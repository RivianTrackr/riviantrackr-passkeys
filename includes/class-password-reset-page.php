<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [riviantrackr_password_reset]: the password reset flow on the owner's own
 * page, in the sign-in page's card.
 *
 * Two steps, both plain HTML forms that post back to the page (no JavaScript
 * needed): "Reset your password" asks for a username or email and sends
 * WordPress's standard reset email (retrieve_password()); the emailed link
 * lands on wp-login.php?action=rp, which LoginRouter sends here with the key.
 * Like wp-login.php, the key is moved from the URL into a cookie before the
 * page renders so it never leaks through the referrer, then "Choose a new
 * password" verifies it (check_password_reset_key()) and saves the password
 * (reset_password()).
 *
 * Every outcome is a redirect back to the page with ?rtpk_reset=<code>, so
 * refreshing never resubmits a form. An address that has no account gets the
 * same "check your inbox" screen as one that does, like open signup: nothing
 * on this page reveals who is registered.
 */
final class PasswordResetPage {

	public const SHORTCODE = 'riviantrackr_password_reset';

	/** Query arg carrying the outcome of the last step. */
	public const QUERY = 'rtpk_reset';

	/** Codes that are a screen of their own rather than a notice. */
	public const STATE_CODES = [ 'sent', 'done' ];

	private const NONCE_REQUEST = 'rtpk_reset_request';
	private const NONCE_RESET   = 'rtpk_reset_password';
	private const FIELD_ACTION  = 'rtpk_reset_action';

	private bool $assets_registered = false;

