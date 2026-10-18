<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\AccountRedirect;
use RivianTrackr\Passkeys\Settings;

final class AccountRedirectTest extends TestCase {

	private const ACCOUNT = 'https://riviantrackr.test/account/';
	private const ADMIN   = 'https://riviantrackr.test/wp-admin/';

	protected function setUp(): void {
		rtpk_test_reset();
	}

	private function target( string $redirect_to, bool $can_edit, string $account = self::ACCOUNT ): string {
		return AccountRedirect::target( $redirect_to, $can_edit, $account, self::ADMIN );
	}

	public function test_members_land_on_the_account_page_by_default(): void {
		$this->assertSame( self::ACCOUNT, $this->target( '', false ) );
		$this->assertSame( self::ACCOUNT, $this->target( self::ADMIN, false ), 'the dashboard default' );
		$this->assertSame( self::ACCOUNT, $this->target( 'wp-admin/', false ) );
		$this->assertSame( self::ACCOUNT, $this->target( self::ADMIN . 'profile.php', false ), 'any wp-admin URL' );
		$this->assertSame( self::ACCOUNT, $this->target( '/wp-admin/', false ), 'site-relative wp-admin' );
	}

	public function test_members_keep_an_explicit_front_end_redirect(): void {
		$this->assertSame( 'https://riviantrackr.test/news/', $this->target( 'https://riviantrackr.test/news/', false ) );
		$this->assertSame( '/tires/', $this->target( '/tires/', false ) );
	}

	public function test_editors_keep_wordpress_behaviour(): void {
		$this->assertSame( '', $this->target( '', true ) );
		$this->assertSame( self::ADMIN, $this->target( self::ADMIN, true ) );
		$this->assertSame( self::ADMIN . 'edit.php', $this->target( self::ADMIN . 'edit.php', true ) );
	}

	public function test_without_an_account_page_nothing_changes(): void {
		$this->assertSame( '', $this->target( '', false, '' ) );
		$this->assertSame( self::ADMIN, $this->target( self::ADMIN, false, '' ) );
	}

	public function test_login_redirect_filter_uses_the_users_capabilities(): void {
		$GLOBALS['rtpk_test_posts'] = [ 20 => [ 'status' => 'publish', 'url' => self::ACCOUNT ] ];
		update_option( RTPK_OPTION_NAME, [ 'account_page_id' => 20 ] );
		$redirect = new AccountRedirect( new Settings() );

		$subscriber = new WP_User( 7 );
		$editor     = new WP_User( 8 );
		$editor->caps = [ 'read', 'edit_posts' ];

		$this->assertSame( self::ACCOUNT, $redirect->filter_login_redirect( self::ADMIN, '', $subscriber ) );
		$this->assertSame( self::ADMIN, $redirect->filter_login_redirect( self::ADMIN, '', $editor ) );
		$this->assertSame( self::ADMIN, $redirect->filter_login_redirect( self::ADMIN, '', new WP_Error( 'x', 'failed' ) ), 'a failed login is left alone' );
	}

	public function test_unpublished_account_page_disables_the_redirect(): void {
		$GLOBALS['rtpk_test_posts'] = [ 20 => [ 'status' => 'draft', 'url' => self::ACCOUNT ] ];
		update_option( RTPK_OPTION_NAME, [ 'account_page_id' => 20 ] );
		$redirect = new AccountRedirect( new Settings() );
		$this->assertSame( self::ADMIN, $redirect->filter_login_redirect( self::ADMIN, '', new WP_User( 7 ) ) );
	}

	public function test_settings_derive_admin_bar_and_menu_flags_from_the_page(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->has_account_page() );
		$this->assertFalse( $settings->hides_admin_bar(), 'the toggle defaults on but needs a page' );

		update_option( RTPK_OPTION_NAME, [ 'account_page_id' => 20 ] );
		$settings = new Settings();
		$this->assertTrue( $settings->has_account_page() );
		$this->assertTrue( $settings->hides_admin_bar() );
		$this->assertFalse( $settings->adds_account_menu_item() );

		update_option( RTPK_OPTION_NAME, [ 'account_page_id' => 20, 'hide_admin_bar' => false, 'account_menu_item' => true ] );
		$settings = new Settings();
		$this->assertFalse( $settings->hides_admin_bar() );
		$this->assertTrue( $settings->adds_account_menu_item() );
	}

	public function test_sanitize_keeps_only_a_published_account_page(): void {
		$GLOBALS['rtpk_test_posts'] = [
			20 => [ 'status' => 'publish', 'url' => self::ACCOUNT ],
			21 => [ 'status' => 'draft', 'url' => '' ],
		];
		$settings = new Settings();

		$out = $settings->sanitize( [ 'account_page_id' => '20', 'hide_admin_bar' => '1' ] );
		$this->assertSame( 20, $out['account_page_id'] );
		$this->assertTrue( $out['hide_admin_bar'] );
		$this->assertFalse( $out['account_menu_item'] );

		$out = $settings->sanitize( [ 'account_page_id' => '21' ] );
		$this->assertSame( 0, $out['account_page_id'] );
		$this->assertSame( 'rtpk_account_page', end( $GLOBALS['rtpk_test_errors'] )['code'] );
	}

	public function test_main_menu_detection(): void {
		$this->assertTrue( AccountRedirect::is_main_menu( 'primary', [ 'primary' => 'Primary', 'footer' => 'Footer' ] ) );
		$this->assertFalse( AccountRedirect::is_main_menu( 'footer', [ 'primary' => 'Primary', 'footer' => 'Footer' ] ) );
		$this->assertTrue( AccountRedirect::is_main_menu( '', [] ), 'no registered locations: any menu' );
	}
}
