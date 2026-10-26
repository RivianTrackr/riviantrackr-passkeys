<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\LoginBranding;
use RivianTrackr\Passkeys\LoginPage;
use RivianTrackr\Passkeys\SetupPage;
use RivianTrackr\Passkeys\SignupPage;

final class LoginPageTest extends TestCase {

	protected function setUp(): void {
		rtpk_test_reset();
	}

	private function types( array $notices ): array {
		return array_column( $notices, 'type' );
	}

	public function test_no_query_means_no_notices(): void {
		$this->assertSame( [], LoginPage::notices( [] ) );
		$this->assertSame( [], LoginPage::notices( [ 'login' => 'weird', 'loggedout' => 'false' ] ) );
	}

	public function test_failed_and_empty_logins_are_errors(): void {
		$failed = LoginPage::notices( [ 'login' => 'failed' ] );
		$this->assertSame( [ 'error' ], $this->types( $failed ) );
		$this->assertStringContainsString( 'incorrect', $failed[0]['text'] );

		$empty = LoginPage::notices( [ 'login' => 'empty' ] );
		$this->assertSame( [ 'error' ], $this->types( $empty ) );
		$this->assertStringContainsString( 'username and password', $empty[0]['text'] );
	}

	public function test_logged_out_is_a_success_notice(): void {
		$this->assertSame( [ 'success' ], $this->types( LoginPage::notices( [ 'loggedout' => 'true' ] ) ) );
	}

	public function test_password_reset_confirmation_passes_through(): void {
		$n = LoginPage::notices( [ 'checkemail' => 'confirm' ] );
		$this->assertSame( [ 'info' ], $this->types( $n ) );
		$this->assertStringContainsString( 'reset your password', $n[0]['text'] );
	}

	public function test_invite_and_signup_hints_reuse_the_wp_login_copy(): void {
		$n = LoginPage::notices( [ 'rtpk_invite' => 'expired' ] );
		$this->assertSame( [ SetupPage::expired_hint() ], array_column( $n, 'text' ) );

		$n = LoginPage::notices( [ 'rtpk_signup' => 'exists' ] );
		$this->assertSame( [ SignupPage::exists_hint() ], array_column( $n, 'text' ) );
	}

	public function test_redirect_to_setup_page_shows_the_setup_hint(): void {
		$n = LoginPage::notices( [], 'https://riviantrackr.test/wp-login.php?action=passkey-setup&redirect_to=/account/' );
		$this->assertSame( [ SetupPage::setup_hint() ], array_column( $n, 'text' ) );

		$this->assertSame( [], LoginPage::notices( [], 'https://riviantrackr.test/account/' ) );
	}

	public function test_redirect_to_the_owners_setup_page_also_shows_the_hint(): void {
		$page = 'https://riviantrackr.test/passkey/';
		$n    = LoginPage::notices( [], $page . '?redirect_to=%2Faccount%2F', $page );
		$this->assertSame( [ SetupPage::setup_hint() ], array_column( $n, 'text' ) );
		$this->assertSame( [], LoginPage::notices( [], $page, '' ), 'without the setting the page is just a page' );
		$this->assertSame( [], LoginPage::notices( [], 'https://riviantrackr.test/account/', $page ) );
	}

	public function test_leads_to_setup_matches_paths_and_plain_permalinks(): void {
		$this->assertTrue( SetupPage::leads_to_setup( 'https://riviantrackr.test/wp-login.php?action=passkey-setup' ) );
		$this->assertTrue( SetupPage::leads_to_setup( '/passkey/', 'https://riviantrackr.test/passkey/' ) );
		$this->assertTrue( SetupPage::leads_to_setup( 'https://riviantrackr.test/passkey', 'https://riviantrackr.test/passkey/' ), 'trailing slash is not significant' );
		$this->assertFalse( SetupPage::leads_to_setup( 'https://riviantrackr.test/passkey-news/', 'https://riviantrackr.test/passkey/' ) );
		$this->assertTrue( SetupPage::leads_to_setup( 'https://riviantrackr.test/?page_id=14&redirect_to=x', 'https://riviantrackr.test/?page_id=14' ), 'plain permalinks' );
		$this->assertFalse( SetupPage::leads_to_setup( 'https://riviantrackr.test/?page_id=15', 'https://riviantrackr.test/?page_id=14' ) );
		$this->assertFalse( SetupPage::leads_to_setup( '', 'https://riviantrackr.test/passkey/' ) );
	}

	public function test_join_page_notices_for_expired_and_failed_links(): void {
		$this->assertNull( SignupPage::notice( '', 24 ) );
		$this->assertNull( SignupPage::notice( 'exists', 24 ), 'exists is a sign-in page notice' );
		$expired = SignupPage::notice( 'expired', 24 );
		$this->assertSame( 'info', $expired['type'] );
		$this->assertStringContainsString( '24 hours', $expired['text'] );
		$this->assertStringContainsString( '1 hour.', SignupPage::notice( 'expired', 1 )['text'] );
		$this->assertSame( 'error', SignupPage::notice( 'failed', 24 )['type'] );
	}

	public function test_expired_invite_wins_over_the_generic_setup_hint(): void {
		$n = LoginPage::notices( [ 'rtpk_invite' => 'expired' ], 'https://riviantrackr.test/wp-login.php?action=passkey-setup' );
		$this->assertCount( 1, $n );
		$this->assertSame( SetupPage::expired_hint(), $n[0]['text'] );
	}

	public function test_notices_stack_in_a_stable_order(): void {
		$n = LoginPage::notices( [ 'login' => 'failed', 'rtpk_signup' => 'exists' ] );
		$this->assertSame( [ 'error', 'info' ], $this->types( $n ) );
	}

	public function test_logo_css_uses_image_or_site_name(): void {
		$img = LoginBranding::logo_css( 'https://riviantrackr.test/logo.png' );
		$this->assertStringContainsString( 'body.rtpk-branded #login h1 a', $img );
		$this->assertStringContainsString( 'url(https://riviantrackr.test/logo.png)', $img );

		$text = LoginBranding::logo_css( '' );
		$this->assertStringContainsString( 'text-indent:0', $text );
		$this->assertStringNotContainsString( 'url(', $text );
	}

	public function test_join_link_brings_new_members_back_where_they_were_headed(): void {
		$join = 'https://riviantrackr.test/join/';
		$post = 'https://riviantrackr.test/news/epa-range/#respond';

		$this->assertSame( $join . '?redirect_to=' . rawurlencode( $post ), LoginPage::join_link( $join, $post ) );
		$this->assertSame( $join, LoginPage::join_link( $join, '' ), 'nowhere in particular' );
		$this->assertSame( $join, LoginPage::join_link( $join, 'https://riviantrackr.test/wp-login.php?action=passkey-setup' ), 'signup ends at passkey setup anyway' );
		$this->assertSame( $join, LoginPage::join_link( $join, 'https://riviantrackr.test/passkey/', 'https://riviantrackr.test/passkey/' ), 'the setup page too' );
	}
}
