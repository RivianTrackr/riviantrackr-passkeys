<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ink & brass skin for whatever still renders on wp-login.php (password
 * reset, logout confirmation, post passwords, the passkey setup and signup
 * landing pages): dark palette, gold primary, the site's logo in place of
 * the WordPress one.
 */
final class LoginBranding {

	public const BODY_CLASS = 'rtpk-branded';

	public function __construct(
		private Settings $settings,
		private InviteService $invites
	) {}

	public function register_hooks(): void {
		if ( ! $this->settings->brands_wp_login() ) {
			return;
		}
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'login_headerurl', [ $this, 'header_url' ] );
		add_filter( 'login_headertext', [ $this, 'header_text' ] );
		add_filter( 'login_body_class', [ $this, 'body_class' ] );
	}

	public function enqueue(): void {
		$file = 'assets/passkeys-wp-login' . RTPK_ASSET_SUFFIX . '.css';
		wp_enqueue_style( 'rtpk-wp-login', RTPK_URL . $file, [ 'login' ], Helpers::asset_version( $file ) );
		wp_add_inline_style( 'rtpk-wp-login', self::logo_css( $this->invites->logo_url() ) );
	}

	/**
	 * Logo rule: the chosen image (invitations logo → theme custom logo →
	 * site icon), else the site name as text.
	 */
	public static function logo_css( string $logo_url ): string {
		if ( '' === $logo_url ) {
			return 'body.' . self::BODY_CLASS . ' #login h1 a{background-image:none;width:auto;height:auto;text-indent:0;font-size:26px;font-weight:700;letter-spacing:-0.4px;line-height:1.2;color:#f6f4f0;}';
		}
		return 'body.' . self::BODY_CLASS . ' #login h1 a{background-image:url(' . esc_url( $logo_url ) . ');background-size:contain;width:100%;max-width:240px;height:64px;}';
	}

	/**
	 * @param string $url Default header link (wordpress.org).
	 */
	public function header_url( $url ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter signature.
		return home_url( '/' );
	}

	/**
	 * @param string $text Default header text ("Powered by WordPress").
	 */
	public function header_text( $text ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter signature.
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * @param string[] $classes Login body classes.
	 * @return string[]
	 */
	public function body_class( $classes ): array {
		$classes   = (array) $classes;
		$classes[] = self::BODY_CLASS;
		return $classes;
	}
}
