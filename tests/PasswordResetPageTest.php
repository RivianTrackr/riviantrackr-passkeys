<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\AccountService;
use RivianTrackr\Passkeys\PasswordResetPage;
use RivianTrackr\Passkeys\RateLimiter;
use RivianTrackr\Passkeys\Settings;

final class PasswordResetPageTest extends TestCase {

	protected function setUp(): void {
		rtpk_test_reset();
	}

	private function page(): PasswordResetPage {
		return new PasswordResetPage( new Settings(), new RateLimiter() );
	}

	// ---- state() ---------------------------------------------------------

	public function test_sent_and_done_are_screens_whatever_else_is_true(): void {
		$this->assertSame( 'sent', PasswordResetPage::state( 'sent', true, true ) );
		$this->assertSame( 'done', PasswordResetPage::state( 'done', true, true ) );
	}

	public function test_a_valid_key_shows_the_new_password_form_even_when_signed_in(): void {
		$this->assertSame( 'reset', PasswordResetPage::state( '', true, false ) );
		$this->assertSame( 'reset', PasswordResetPage::state( 'mismatch', true, false ), 'a form error keeps the form' );
		$this->assertSame( 'reset', PasswordResetPage::state( '', true, true ) );
	}

	public function test_without_a_key_visitors_get_the_request_form_and_members_their_account(): void {
		$this->assertSame( 'request', PasswordResetPage::state( '', false, false ) );
		$this->assertSame( 'request', PasswordResetPage::state( 'expired', false, false ) );
		$this->assertSame( 'signed-in', PasswordResetPage::state( '', false, true ) );
	}

	// ---- notice() --------------------------------------------------------

	public function test_screens_and_unknown_codes_have_no_notice(): void {
		$this->assertNull( PasswordResetPage::notice( '' ) );
		$this->assertNull( PasswordResetPage::notice( 'sent' ) );
		$this->assertNull( PasswordResetPage::notice( 'done' ) );
		$this->assertNull( PasswordResetPage::notice( 'something-else' ) );
	}

	/**
	 * @dataProvider error_codes
	 */
	public function test_every_error_code_is_an_error_notice( string $code, string $fragment ): void {
		$notice = PasswordResetPage::notice( $code );
		$this->assertNotNull( $notice, "code={$code}" );
		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( $fragment, $notice['text'] );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function error_codes(): array {
		return [
			'empty'          => [ 'empty', 'username or email' ],
			'not_allowed'    => [ 'not_allowed', 'not allowed' ],
			'mail'           => [ 'mail', 'could not be sent' ],
			'limited'        => [ 'limited', 'Too many' ],
			'retry'          => [ 'retry', 'timed out' ],
			'failed'         => [ 'failed', 'Something went wrong' ],
			'expired'        => [ 'expired', 'expired' ],
			'empty_password' => [ 'empty_password', 'Enter a new password' ],
			'mismatch'       => [ 'mismatch', 'do not match' ],
			'short'          => [ 'short', (string) AccountService::PASSWORD_MIN ],
			'backslash'      => [ 'backslash', '\\' ],
		];
	}

	// ---- code_for() ------------------------------------------------------

	public function test_success_and_unknown_accounts_both_read_as_sent(): void {
		$this->assertSame( 'sent', PasswordResetPage::code_for( true ) );
		$this->assertSame( 'sent', PasswordResetPage::code_for( new WP_Error( 'invalidcombo', 'There is no account with that username or email address.' ) ), 'nothing reveals who is registered' );
		$this->assertSame( 'sent', PasswordResetPage::code_for( new WP_Error( 'invalid_email', 'There is no account with that username or email address.' ) ), 'core uses invalid_email for an unknown address' );
	}

	public function test_retrieve_password_errors_map_to_page_codes(): void {
		$this->assertSame( 'empty', PasswordResetPage::code_for( new WP_Error( 'empty_username' ) ) );
		$this->assertSame( 'limited', PasswordResetPage::code_for( new WP_Error( 'rtpk_rate_limited' ) ) );
		$this->assertSame( 'not_allowed', PasswordResetPage::code_for( new WP_Error( 'no_password_reset' ) ) );
		$this->assertSame( 'mail', PasswordResetPage::code_for( new WP_Error( 'retrieve_password_email_failure' ) ) );
		$this->assertSame( 'failed', PasswordResetPage::code_for( new WP_Error( 'captcha_failed', 'From a plugin' ) ) );
	}

	// ---- validate_new_password() -----------------------------------------

	public function test_new_password_rules(): void {
		$this->assertSame( 'empty_password', PasswordResetPage::validate_new_password( '', '' ) );
		$this->assertSame( 'backslash', PasswordResetPage::validate_new_password( 'correct\\horse', 'correct\\horse' ) );
		$this->assertSame( 'short', PasswordResetPage::validate_new_password( 'short1', 'short1' ) );
		$this->assertSame( 'mismatch', PasswordResetPage::validate_new_password( 'correct horse battery', 'correct horse staple' ) );
		$this->assertNull( PasswordResetPage::validate_new_password( 'correct horse battery', 'correct horse battery' ) );
	}

	public function test_minimum_length_matches_the_account_page(): void {
		$ok = str_repeat( 'a', AccountService::PASSWORD_MIN );
		$this->assertNull( PasswordResetPage::validate_new_password( $ok, $ok ) );
		$this->assertSame( 'short', PasswordResetPage::validate_new_password( substr( $ok, 1 ), substr( $ok, 1 ) ) );
	}

	// ---- request() -------------------------------------------------------

	public function test_request_sends_the_standard_reset_email(): void {
		$this->assertSame( 'sent', $this->page()->request( 'jose' ) );
		$this->assertSame( [ 'jose' ], $GLOBALS['rtpk_test_reset_calls'] );
	}

	public function test_request_with_nothing_typed_sends_nothing(): void {
		$this->assertSame( 'empty', $this->page()->request( '' ) );
		$this->assertSame( [], $GLOBALS['rtpk_test_reset_calls'] );
	}

	public function test_request_passes_retrieve_password_errors_through(): void {
		$GLOBALS['rtpk_test_reset_result'] = new WP_Error( 'retrieve_password_email_failure' );
		$this->assertSame( 'mail', $this->page()->request( 'jose@riviantrackr.test' ) );
	}

	// ---- guard_lostpassword() (runs inside retrieve_password() everywhere) ----

	public function test_lostpassword_guard_limits_per_ip_and_blocks_the_email(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$page                   = $this->page();
		for ( $i = 0; $i < RTPK_RESET_RATE_LIMIT; $i++ ) {
			$errors = new WP_Error();
			$page->guard_lostpassword( $errors, false );
			$this->assertFalse( $errors->has_errors(), "request {$i} is allowed" );
		}
		$errors = new WP_Error();
		$page->guard_lostpassword( $errors, new WP_User( 5 ) );
		$this->assertSame( [ 'rtpk_rate_limited' ], $errors->get_error_codes(), 'retrieve_password() aborts on any error' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.6';
		$errors                 = new WP_Error();
		$page->guard_lostpassword( $errors, false );
		$this->assertFalse( $errors->has_errors(), 'another connection is unaffected' );
	}

	public function test_lostpassword_guard_ignores_a_non_error_argument(): void {
		$page = $this->page();
		$page->guard_lostpassword( null );
		$this->assertTrue( true, 'no type error' );
	}

	// ---- cookie ----------------------------------------------------------

	public function test_cookie_name_is_scoped_to_the_site(): void {
		$this->assertSame( 'rtpk-resetpass-' . COOKIEHASH, PasswordResetPage::cookie_name() );
	}
}
