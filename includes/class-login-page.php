<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [riviantrackr_login]: a branded sign-in form for the owner's own page.
 *
 * Renders wp_login_form() inside the plugin's markup. The passkey button is
 * injected by LoginForm through the login_form_middle filter and moved to
 * the top of the form by passkeys-login.js, so the passwordless path leads.
 */
final class LoginPage {

	public const SHORTCODE = 'riviantrackr_login';

	private bool $assets_registered = false;

	public function __construct(
		private Settings $settings,
		private SignupService $signup
	) {}

	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
		add_filter( 'login_form_middle', [ $this, 'rsssl_nonce_field' ], 20 );
		add_filter( 'cloudflare_use_cache', [ $this, 'cloudflare_use_cache' ] );
	}

	/**
	 * Really Simple Security rejects a wp-login.php POST that lacks its
	 * nonce, and only adds that nonce to wp-login.php's own form (the
	 * login_form action). wp_login_form() fires login_form_middle instead,
	 * so the field is added here.
	 *
	 * @param string $html Markup other filters added.
	 */
	public function rsssl_nonce_field( $html ): string {
		if ( ! class_exists( 'RSSSL\\Pro\\Security\\WordPress\\Rsssl_Event_Listener' ) ) {
			return (string) $html;
		}
		return (string) $html . wp_nonce_field( 'rsssl_login_action', 'rsssl_login_nonce', false, false );
	}

	/**
	 * Keep the sign-in page out of Cloudflare APO. A cached copy carries a
	 * nonce that expires within a day, so sign-in fails once it goes stale.
	 *
	 * @param bool $use Whether APO may cache this response.
	 */
	public function cloudflare_use_cache( $use ): bool {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared only.
		return (bool) $use && ! self::is_page_path( $path, $this->settings->login_page_url() );
	}

	/**
	 * Whether a request path is the given page's path. Never matches the
	 * site root, so a misconfigured page can't take the homepage out of
	 * the cache. Pure.
	 */
	public static function is_page_path( string $path, string $page_url ): bool {
		$page_path = trim( (string) wp_parse_url( $page_url, PHP_URL_PATH ), '/' );
		return '' !== $page_path && trim( $path, '/' ) === $page_path;
	}

	public function enqueue(): void {
		if ( ! $this->assets_registered ) {
			$this->assets_registered = true;
			$file                    = 'assets/passkeys-login-page' . RTPK_ASSET_SUFFIX . '.css';
			wp_register_style( 'rtpk-login-page', RTPK_URL . $file, [], Helpers::asset_version( $file ) );
		}
		wp_enqueue_style( 'rtpk-login-page' );
	}

	/**
	 * [riviantrackr_login theme="dark|light" redirect=""]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		$atts  = shortcode_atts(
			[
				'theme'    => 'dark',
				'redirect' => '',
			],
			is_array( $atts ) ? $atts : [],
			self::SHORTCODE
		);
		$light = 'light' === $atts['theme'];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display hints only; nothing is changed.
		$query = [];
		foreach ( [ 'login', 'loggedout', 'rtpk_invite', 'rtpk_signup', 'checkemail' ] as $key ) {
			$query[ $key ] = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ) : '';
		}
		$requested = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ), '' ) : '';
		// phpcs:enable

		if ( '' !== $requested ) {
			$redirect = $requested;
		} elseif ( '' !== $atts['redirect'] ) {
			$redirect = esc_url_raw( (string) $atts['redirect'] );
		} else {
			$redirect = home_url( '/' );
		}

		$this->enqueue();

		if ( is_user_logged_in() ) {
			return $this->signed_in_markup( wp_get_current_user(), $redirect, $light );
		}

		return $this->form_markup( $redirect, self::notices( $query, $requested, $this->settings->setup_page_url() ), $light, $requested );
	}

	/**
	 * Which hints to show for the current query string. Pure, so it can be tested.
	 *
	 * @param array<string,string> $query       login / loggedout / rtpk_invite / rtpk_signup / checkemail.
	 * @param string               $redirect_to Validated redirect_to value.
	 * @param string               $setup_page  URL of the passkey setup page, or ''.
	 * @return array<int,array{type:string,text:string}>
	 */
	public static function notices( array $query, string $redirect_to = '', string $setup_page = '' ): array {
		$out = [];

		switch ( $query['login'] ?? '' ) {
			case 'failed':
				$out[] = [
					'type' => 'error',
					'text' => __( 'The username or password you entered is incorrect. Try again, or use “Lost your password?” below.', 'riviantrackr-passkeys' ),
				];
				break;
			case 'empty':
				$out[] = [
					'type' => 'error',
					'text' => __( 'Please enter your username and password.', 'riviantrackr-passkeys' ),
				];
				break;
		}

		if ( 'true' === ( $query['loggedout'] ?? '' ) ) {
			$out[] = [
				'type' => 'success',
				'text' => __( 'You are now signed out.', 'riviantrackr-passkeys' ),
			];
		}

		switch ( $query['checkemail'] ?? '' ) {
			case 'confirm':
				$out[] = [
					'type' => 'info',
					'text' => __( 'Check your email for a link to reset your password. Once you have set a new one, sign in below.', 'riviantrackr-passkeys' ),
				];
				break;
			case 'registered':
				$out[] = [
					'type' => 'info',
					'text' => __( 'Registration complete. Please check your email.', 'riviantrackr-passkeys' ),
				];
				break;
		}

		if ( 'expired' === ( $query['rtpk_invite'] ?? '' ) ) {
			$out[] = [
				'type' => 'info',
				'text' => SetupPage::expired_hint(),
			];
		} elseif ( SetupPage::leads_to_setup( $redirect_to, $setup_page ) ) {
			$out[] = [
				'type' => 'info',
				'text' => SetupPage::setup_hint(),
			];
		}

		if ( 'exists' === ( $query['rtpk_signup'] ?? '' ) ) {
			$out[] = [
				'type' => 'info',
				'text' => SignupPage::exists_hint(),
			];
		}

		return $out;
	}

	/**
	 * The join page link under the form. A visitor sent here from somewhere
	 * (a post's comment form, say) who creates an account instead is brought
	 * back there too, unless that somewhere is passkey setup, which signup
	 * leads to anyway. Pure.
	 *
	 * @param string $join_page  Join page URL.
	 * @param string $requested  Validated ?redirect_to, or ''.
	 * @param string $setup_page Passkey setup page URL, or ''.
	 */
	public static function join_link( string $join_page, string $requested, string $setup_page = '' ): string {
		if ( '' === $requested || SetupPage::leads_to_setup( $requested, $setup_page ) ) {
			return $join_page;
		}
		return add_query_arg( 'redirect_to', rawurlencode( $requested ), $join_page );
	}

	/**
	 * @param string                                    $redirect  Where to go after signing in.
	 * @param array<int,array{type:string,text:string}> $notices   Hints to show above the form.
	 * @param bool                                      $light     Light theme.
	 * @param string                                    $requested Validated ?redirect_to, or ''.
	 */
	private function form_markup( string $redirect, array $notices, bool $light, string $requested = '' ): string {
		$form = wp_login_form(
			[
				'echo'           => false,
				'redirect'       => $redirect,
				'form_id'        => 'rtpk-loginform',
				'label_username' => __( 'Username or email', 'riviantrackr-passkeys' ),
				'label_password' => __( 'Password', 'riviantrackr-passkeys' ),
				'label_remember' => __( 'Keep me signed in', 'riviantrackr-passkeys' ),
				'label_log_in'   => __( 'Sign in', 'riviantrackr-passkeys' ),
				'id_username'    => 'rtpk-user-login',
				'id_password'    => 'rtpk-user-pass',
				'id_remember'    => 'rtpk-rememberme',
				'id_submit'      => 'rtpk-wp-submit',
				'remember'       => true,
				'value_remember' => false,
			]
		);

		$join_url = '';
		if ( $this->signup->enabled() && '' !== $this->settings->register_page_url() ) {
			$join_url = self::join_link( $this->settings->register_page_url(), $requested, $this->settings->setup_page_url() );
		} elseif ( get_option( 'users_can_register' ) ) {
			$join_url = wp_registration_url();
		}

		ob_start();
		?>
		<div class="rtpk-login-page<?php echo $light ? ' is-light' : ''; ?>" data-rtpk-login-page>
			<h1 class="rtpk-sr-only"><?php esc_html_e( 'Sign in', 'riviantrackr-passkeys' ); ?></h1>
			<?php foreach ( $notices as $notice ) : ?>
				<div class="rtpk-login-page__notice is-<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>">
					<?php echo esc_html( $notice['text'] ); ?>
				</div>
			<?php endforeach; ?>

			<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_login_form() escapes its output. ?>

			<p class="rtpk-login-page__links">
				<a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>"><?php esc_html_e( 'Lost your password?', 'riviantrackr-passkeys' ); ?></a>
				<?php if ( '' !== $join_url ) : ?>
					<span class="rtpk-login-page__sep" aria-hidden="true">·</span>
					<a href="<?php echo esc_url( $join_url ); ?>"><?php esc_html_e( 'Create an account', 'riviantrackr-passkeys' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function signed_in_markup( \WP_User $user, string $redirect, bool $light ): string {
		$name = '' !== $user->display_name ? $user->display_name : $user->user_login;

		ob_start();
		?>
		<div class="rtpk-login-page<?php echo $light ? ' is-light' : ''; ?> is-signed-in" data-rtpk-login-page>
			<h1 class="rtpk-sr-only"><?php esc_html_e( 'Sign in', 'riviantrackr-passkeys' ); ?></h1>
			<div class="rtpk-login-page__signed-in">
				<p class="rtpk-login-page__signed-in-text">
					<?php
					printf(
						/* translators: %s: display name */
						esc_html__( 'You’re signed in as %s.', 'riviantrackr-passkeys' ),
						'<strong>' . esc_html( $name ) . '</strong>'
					);
					?>
				</p>
				<a class="rtpk-btn rtpk-btn--primary rtpk-login-page__continue" href="<?php echo esc_url( $redirect ); ?>"><?php esc_html_e( 'Continue', 'riviantrackr-passkeys' ); ?></a>
				<p class="rtpk-login-page__links">
					<a href="<?php echo esc_url( wp_logout_url( $redirect ) ); ?>"><?php esc_html_e( 'Not you? Sign out', 'riviantrackr-passkeys' ); ?></a>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
