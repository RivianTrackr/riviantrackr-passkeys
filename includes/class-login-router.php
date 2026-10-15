<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends wp-login.php's login and registration screens to the owner's own
 * pages, and routes password failures back to the sign-in page instead of
 * showing the stock form. Every other wp-login.php action (password reset,
 * logout, post passwords, confirmaction, the passkey landing pages) is left
 * alone, as are POST requests, interim logins and reauth requests.
 */
final class LoginRouter {

	/** Actions on wp-login.php that mean "show me the login form". */
	public const LOGIN_ACTIONS = [ '', 'login' ];

	/** Query args carried over from wp-login.php to the custom page. */
	public const PASSTHROUGH = [ 'redirect_to', 'login', 'loggedout', 'rtpk_invite', 'rtpk_signup', 'checkemail' ];

	public function __construct( private Settings $settings ) {}

	public function register_hooks(): void {
		if ( ! $this->settings->wants_wp_login_redirect() ) {
			return;
		}
		add_filter( 'login_url', [ $this, 'filter_login_url' ], 10, 3 );
		add_filter( 'register_url', [ $this, 'filter_register_url' ] );
		add_action( 'login_init', [ $this, 'maybe_redirect' ] );
		add_action( 'wp_login_failed', [ $this, 'on_login_failed' ], 10, 2 );
		add_filter( 'authenticate', [ $this, 'on_empty_credentials' ], 25, 3 );
	}

	// ---- decision --------------------------------------------------------

	/**
	 * Pure routing decision for a wp-login.php request.
	 *
	 * @param string               $method        HTTP method.
	 * @param string               $action        wp-login.php action ('' or 'login' is the login form).
	 * @param array<string,string> $query         Query-string arguments.
	 * @param bool                 $logged_in     Whether a user is signed in.
	 * @param string               $login_page    URL of the custom sign-in page, or ''.
	 * @param string               $register_page URL of the custom join page, or ''.
	 * @return string|null Target URL, or null to let wp-login.php handle the request.
	 */
	public static function resolve( string $method, string $action, array $query, bool $logged_in, string $login_page, string $register_page ): ?string {
		if ( 'GET' !== strtoupper( $method ) ) {
			return null;
		}
		// Interim (modal re-login) and reauth requests must reach the stock form.
		if ( isset( $query['interim-login'] ) || ! empty( $query['reauth'] ) ) {
			return null;
		}
		// wp-login.php already bounces signed-in visitors to redirect_to or the dashboard.
		if ( $logged_in ) {
			return null;
		}

		$action = strtolower( trim( $action ) );
		if ( in_array( $action, self::LOGIN_ACTIONS, true ) ) {
			$target = $login_page;
		} elseif ( 'register' === $action ) {
			$target = $register_page;
		} else {
			return null;
		}
		if ( '' === $target ) {
			return null;
		}
		return self::with_passthrough( $target, $query );
	}

	/**
	 * Append the whitelisted wp-login.php query args to a page URL.
	 *
	 * @param string              $url   Page URL.
	 * @param array<string,mixed> $query Query-string arguments.
	 */
	public static function with_passthrough( string $url, array $query ): string {
		$args = [];
		foreach ( self::PASSTHROUGH as $key ) {
			if ( isset( $query[ $key ] ) && is_string( $query[ $key ] ) && '' !== $query[ $key ] ) {
				$args[ $key ] = rawurlencode( $query[ $key ] );
			}
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Sign-in page URL with an optional redirect_to.
	 */
	public static function login_page_link( string $page, string $redirect = '' ): string {
		return '' !== $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : $page;
	}

	/**
	 * Sign-in page URL carrying a failure code (?login=failed|empty).
	 */
	public static function failure_url( string $page, string $code, string $redirect_to = '' ): string {
		$args = [ 'login' => $code ];
		if ( '' !== $redirect_to ) {
			$args['redirect_to'] = rawurlencode( $redirect_to );
		}
		return add_query_arg( $args, $page );
	}

	// ---- hooks -----------------------------------------------------------

	/**
	 * Runs on login_init, inside wp-login.php before any output.
	 */
	public function maybe_redirect(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing of a GET request.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		$query  = [];
		foreach ( $_GET as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$key           = sanitize_key( (string) $key );
			$query[ $key ] = 'redirect_to' === $key
				? esc_url_raw( wp_unslash( $value ) )
				: sanitize_text_field( wp_unslash( $value ) );
		}
		// phpcs:enable

		$target = self::resolve(
			$method,
			$action,
			$query,
			is_user_logged_in(),
			$this->settings->login_page_url(),
			$this->settings->register_page_url()
		);
		if ( null === $target ) {
			return;
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Filters login_url so wp_login_url() points at the sign-in page, keeping
	 * redirect_to. Forced re-authentication still goes to wp-login.php.
	 *
	 * @param string $login_url    Default URL.
	 * @param string $redirect     Where to go after signing in.
	 * @param bool   $force_reauth Whether to force re-authentication.
	 */
	public function filter_login_url( $login_url, $redirect = '', $force_reauth = false ): string {
		$page = $this->settings->login_page_url();
		if ( '' === $page || $force_reauth ) {
			return (string) $login_url;
		}
		return self::login_page_link( $page, (string) $redirect );
	}

	/**
	 * Filters register_url so wp_registration_url() points at the join page.
	 *
	 * @param string $url Default URL.
	 */
	public function filter_register_url( $url ): string {
		$page = $this->settings->register_page_url();
		return '' !== $page ? $page : (string) $url;
	}

	/**
	 * Handles wp_login_failed: a wrong username/password on wp-login.php.
	 *
	 * @param string         $username Submitted username.
	 * @param \WP_Error|null $error    Why it failed.
	 */
	public function on_login_failed( $username, $error = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		$this->bounce( 'failed' );
	}

	/**
	 * Filters authenticate at priority 25 (after core's username/email
	 * checks): an empty username or password never fires wp_login_failed,
	 * so it is caught here.
	 *
	 * @param \WP_User|\WP_Error|null $user     Result so far.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function on_empty_credentials( $user, $username = '', $password = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		if ( is_wp_error( $user ) && in_array( $user->get_error_code(), [ 'empty_username', 'empty_password' ], true ) ) {
			$this->bounce( 'empty' );
		}
		return $user;
	}

	private function bounce( string $code ): void {
		if ( ! self::is_login_post() ) {
			return;
		}
		$page = $this->settings->login_page_url();
		if ( '' === $page ) {
			return;
		}
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only used to rebuild the sign-in link.
		wp_safe_redirect( self::failure_url( $page, $code, $redirect_to ) );
		exit;
	}

	/**
	 * Is this a password form submission to wp-login.php's login action?
	 * Other callers of wp_authenticate() (XML-RPC, REST, custom forms that
	 * post elsewhere) are never redirected.
	 */
	private static function is_login_post(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Inspection only; wp-login.php owns the form.
		if ( 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return false;
		}
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return false;
		}
		if ( ( ! isset( $_POST['log'] ) && ! isset( $_POST['pwd'] ) ) || isset( $_REQUEST['interim-login'] ) ) {
			return false;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		// phpcs:enable
		return in_array( $action, self::LOGIN_ACTIONS, true );
	}
}
