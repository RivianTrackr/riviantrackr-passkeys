<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\Plugin;

/**
 * Thrown by the wp_redirect filter so a handler that redirects and exits can
 * be observed without ending the test process.
 */
final class RTPK_Redirect_Exception extends RuntimeException {

	public function __construct( public string $location, public int $status ) {
		parent::__construct( "redirect to {$location}" );
	}
}

/**
 * Shared helpers for the integration suite.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR']    = '203.0.113.' . wp_rand( 1, 254 );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = [];
		$_POST                     = [];
		$_REQUEST                  = [];
		$_COOKIE                   = [];
		// Cookies cannot be sent once PHPUnit has written to stdout.
		add_filter( 'send_auth_cookies', '__return_false' );
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTPS'] );
		$GLOBALS['wp_rest_server'] = null;
		$_GET                      = [];
		$_POST                     = [];
		$_REQUEST                  = [];
		$_COOKIE                   = [];
		parent::tear_down();
	}

	protected function plugin(): Plugin {
		return Plugin::instance();
	}

	/**
	 * Dispatch a request against the plugin's REST namespace.
	 *
	 * @param array<string,mixed> $body JSON body.
	 */
	protected function rest( string $method, string $path, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RTPK_REST_NAMESPACE . '/' . ltrim( $path, '/' ) );
		if ( [] !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Run code that calls wp_redirect()/wp_safe_redirect() and return where
	 * it wanted to go. Fails the test when nothing redirected.
	 */
	protected function expect_redirect( callable $fn ): string {
		$thrower = static function ( $location, $status ): void {
			throw new RTPK_Redirect_Exception( (string) $location, (int) $status );
		};
		add_filter( 'wp_redirect', $thrower, 1, 2 );
		try {
			$fn();
		} catch ( RTPK_Redirect_Exception $e ) {
			return $e->location;
		} finally {
			remove_filter( 'wp_redirect', $thrower, 1 );
		}
		$this->fail( 'Expected a redirect, none happened.' );
	}

	/**
	 * The emails sent so far (MockPHPMailer records them).
	 *
	 * @return array<int,object>
	 */
	protected function sent_mail(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		return $mailer ? $mailer->mock_sent : [];
	}

	/**
	 * The last email, with its body decoded: PHPMailer quoted-printable
	 * encodes HTML (soft line breaks, =3D) and esc_url() writes & as &#038;.
	 */
	protected function last_mail(): object {
		$sent = $this->sent_mail();
		$this->assertNotEmpty( $sent, 'an email was sent' );
		$mail       = (object) end( $sent );
		$mail->body = self::decode_body( (string) $mail->body );
		return $mail;
	}

	public static function decode_body( string $body ): string {
		return html_entity_decode( quoted_printable_decode( $body ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Serve the test site over HTTPS for this test: WebAuthn origins must be
	 * https (the bundled library enforces it outside localhost).
	 */
	protected function use_https(): void {
		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		$_SERVER['HTTPS'] = 'on';
	}

	/**
	 * Create a published page holding a shortcode and return its ID.
	 */
	protected function page_with( string $shortcode, string $slug ): int {
		return (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => ucfirst( str_replace( '-', ' ', $slug ) ),
				'post_name'    => $slug,
				'post_content' => $shortcode,
			]
		);
	}

	/**
	 * Merge values into the plugin's main option and flush its cache.
	 *
	 * @param array<string,mixed> $values Option values.
	 */
	protected function set_settings( array $values ): void {
		$current = get_option( RTPK_OPTION_NAME, [] );
		update_option( RTPK_OPTION_NAME, array_merge( is_array( $current ) ? $current : [], $values ) );
		$this->plugin()->settings()->flush();
	}

	/**
	 * Pull "key" and "login" out of the reset link in a decoded email body
	 * (see last_mail()).
	 *
	 * @return array{key:string,login:string}
	 */
	protected function reset_link_parts( string $html ): array {
		$this->assertMatchesRegularExpression( '/action=rp&key=([^&"<\s]+)&login=([^&"<\s]+)/', $html, 'the email carries the reset link' );
		preg_match( '/action=rp&key=([^&"<\s]+)&login=([^&"<\s]+)/', $html, $m );
		return [
			'key'   => rawurldecode( $m[1] ),
			'login' => rawurldecode( $m[2] ),
		];
	}
}
