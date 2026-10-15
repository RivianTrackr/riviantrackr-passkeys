<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "Sign in with a passkey" button to wp-login.php, to forms built
 * with wp_login_form(), and via the [riviantrackr_passkey_login] shortcode.
 */
final class LoginForm {

	private bool $assets_registered = false;

	public function __construct( private Settings $settings ) {}

	public function register_hooks(): void {
		add_shortcode( 'riviantrackr_passkey_login', [ $this, 'shortcode' ] );

		if ( ! $this->settings->get( 'login_button' ) ) {
			return;
		}

		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_page' ] );
		add_action( 'login_form', [ $this, 'render_login_page_button' ] );
		add_filter( 'login_form_middle', [ $this, 'filter_wp_login_form' ], 10, 2 );
	}

	public function register_assets(): void {
		if ( $this->assets_registered ) {
			return;
		}
		$this->assets_registered = true;
		$suffix                  = RTPK_ASSET_SUFFIX;

		wp_register_script( 'rtpk-core', RTPK_URL . "assets/passkeys-core{$suffix}.js", [], Helpers::asset_version( "assets/passkeys-core{$suffix}.js" ), true );
		wp_register_script( 'rtpk-login', RTPK_URL . "assets/passkeys-login{$suffix}.js", [ 'rtpk-core' ], Helpers::asset_version( "assets/passkeys-login{$suffix}.js" ), true );
		wp_register_style( 'rtpk-login', RTPK_URL . "assets/passkeys-login{$suffix}.css", [], Helpers::asset_version( "assets/passkeys-login{$suffix}.css" ) );
	}

	public function enqueue( string $redirect_to = '' ): void {
		$this->register_assets();

		if ( ! wp_script_is( 'rtpk-login', 'enqueued' ) ) {
			wp_localize_script( 'rtpk-login', 'rtpkLogin', $this->config( $redirect_to ) );
		}

		wp_enqueue_style( 'rtpk-login' );
		wp_enqueue_script( 'rtpk-login' );
	}

	public function enqueue_login_page(): void {
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->enqueue( $redirect_to );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function config( string $redirect_to ): array {
		return [
			'restUrl'       => esc_url_raw( rest_url( RTPK_REST_NAMESPACE ) ),
			'redirectTo'    => $redirect_to,
			'conditionalUi' => (bool) $this->settings->get( 'conditional_ui' ),
			'i18n'          => [
				'unsupported'   => __( 'Passkeys are not supported in this browser.', 'riviantrackr-passkeys' ),
				'working'       => __( 'Waiting for your device…', 'riviantrackr-passkeys' ),
				'signingIn'     => __( 'Signing you in…', 'riviantrackr-passkeys' ),
				'cancelled'     => __( 'Sign-in was cancelled or timed out.', 'riviantrackr-passkeys' ),
				'security'      => __( 'Passkeys need a secure (HTTPS) connection.', 'riviantrackr-passkeys' ),
				'genericError'  => __( 'Something went wrong. Please try again.', 'riviantrackr-passkeys' ),
				'noCredentials' => __( 'No passkey was found for this site on your device.', 'riviantrackr-passkeys' ),
			],
		];
	}

	/**
	 * Button markup: passkey button first, then an "or" divider leading into
	 * the password fields below. Hidden until JS confirms WebAuthn support.
	 */
	public function button_markup( string $redirect_to = '', bool $dark = false, bool $divider = true ): string {
		$classes = 'rtpk-login' . ( $dark ? ' is-dark' : '' );

		$html  = '<div class="' . esc_attr( $classes ) . '" data-rtpk-login data-redirect="' . esc_attr( $redirect_to ) . '" hidden>';
		$html .= '<button type="button" class="rtpk-login-btn" data-rtpk-login-btn>';
		$html .= self::icon();
		$html .= '<span class="rtpk-login-btn__label">' . esc_html__( 'Sign in with a passkey', 'riviantrackr-passkeys' ) . '</span>';
		$html .= '</button>';
		$html .= '<p class="rtpk-login-msg" data-rtpk-login-msg role="status" aria-live="polite" hidden></p>';
		if ( $divider ) {
			$html .= '<div class="rtpk-divider" aria-hidden="true"><span>' . esc_html__( 'or', 'riviantrackr-passkeys' ) . '</span></div>';
		}
		$html .= '</div>';

		return $html;
	}

	public function render_login_page_button(): void {
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo $this->button_markup( $redirect_to, false, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button_markup().
	}

	/**
	 * @param string              $content Existing middle content.
	 * @param array<string,mixed> $args    wp_login_form() arguments.
	 */
	public function filter_wp_login_form( $content, $args = [] ): string {
		$redirect_to = isset( $args['redirect'] ) ? esc_url_raw( (string) $args['redirect'] ) : '';
		$this->enqueue( $redirect_to );
		return (string) $content . $this->button_markup( $redirect_to, false, true );
	}

	/**
	 * [riviantrackr_passkey_login redirect="" theme="dark|light" divider="0|1"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		if ( is_user_logged_in() ) {
			return '';
		}
		$atts = shortcode_atts(
			[
				'redirect' => '',
				'theme'    => 'dark',
				'divider'  => '0',
			],
			is_array( $atts ) ? $atts : [],
			'riviantrackr_passkey_login'
		);

		$redirect_to = '' !== $atts['redirect'] ? esc_url_raw( (string) $atts['redirect'] ) : '';
		$this->enqueue( $redirect_to );

		return $this->button_markup( $redirect_to, 'light' !== $atts['theme'], '1' === (string) $atts['divider'] );
	}

	/**
	 * Inline passkey glyph (fingerprint-in-key). Decorative.
	 */
	public static function icon(): string {
		return '<svg class="rtpk-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
			. '<circle cx="9" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/>'
			. '<path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5 1.5 0 2.9.5 4 1.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
			. '<circle cx="17.5" cy="13.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>'
			. '<path d="M17.5 16v5.5l1.8-1.5-1.8-1.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}
}
