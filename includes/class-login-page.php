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

		return $this->form_markup( $redirect, self::notices( $query, $requested ), $light );
	}

	/**
	 * Which hints to show for the current query string. Pure, so it can be tested.
	 *
	 * @param array<string,string> $query       login / loggedout / rtpk_invite / rtpk_signup / checkemail.
	 * @param string               $redirect_to Validated redirect_to value.
	 * @return array<int,array{type:string,text:string}>
	 */
	public static function notices( array $query, string $redirect_to = '' ): array {
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
		} elseif ( SetupPage::leads_to_setup( $redirect_to ) ) {
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
	 * @param string                                    $redirect Where to go after signing in.
	 * @param array<int,array{type:string,text:string}> $notices  Hints to show above the form.
	 * @param bool                                      $light    Light theme.
	 */
	private function form_markup( string $redirect, array $notices, bool $light ): string {
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
			$join_url = $this->settings->register_page_url();
		} elseif ( get_option( 'users_can_register' ) ) {
			$join_url = wp_registration_url();
		}

		ob_start();
		?>
		<div class="rtpk-login-page<?php echo $light ? ' is-light' : ''; ?>" data-rtpk-login-page>
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
