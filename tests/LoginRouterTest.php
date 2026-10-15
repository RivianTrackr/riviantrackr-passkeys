<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\LoginRouter;
use RivianTrackr\Passkeys\Settings;

final class LoginRouterTest extends TestCase {

	private const LOGIN    = 'https://riviantrackr.test/sign-in/';
	private const REGISTER = 'https://riviantrackr.test/join/';

	protected function setUp(): void {
		rtpk_test_reset();
		$GLOBALS['rtpk_test_posts'] = [
			10 => [ 'status' => 'publish', 'url' => self::LOGIN ],
			11 => [ 'status' => 'publish', 'url' => self::REGISTER ],
			12 => [ 'status' => 'draft', 'url' => 'https://riviantrackr.test/?page_id=12' ],
		];
	}

	private function resolve( string $method, string $action, array $query = [], bool $logged_in = false, string $login = self::LOGIN, string $register = self::REGISTER ): ?string {
		return LoginRouter::resolve( $method, $action, $query, $logged_in, $login, $register );
	}

	// ---- resolve() ---------------------------------------------------------

	public function test_bare_wp_login_goes_to_sign_in_page(): void {
		$this->assertSame( self::LOGIN, $this->resolve( 'GET', '' ) );
		$this->assertSame( self::LOGIN, $this->resolve( 'GET', 'login' ) );
		$this->assertSame( self::LOGIN, $this->resolve( 'get', 'Login' ), 'method and action are case-insensitive' );
	}

	public function test_register_goes_to_join_page(): void {
		$this->assertSame( self::REGISTER, $this->resolve( 'GET', 'register' ) );
	}

	public function test_register_without_join_page_is_left_alone(): void {
		$this->assertNull( $this->resolve( 'GET', 'register', [], false, self::LOGIN, '' ) );
	}

	public function test_no_sign_in_page_means_no_redirect(): void {
		$this->assertNull( $this->resolve( 'GET', '', [], false, '', self::REGISTER ) );
	}

	public function test_post_requests_are_never_redirected(): void {
		$this->assertNull( $this->resolve( 'POST', '' ) );
		$this->assertNull( $this->resolve( 'POST', 'login', [ 'redirect_to' => '/wp-admin/' ] ) );
		$this->assertNull( $this->resolve( 'POST', 'register' ) );
	}

	/**
	 * @dataProvider keep_actions
	 */
	public function test_other_actions_stay_on_wp_login( string $action ): void {
		$this->assertNull( $this->resolve( 'GET', $action ), "action={$action} must keep working on wp-login.php" );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function keep_actions(): array {
		$actions = [ 'postpass', 'logout', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'confirmaction', 'passkey-setup', 'passkey-signup', 'something-else' ];
		$out     = [];
		foreach ( $actions as $action ) {
			$out[ $action ] = [ $action ];
		}
		return $out;
	}

	public function test_interim_and_reauth_requests_reach_the_stock_form(): void {
		$this->assertNull( $this->resolve( 'GET', '', [ 'interim-login' => '1' ] ) );
		$this->assertNull( $this->resolve( 'GET', 'login', [ 'reauth' => '1' ] ) );
		$this->assertSame( self::LOGIN, $this->resolve( 'GET', 'login', [ 'reauth' => '0' ] ), 'reauth=0 is not a reauth' );
	}

	public function test_signed_in_visitors_are_left_to_wp_login(): void {
		$this->assertNull( $this->resolve( 'GET', '', [], true ) );
		$this->assertNull( $this->resolve( 'GET', '', [ 'redirect_to' => 'https://riviantrackr.test/wp-login.php?action=passkey-setup' ], true ) );
		$this->assertNull( $this->resolve( 'GET', 'register', [], true ) );
	}

	public function test_whitelisted_query_args_pass_through_encoded(): void {
		$setup  = 'https://riviantrackr.test/wp-login.php?action=passkey-setup&redirect_to=/account/';
		$target = $this->resolve(
			'GET',
			'',
			[
				'redirect_to' => $setup,
				'login'       => 'failed',
				'loggedout'   => 'true',
				'rtpk_invite' => 'expired',
				'rtpk_signup' => 'exists',
				'checkemail'  => 'confirm',
				'wp_lang'     => 'en_US',
				'evil'        => '<script>',
				'empty'       => '',
			]
		);

		$this->assertNotNull( $target );
		$this->assertStringStartsWith( self::LOGIN . '?', $target );
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( $setup ), $target );
		$this->assertStringContainsString( 'login=failed', $target );
		$this->assertStringContainsString( 'loggedout=true', $target );
		$this->assertStringContainsString( 'rtpk_invite=expired', $target );
		$this->assertStringContainsString( 'rtpk_signup=exists', $target );
		$this->assertStringContainsString( 'checkemail=confirm', $target );
		$this->assertStringNotContainsString( 'wp_lang', $target );
		$this->assertStringNotContainsString( 'evil', $target );
		$this->assertStringNotContainsString( 'script', $target );
		$this->assertStringNotContainsString( 'empty=', $target );
	}

	public function test_no_args_means_bare_page_url(): void {
		$this->assertSame( self::LOGIN, $this->resolve( 'GET', '', [ 'wp_lang' => 'en_US' ] ) );
	}

	// ---- URL helpers ----------------------------------------------------

	public function test_failure_url_carries_code_and_redirect(): void {
		$this->assertSame( self::LOGIN . '?login=failed', LoginRouter::failure_url( self::LOGIN, 'failed' ) );
		$this->assertSame(
			self::LOGIN . '?login=empty&redirect_to=' . rawurlencode( 'https://riviantrackr.test/wp-admin/' ),
			LoginRouter::failure_url( self::LOGIN, 'empty', 'https://riviantrackr.test/wp-admin/' )
		);
	}