	public function __construct(
		private Settings $settings,
		private RateLimiter $limiter
	) {}

	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
		add_action( 'template_redirect', [ $this, 'handle' ] );
		add_action( 'lostpassword_post', [ $this, 'guard_lostpassword' ], 5, 2 );
	}

	/**
	 * Runs inside retrieve_password() for every reset request, whether it
	 * came from this page, wp-login.php or another plugin, so the per-IP
	 * limit cannot be sidestepped by posting to wp-login.php directly.
	 *
	 * @param \WP_Error           $errors    Errors so far; adding one aborts the email.
	 * @param \WP_User|false|null $user_data The matched user, if any.
	 */
	public function guard_lostpassword( $errors, $user_data = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}
		if ( $this->limiter->is_limited( 'password-reset', Helpers::client_ip(), RTPK_RESET_RATE_LIMIT, HOUR_IN_SECONDS ) ) {
			$errors->add( 'rtpk_rate_limited', __( 'Too many password reset requests from this connection. Please try again in an hour.', 'riviantrackr-passkeys' ) );
		}
	}

	public function enqueue(): void {
		if ( ! $this->assets_registered ) {
			$this->assets_registered = true;
			$file                    = 'assets/passkeys-reset-page' . RTPK_ASSET_SUFFIX . '.css';
			wp_register_style( 'rtpk-reset-page', RTPK_URL . $file, [], Helpers::asset_version( $file ) );
		}
		wp_enqueue_style( 'rtpk-reset-page' );
	}

	// ---- pure pieces (tested) --------------------------------------------

	/**
	 * Which screen to show.
	 *
	 * @param string $code      ?rtpk_reset value.
	 * @param bool   $has_key   A valid reset key is in the cookie.
	 * @param bool   $signed_in A user is signed in.
	 * @return string sent | done | reset | signed-in | request
	 */
	public static function state( string $code, bool $has_key, bool $signed_in ): string {
		if ( 'sent' === $code ) {
			return 'sent';
		}
		if ( 'done' === $code ) {
			return 'done';
		}
		if ( $has_key ) {
			return 'reset';
		}
		return $signed_in ? 'signed-in' : 'request';
	}

	/**
	 * Notice for a ?rtpk_reset code, or null when the code is a screen or unknown.
	 *
	 * @return array{type:string,text:string}|null
	 */
	public static function notice( string $code ): ?array {
		$errors = [
			'empty'          => __( 'Enter your username or email address.', 'riviantrackr-passkeys' ),
			'not_allowed'    => __( 'Password reset is not allowed for this account.', 'riviantrackr-passkeys' ),
			'mail'           => __( 'The reset email could not be sent. Please try again later or contact us.', 'riviantrackr-passkeys' ),
			'limited'        => __( 'Too many reset requests from this connection. Please try again in an hour.', 'riviantrackr-passkeys' ),
			'retry'          => __( 'The form timed out. Please try again.', 'riviantrackr-passkeys' ),
			'failed'         => __( 'Something went wrong. Please try again.', 'riviantrackr-passkeys' ),
			'expired'        => __( 'This reset link has expired or has already been used. Request a new one below.', 'riviantrackr-passkeys' ),
			'empty_password' => __( 'Enter a new password.', 'riviantrackr-passkeys' ),
			'mismatch'       => __( 'The passwords do not match.', 'riviantrackr-passkeys' ),
			'short'          => sprintf(
				/* translators: %d: minimum length */
				__( 'Use at least %d characters.', 'riviantrackr-passkeys' ),
				AccountService::PASSWORD_MIN
			),
			'backslash'      => __( 'Passwords may not contain the “\” character.', 'riviantrackr-passkeys' ),
		];
		if ( ! isset( $errors[ $code ] ) ) {
			return null;
		}
		return [
			'type' => 'error',
			'text' => $errors[ $code ],
		];
	}

	/**
	 * Map retrieve_password()'s result to a page code. A username or email
	 * that matches nothing (core's invalidcombo, or invalid_email for an
	 * unknown address) is reported as "sent" on purpose.
	 *
	 * @param true|\WP_Error $result What retrieve_password() returned.
	 */
	public static function code_for( $result ): string {
		if ( ! is_wp_error( $result ) ) {
			return 'sent';
		}
		switch ( $result->get_error_code() ) {
			case 'empty_username':
				return 'empty';
			case 'invalid_email':
			case 'invalidcombo':
				return 'sent';
			case 'rtpk_rate_limited':
				return 'limited';
			case 'no_password_reset':
				return 'not_allowed';
			case 'retrieve_password_email_failure':
				return 'mail';
			default:
				return 'failed';
		}
	}

	/**
	 * The same rules as wp-login.php's reset form plus the account page's
	 * minimum length. Returns the error code, or null when the password is fine.
	 */
	public static function validate_new_password( string $pass1, string $pass2 ): ?string {
		if ( '' === $pass1 ) {
			return 'empty_password';
		}
		if ( str_contains( $pass1, '\\' ) ) {
			return 'backslash';
		}
		if ( strlen( $pass1 ) < AccountService::PASSWORD_MIN ) {
			return 'short';
		}
		if ( $pass1 !== $pass2 ) {
			return 'mismatch';
		}
		return null;
	}

	/**
	 * Send the reset email for a username or email address. Returns the
	 * page code. The per-IP limit is applied inside retrieve_password() by
	 * guard_lostpassword().
	 *
	 * @param string $login Username or email as typed.
	 */
	public function request( string $login ): string {
		if ( '' === $login ) {
			return 'empty';
		}
		return self::code_for( retrieve_password( $login ) );
	}

	// ---- request handling --------------------------------------------------

	/**
	 * Runs on template_redirect: handles the two form posts, and moves a
	 * key arriving in the URL (from the emailed link) into the cookie.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- The nonce is checked in the step handlers; the key exchange changes nothing.
		if ( isset( $_POST[ self::FIELD_ACTION ] ) ) {
			$step = sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_ACTION ] ) );
			if ( 'request' === $step ) {
				$this->handle_request();
			} elseif ( 'reset' === $step ) {
				$this->handle_reset();
			}
			return;
		}
		if ( isset( $_GET['key'], $_GET['login'] ) && $this->is_reset_page() ) {
			$this->exchange_key(
				(string) preg_replace( '/[^a-z0-9]/i', '', sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) ),
				sanitize_text_field( wp_unslash( (string) $_GET['login'] ) )
			);
		}
		// phpcs:enable
	}

	private function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified on the next line.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_REQUEST ) ) {
			$this->finish( 'retry' );
		}
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['user_login'] ) ) : '';
		// phpcs:enable
		$this->finish( $this->request( $login ) );
	}

	private function handle_reset(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified on the next line.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_RESET ) ) {
			$this->finish( 'retry' );
		}
		$user = $this->user_for_cookie();
		if ( null === $user ) {
			$this->clear_cookie();
			$this->finish( 'expired' );
		}

		// Passwords are stored as typed; only the slashes PHP added are removed.
		$pass1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( (string) $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is never altered.
		$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( (string) $_POST['pass2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is never altered.
		// phpcs:enable

		$code = self::validate_new_password( $pass1, $pass2 );
		if ( null !== $code ) {
			$this->finish( $code );
		}

		$errors = new \WP_Error();
		/** This action is documented in wp-login.php. */
		do_action( 'validate_password_reset', $errors, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		if ( $errors->has_errors() ) {
			$this->finish( 'failed' );
		}

		reset_password( $user, $pass1 );
		$this->clear_cookie();
		$this->finish( 'done' );
	}

	/**
	 * Redirect back to the page with the outcome code. Never returns.
	 */
	private function finish( string $code ): void {
		$url = add_query_arg( self::QUERY, $code, $this->page_url() );
		$to  = $this->posted_redirect();
		if ( '' !== $to ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $to ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The emailed link carries key and login in the query string. Store them
	 * in a cookie scoped to this page and reload without them, as wp-login.php
	 * does, so the key is not sent to third parties in the referrer.
	 */
	private function exchange_key( string $key, string $login ): void {
		if ( '' !== $key && '' !== $login ) {
			$this->set_cookie( $login . ':' . $key, 0 );
		}
		$url = $this->page_url();
		$to  = $this->posted_redirect();
		if ( '' !== $to ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $to ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	// ---- cookie ----------------------------------------------------------

	/**
	 * Cookie holding "login:key" between the emailed link and the form post.
	 *
	 * The "wp-" prefix matters: full-page caches (Cloudflare APO, WP Rocket
	 * and the like) skip the cache for requests carrying a cookie that starts
	 * with "wp-", as core's own wp-resetpass cookie does. Without it the
	 * redirect from the emailed link lands on the cached "Reset your
	 * password" form instead of "Choose a new password".
	 */
	public static function cookie_name(): string {
		return 'wp-rtpk-resetpass-' . COOKIEHASH;
	}

	/**
	 * The user the cookie's key belongs to, or null when there is no cookie
	 * or the key is invalid, expired or spent.
	 */
	private function user_for_cookie(): ?\WP_User {
		$name = self::cookie_name();
		$raw  = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) ) : '';
		if ( ! str_contains( $raw, ':' ) ) {
			return null;
		}
		[ $login, $key ] = explode( ':', $raw, 2 );
		if ( '' === $login || '' === $key ) {
			return null;
		}
		$user = check_password_reset_key( $key, $login );
		return $user instanceof \WP_User ? $user : null;
	}

	private function set_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::cookie_name(),
			$value,
			[
				'expires'  => $expires,
				'path'     => $this->cookie_path(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	private function clear_cookie(): void {
		$this->set_cookie( ' ', time() - YEAR_IN_SECONDS );
	}

	private function cookie_path(): string {
		$path = (string) wp_parse_url( $this->page_url(), PHP_URL_PATH );
		return '' !== $path ? $path : '/';
	}

	// ---- URLs ----------------------------------------------------------

	/**
	 * Is the current request the reset page: the one chosen in settings, or
	 * any page carrying the shortcode?
	 */
	private function is_reset_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( $id > 0 && $id === (int) $this->settings->get( 'reset_page_id' ) ) {
			return true;
		}
		$post = get_post( $id );
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * Clean permalink of the page being viewed (no outcome code, key or login).
	 */
	private function page_url(): string {
		$url = is_singular() ? get_permalink( get_queried_object_id() ) : '';
		if ( ! is_string( $url ) || '' === $url ) {
			$url = $this->settings->reset_page_url();
		}
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Validated redirect_to from the form post or the query string.
	 */
	private function posted_redirect(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Only used to rebuild the sign-in link.
		$raw = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : '';
		// phpcs:enable
		return '' !== $raw ? wp_validate_redirect( $raw, '' ) : '';
	}

	// ---- shortcode -------------------------------------------------------

	/**
	 * [riviantrackr_password_reset theme="dark|light"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		$atts  = shortcode_atts( [ 'theme' => 'dark' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
		$light = 'light' === $atts['theme'];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display state only; nothing is changed.
		$code = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::QUERY ] ) ) : '';
		// phpcs:enable
		$redirect_to = $this->posted_redirect();

		$user = in_array( $code, self::STATE_CODES, true ) ? null : $this->user_for_cookie();
		if ( null === $user && '' === $code && isset( $_COOKIE[ self::cookie_name() ] ) ) {
			// A cookie that no longer opens anything: say so and offer a new link.
			$code = 'expired';
		}

		$this->enqueue();

		$state  = self::state( $code, null !== $user, is_user_logged_in() );
		$notice = self::notice( $code );

		ob_start();
		?>
		<div class="rtpk-reset-page<?php echo $light ? ' is-light' : ''; ?> is-<?php echo esc_attr( $state ); ?>" data-rtpk-reset-page>
			<?php if ( null !== $notice ) : ?>
				<div class="rtpk-reset-page__notice is-<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>">
					<?php echo esc_html( $notice['text'] ); ?>
				</div>
			<?php endif; ?>
			<?php
			switch ( $state ) {
				case 'sent':
					$this->sent_markup( $redirect_to );
					break;
				case 'done':
					$this->done_markup( $redirect_to );
					break;
				case 'reset':
					$this->reset_form_markup( $user, $redirect_to );
					break;
				case 'signed-in':
					$this->signed_in_markup( wp_get_current_user() );
					break;
				default:
					$this->request_form_markup( $redirect_to );
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function request_form_markup( string $redirect_to ): void {
		?>
		<form class="rtpk-reset-page__form" method="post" action="<?php echo esc_url( $this->page_url() ); ?>">
			<h3 class="rtpk-reset-page__title"><?php esc_html_e( 'Reset your password', 'riviantrackr-passkeys' ); ?></h3>
			<p class="rtpk-reset-page__desc"><?php esc_html_e( 'Enter your username or email address and we’ll send you a link to choose a new password.', 'riviantrackr-passkeys' ); ?></p>

			<div class="rtpk-reset-page__field">
				<label class="rtpk-reset-page__label" for="rtpk-reset-login"><?php esc_html_e( 'Username or email', 'riviantrackr-passkeys' ); ?></label>
				<input type="text" id="rtpk-reset-login" class="rtpk-reset-page__input" name="user_login" autocomplete="username" autocapitalize="off" spellcheck="false" required>
			</div>

			<?php $this->hidden_fields( 'request', self::NONCE_REQUEST, $redirect_to ); ?>

			<button type="submit" class="rtpk-btn rtpk-btn--primary"><?php esc_html_e( 'Send reset link', 'riviantrackr-passkeys' ); ?></button>

			<p class="rtpk-reset-page__links">
				<a href="<?php echo esc_url( wp_login_url( $redirect_to ) ); ?>"><?php esc_html_e( 'Back to sign in', 'riviantrackr-passkeys' ); ?></a>
			</p>
		</form>
		<?php
	}

	private function reset_form_markup( \WP_User $user, string $redirect_to ): void {
		?>
		<form class="rtpk-reset-page__form" method="post" action="<?php echo esc_url( $this->page_url() ); ?>">
			<h3 class="rtpk-reset-page__title"><?php esc_html_e( 'Choose a new password', 'riviantrackr-passkeys' ); ?></h3>
			<p class="rtpk-reset-page__desc">
				<?php
				printf(
					/* translators: %s: username */
					esc_html__( 'Pick a new password for %s. Use something you haven’t used anywhere else.', 'riviantrackr-passkeys' ),
					'<strong>' . esc_html( $user->user_login ) . '</strong>'
				);
				?>
			</p>

			<div class="rtpk-reset-page__field">
				<label class="rtpk-reset-page__label" for="rtpk-reset-pass1"><?php esc_html_e( 'New password', 'riviantrackr-passkeys' ); ?></label>
				<input type="password" id="rtpk-reset-pass1" class="rtpk-reset-page__input" name="pass1" autocomplete="new-password" minlength="<?php echo (int) AccountService::PASSWORD_MIN; ?>" required>
				<p class="rtpk-reset-page__help">
					<?php
					printf(
						/* translators: %d: minimum length */
						esc_html__( 'At least %d characters.', 'riviantrackr-passkeys' ),
						(int) AccountService::PASSWORD_MIN
					);
					?>
				</p>
			</div>
			<div class="rtpk-reset-page__field">
				<label class="rtpk-reset-page__label" for="rtpk-reset-pass2"><?php esc_html_e( 'Repeat new password', 'riviantrackr-passkeys' ); ?></label>
				<input type="password" id="rtpk-reset-pass2" class="rtpk-reset-page__input" name="pass2" autocomplete="new-password" required>
			</div>

			<?php $this->hidden_fields( 'reset', self::NONCE_RESET, $redirect_to ); ?>

			<button type="submit" class="rtpk-btn rtpk-btn--primary"><?php esc_html_e( 'Save new password', 'riviantrackr-passkeys' ); ?></button>
		</form>
		<?php
	}

	private function sent_markup( string $redirect_to ): void {
		?>
		<div class="rtpk-reset-page__state">
			<div class="rtpk-reset-page__state-icon" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M3 7.5v9A2.5 2.5 0 0 0 5.5 19h13a2.5 2.5 0 0 0 2.5-2.5v-9M3 7.5A2.5 2.5 0 0 1 5.5 5h13A2.5 2.5 0 0 1 21 7.5M3 7.5l9 6 9-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</div>
			<h3 class="rtpk-reset-page__title"><?php esc_html_e( 'Check your inbox', 'riviantrackr-passkeys' ); ?></h3>
			<p class="rtpk-reset-page__desc"><?php esc_html_e( 'If an account matches what you entered, a link to choose a new password is on its way. The link works once and expires after a day.', 'riviantrackr-passkeys' ); ?></p>
			<p class="rtpk-reset-page__fine">
				<?php esc_html_e( 'Nothing after a few minutes? Look in spam, or', 'riviantrackr-passkeys' ); ?>
				<a href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'try again', 'riviantrackr-passkeys' ); ?></a>.
			</p>
			<p class="rtpk-reset-page__links">
				<a href="<?php echo esc_url( wp_login_url( $redirect_to ) ); ?>"><?php esc_html_e( 'Back to sign in', 'riviantrackr-passkeys' ); ?></a>
			</p>
		</div>
		<?php
	}

	private function done_markup( string $redirect_to ): void {
		?>
		<div class="rtpk-reset-page__state">
			<div class="rtpk-reset-page__state-icon is-success" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</div>
			<h3 class="rtpk-reset-page__title"><?php esc_html_e( 'Password updated', 'riviantrackr-passkeys' ); ?></h3>
			<p class="rtpk-reset-page__desc"><?php esc_html_e( 'Your new password is saved. Sign in with it, then add a passkey from your account page so you can skip the password next time.', 'riviantrackr-passkeys' ); ?></p>
			<a class="rtpk-btn rtpk-btn--primary" href="<?php echo esc_url( wp_login_url( $redirect_to ) ); ?>"><?php esc_html_e( 'Sign in', 'riviantrackr-passkeys' ); ?></a>
		</div>
		<?php
	}

	private function signed_in_markup( \WP_User $user ): void {
		$name    = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$account = $this->settings->account_page_url();
		$change  = '' !== $account ? $account : admin_url( 'profile.php' );
		?>
		<div class="rtpk-reset-page__state">
			<p class="rtpk-reset-page__signed-in-text">
				<?php
				printf(
					/* translators: %s: display name */
					esc_html__( 'You’re signed in as %s.', 'riviantrackr-passkeys' ),
					'<strong>' . esc_html( $name ) . '</strong>'
				);
				?>
			</p>
			<p class="rtpk-reset-page__desc"><?php esc_html_e( 'You can change your password from your account page without a reset link.', 'riviantrackr-passkeys' ); ?></p>
			<a class="rtpk-btn rtpk-btn--primary" href="<?php echo esc_url( $change ); ?>"><?php esc_html_e( 'Go to my account', 'riviantrackr-passkeys' ); ?></a>
			<p class="rtpk-reset-page__links">
				<a href="<?php echo esc_url( wp_logout_url( $this->page_url() ) ); ?>"><?php esc_html_e( 'Not you? Sign out', 'riviantrackr-passkeys' ); ?></a>
			</p>
		</div>
		<?php
	}

	private function hidden_fields( string $step, string $nonce_action, string $redirect_to ): void {
		?>
		<input type="hidden" name="<?php echo esc_attr( self::FIELD_ACTION ); ?>" value="<?php echo esc_attr( $step ); ?>">
		<?php wp_nonce_field( $nonce_action, '_wpnonce', false ); ?>
		<?php if ( '' !== $redirect_to ) : ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
		<?php endif; ?>
		<?php
	}
}
