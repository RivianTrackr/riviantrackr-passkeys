<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\AccountService;

final class AccountServiceTest extends TestCase {

	protected function setUp(): void {
		rtpk_test_reset();
		$GLOBALS['rtpk_test_user_emails'] = [
			'jose@riviantrackr.test'  => 7,
			'other@riviantrackr.test' => 8,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid( array $overrides = [] ): array {
		return array_merge(
			[
				'display_name' => 'Jose',
				'first_name'   => 'Jose',
				'last_name'    => 'Castillo',
				'email'        => 'jose@riviantrackr.test',
				'url'          => 'riviantrackr.com',
				'description'  => 'R1S owner.',
			],
			$overrides
		);
	}

	// ---- profile -------------------------------------------------------------

	public function test_valid_profile_passes_and_is_normalised(): void {
		$out = AccountService::validate_profile( $this->valid(), 7 );
		$this->assertSame( [], $out['errors'] );
		$this->assertSame( 'https://riviantrackr.com', $out['data']['url'], 'scheme is added to a bare domain' );
		$this->assertSame( 'jose@riviantrackr.test', $out['data']['email'] );
		$this->assertSame( 'R1S owner.', $out['data']['description'] );
	}

	public function test_display_name_is_required_and_clipped(): void {
		$out = AccountService::validate_profile( $this->valid( [ 'display_name' => '   ' ] ), 7 );
		$this->assertArrayHasKey( 'display_name', $out['errors'] );

		$long = str_repeat( 'a', AccountService::DISPLAY_NAME_MAX + 40 );
		$out  = AccountService::validate_profile( $this->valid( [ 'display_name' => $long ] ), 7 );
		$this->assertSame( [], $out['errors'] );
		$this->assertSame( AccountService::DISPLAY_NAME_MAX, strlen( $out['data']['display_name'] ) );
	}

	public function test_display_name_tags_are_stripped(): void {
		$out = AccountService::validate_profile( $this->valid( [ 'display_name' => '<b>Jose</b><script>x()</script>' ] ), 7 );
		$this->assertSame( 'Josex()', $out['data']['display_name'] );
	}

	public function test_email_must_be_valid(): void {
		foreach ( [ '', 'nope', 'a@b', 'jose@' ] as $bad ) {
			$out = AccountService::validate_profile( $this->valid( [ 'email' => $bad ] ), 7 );
			$this->assertArrayHasKey( 'email', $out['errors'], "'{$bad}' must be rejected" );
		}
	}

	public function test_email_taken_by_another_user_is_rejected(): void {
		$out = AccountService::validate_profile( $this->valid( [ 'email' => 'other@riviantrackr.test' ] ), 7 );
		$this->assertArrayHasKey( 'email', $out['errors'] );
		$this->assertStringContainsString( 'another account', $out['errors']['email'] );
	}

	public function test_own_email_and_a_free_email_are_accepted(): void {
		$out = AccountService::validate_profile( $this->valid( [ 'email' => 'Jose@RivianTrackr.test' ] ), 7 );
		$this->assertSame( [], $out['errors'], 'the member\'s current address is fine in any case' );

		$out = AccountService::validate_profile( $this->valid( [ 'email' => 'new@riviantrackr.test' ] ), 7 );
		$this->assertSame( [], $out['errors'] );
	}

	public function test_website_is_optional_but_must_be_a_url(): void {
		$out = AccountService::validate_profile( $this->valid( [ 'url' => '' ] ), 7 );
		$this->assertSame( [], $out['errors'] );
		$this->assertSame( '', $out['data']['url'] );

		$out = AccountService::validate_profile( $this->valid( [ 'url' => 'https://' ] ), 7 );
		$this->assertArrayHasKey( 'url', $out['errors'] );
	}

	public function test_bio_is_plain_text_and_clipped(): void {
		$out = AccountService::validate_profile( $this->valid( [ 'description' => '<p>Hi <a href="x">there</a></p>' ] ), 7 );
		$this->assertSame( 'Hi there', $out['data']['description'] );

		$out = AccountService::validate_profile( $this->valid( [ 'description' => str_repeat( 'b', AccountService::BIO_MAX + 5 ) ] ), 7 );
		$this->assertSame( AccountService::BIO_MAX, strlen( $out['data']['description'] ) );
	}

	public function test_errors_are_collected_per_field(): void {
		$out = AccountService::validate_profile(
			[
				'display_name' => '',
				'email'        => 'bad',
				'url'          => 'https://',
			],
			7
		);
		$this->assertSame( [ 'display_name', 'email', 'url' ], array_keys( $out['errors'] ) );
	}

	// ---- password ------------------------------------------------------------

	public function test_password_change_requires_the_current_password(): void {
		$errors = AccountService::validate_password( '', 'longenough1', 'longenough1', 'hashed:secret', 7 );
		$this->assertArrayHasKey( 'current', $errors );

		$errors = AccountService::validate_password( 'wrong', 'longenough1', 'longenough1', 'hashed:secret', 7 );
		$this->assertArrayHasKey( 'current', $errors );
		$this->assertStringContainsString( 'incorrect', $errors['current'] );
	}

	public function test_new_password_must_be_long_and_confirmed(): void {
		$errors = AccountService::validate_password( 'secret', 'short', 'short', 'hashed:secret', 7 );
		$this->assertSame( [ 'new' ], array_keys( $errors ) );

		$errors = AccountService::validate_password( 'secret', 'longenough1', 'longenough2', 'hashed:secret', 7 );
		$this->assertSame( [ 'confirm' ], array_keys( $errors ) );

		$errors = AccountService::validate_password( 'secret', 'secret', 'secret', 'hashed:secret', 7 );
		$this->assertArrayHasKey( 'new', $errors, 'reusing the current password is refused' );
	}

	public function test_valid_password_change_has_no_errors(): void {
		$this->assertSame( [], AccountService::validate_password( 'secret', 'longenough1', 'longenough1', 'hashed:secret', 7 ) );
	}

	// ---- pending email ---------------------------------------------------------

	public function test_pending_email_round_trip(): void {
		$this->assertSame( '', AccountService::pending_email( 7 ) );
		update_user_meta( 7, AccountService::NEW_EMAIL_META, [ 'hash' => 'abc', 'newemail' => 'new@riviantrackr.test' ] );
		$this->assertSame( 'new@riviantrackr.test', AccountService::pending_email( 7 ) );
		AccountService::cancel_email_change( 7 );
		$this->assertSame( '', AccountService::pending_email( 7 ) );
	}
}