	public function test_login_page_link(): void {
		$this->assertSame( self::LOGIN, LoginRouter::login_page_link( self::LOGIN ) );
		$this->assertSame( self::LOGIN . '?redirect_to=%2Faccount%2F', LoginRouter::login_page_link( self::LOGIN, '/account/' ) );
	}

	// ---- filters backed by Settings ---------------------------------------

	private function router( array $options ): LoginRouter {
		update_option( RTPK_OPTION_NAME, $options );
		return new LoginRouter( new Settings() );
	}

	public function test_login_url_filter_points_at_the_sign_in_page(): void {
		$router = $this->router( [ 'login_page_id' => 10, 'redirect_wp_login' => true ] );
		$stock  = 'https://riviantrackr.test/wp-login.php';

		$this->assertSame( self::LOGIN, $router->filter_login_url( $stock, '', false ) );
		$this->assertSame( self::LOGIN . '?redirect_to=' . rawurlencode( 'https://riviantrackr.test/wp-admin/' ), $router->filter_login_url( $stock . '?redirect_to=x', 'https://riviantrackr.test/wp-admin/', false ) );
		$this->assertSame( $stock . '?reauth=1', $router->filter_login_url( $stock . '?reauth=1', '', true ), 'forced reauth stays on wp-login.php' );
	}

	public function test_login_url_filter_is_a_no_op_without_a_published_page(): void {
		$stock = 'https://riviantrackr.test/wp-login.php';

		$this->assertSame( $stock, $this->router( [ 'redirect_wp_login' => true ] )->filter_login_url( $stock, '', false ), 'no page chosen' );
		$this->assertSame( $stock, $this->router( [ 'login_page_id' => 12, 'redirect_wp_login' => true ] )->filter_login_url( $stock, '', false ), 'draft page' );
		$this->assertSame( $stock, $this->router( [ 'login_page_id' => 99, 'redirect_wp_login' => true ] )->filter_login_url( $stock, '', false ), 'deleted page' );
	}

	public function test_register_url_filter(): void {
		$stock = 'https://riviantrackr.test/wp-login.php?action=register';
		$this->assertSame( self::REGISTER, $this->router( [ 'register_page_id' => 11 ] )->filter_register_url( $stock ) );
		$this->assertSame( $stock, $this->router( [ 'register_page_id' => 12 ] )->filter_register_url( $stock ) );
		$this->assertSame( $stock, $this->router( [] )->filter_register_url( $stock ) );
	}

	public function test_empty_credentials_hook_passes_result_through(): void {
		// Outside a wp-login.php POST nothing happens; the result is returned untouched.
		$router = $this->router( [ 'login_page_id' => 10, 'redirect_wp_login' => true ] );
		$error  = new WP_Error( 'empty_username', 'Empty' );
		$this->assertSame( $error, $router->on_empty_credentials( $error, '', 'pw' ) );
		$user = new WP_User( 5 );
		$this->assertSame( $user, $router->on_empty_credentials( $user, 'jose', 'pw' ) );
	}

	// ---- Settings glue --------------------------------------------------

	public function test_settings_wants_redirect_only_with_toggle_and_page(): void {
		update_option( RTPK_OPTION_NAME, [ 'login_page_id' => 10, 'redirect_wp_login' => true ] );
		$this->assertTrue( ( new Settings() )->wants_wp_login_redirect() );

		update_option( RTPK_OPTION_NAME, [ 'login_page_id' => 10, 'redirect_wp_login' => false ] );
		$this->assertFalse( ( new Settings() )->wants_wp_login_redirect() );

		update_option( RTPK_OPTION_NAME, [ 'login_page_id' => 0, 'redirect_wp_login' => true ] );
		$this->assertFalse( ( new Settings() )->wants_wp_login_redirect() );
	}

	public function test_settings_page_urls_require_published_pages(): void {
		update_option( RTPK_OPTION_NAME, [ 'login_page_id' => 10, 'register_page_id' => 12 ] );
		$settings = new Settings();
		$this->assertSame( self::LOGIN, $settings->login_page_url() );
		$this->assertSame( '', $settings->register_page_url(), 'draft page yields no URL' );
	}

	public function test_sanitize_keeps_only_published_page_ids(): void {
		$out = ( new Settings() )->sanitize(
			[
				'login_page_id'     => '10',
				'register_page_id'  => '12',
				'redirect_wp_login' => '1',
				'brand_wp_login'    => '1',
			]
		);
		$this->assertSame( 10, $out['login_page_id'] );
		$this->assertSame( 0, $out['register_page_id'], 'draft page is cleared' );
		$this->assertTrue( $out['redirect_wp_login'] );
		$this->assertTrue( $out['brand_wp_login'] );
		$this->assertCount( 1, $GLOBALS['rtpk_test_errors'] );
		$this->assertSame( 'rtpk_register_page', $GLOBALS['rtpk_test_errors'][0]['code'] );
	}

	public function test_sanitize_warns_when_redirect_is_on_without_a_page(): void {
		$out = ( new Settings() )->sanitize( [ 'redirect_wp_login' => '1' ] );
		$this->assertSame( 0, $out['login_page_id'] );
		$this->assertTrue( $out['redirect_wp_login'] );
		$codes = array_column( $GLOBALS['rtpk_test_errors'], 'code' );
		$this->assertContains( 'rtpk_redirect_needs_page', $codes );
	}

	public function test_sanitize_defaults_to_off(): void {
		$out = ( new Settings() )->sanitize( [] );
		$this->assertSame( 0, $out['login_page_id'] );
		$this->assertSame( 0, $out['register_page_id'] );
		$this->assertFalse( $out['redirect_wp_login'] );
		$this->assertFalse( $out['brand_wp_login'] );
	}
}
