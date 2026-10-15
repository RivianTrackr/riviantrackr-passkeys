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
}
