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

	public function test_signup_accounts_are_flagged_until_a_password_is_set(): void {
		$this->assertFalse( AccountService::has_no_password( 7 ) );
		AccountService::mark_no_password( 7 );
		$this->assertTrue( AccountService::has_no_password( 7 ) );
		AccountService::on_password_set( 'chosen by the member', 7 );
		$this->assertFalse( AccountService::has_no_password( 7 ), 'the wp_set_password action clears the flag' );
	}

	public function test_password_link_sends_the_standard_reset_email_to_the_member(): void {
		$this->assertSame( 'sent', AccountService::send_password_link( new WP_User( 7, 'jose' ) ) );
		$this->assertSame( [ 'jose' ], $GLOBALS['rtpk_test_reset_calls'] );

		$GLOBALS['rtpk_test_reset_result'] = new WP_Error( 'rtpk_rate_limited' );
		$this->assertSame( 'limited', AccountService::send_password_link( new WP_User( 7, 'jose' ) ) );
	}

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

	public function test_confirmation_link_lands_on_the_account_page_when_one_is_set(): void {
		$this->assertSame( 'https://riviantrackr.test/wp-admin/profile.php?newuseremail=abc', AccountService::confirm_email_url( 'abc' ) );

		$GLOBALS['rtpk_test_posts'] = [ 20 => [ 'status' => 'publish', 'url' => 'https://riviantrackr.test/account/' ] ];
		update_option( RTPK_OPTION_NAME, [ 'account_page_id' => 20 ] );
		$this->assertSame( 'https://riviantrackr.test/account/?newuseremail=abc', AccountService::confirm_email_url( 'abc' ) );
	}

	public function test_confirming_with_the_right_hash_applies_the_new_address(): void {
		$GLOBALS['rtpk_test_users'][7] = new WP_User( 7, 'jose', 'Jose', 'old@riviantrackr.test' );
		update_user_meta( 7, AccountService::NEW_EMAIL_META, [ 'hash' => 'h4sh', 'newemail' => 'new@riviantrackr.test' ] );

		$this->assertSame( '', AccountService::confirm_email_change( 7, 'wrong' ), 'wrong hash' );
		$this->assertSame( 'new@riviantrackr.test', AccountService::pending_email( 7 ), 'a wrong hash leaves the pending change alone' );
		$this->assertSame( [], $GLOBALS['rtpk_test_updated_users'] );

		$this->assertSame( 'new@riviantrackr.test', AccountService::confirm_email_change( 7, 'h4sh' ) );
		$this->assertSame( 'new@riviantrackr.test', $GLOBALS['rtpk_test_users'][7]->user_email );
		$this->assertSame( '', AccountService::pending_email( 7 ), 'pending change consumed' );

		$this->assertSame( '', AccountService::confirm_email_change( 7, 'h4sh' ), 'a used link does nothing' );
		$this->assertSame( '', AccountService::confirm_email_change( 7, '' ) );
	}

	// ---- personal data ---------------------------------------------------------

	public function test_members_delete_immediately_but_publishers_and_disabled_sites_file_a_request(): void {
		$member = new WP_User( 7 );
		$editor = new WP_User( 8 );
		$editor->caps = [ 'read', 'edit_posts' ];
		$admin        = new WP_User( 9 );
		$admin->caps  = [ 'manage_options' ];

		$this->assertSame( 'delete', AccountService::deletion_mode( $member, true ) );
		$this->assertSame( 'request', AccountService::deletion_mode( $member, false ), 'setting off' );
		$this->assertSame( 'request', AccountService::deletion_mode( $editor, true ) );
		$this->assertSame( 'request', AccountService::deletion_mode( $admin, true ) );
	}

	public function test_privacy_requests_are_filed_confirmed_and_not_duplicated(): void {
		$user = new WP_User( 7, 'jose', 'Jose', 'jose@riviantrackr.test' );
		$id   = AccountService::privacy_request( $user, 'export_personal_data' );
		$this->assertIsInt( $id );
		$this->assertSame( [ [ 'email' => 'jose@riviantrackr.test', 'action' => 'export_personal_data', 'status' => 'confirmed' ] ], $GLOBALS['rtpk_test_privacy_requests'] );

		$again = AccountService::privacy_request( $user, 'export_personal_data' );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'duplicate_request', $again->get_error_code() );

		$this->assertIsInt( AccountService::privacy_request( $user, 'remove_personal_data' ), 'a different action is a different request' );
	}

	public function test_delete_account_removes_the_user_and_signs_out(): void {
		$user                               = new WP_User( 7 );
		$GLOBALS['rtpk_test_users'][7]      = $user;
		$GLOBALS['rtpk_test_current_user']  = $user;

		$this->assertTrue( AccountService::delete_account( $user ) );
		$this->assertSame( [ 7 ], $GLOBALS['rtpk_test_deleted_users'] );
		$this->assertNull( $GLOBALS['rtpk_test_current_user'], 'signed out' );
		$actions = array_column( $GLOBALS['rtpk_test_actions'], 0 );
		$this->assertContains( 'riviantrackr_passkeys_account_deleting', $actions );
		$this->assertContains( 'deleted_user', $actions, 'core hook that drops the passkeys' );
	}

	public function test_pending_email_round_trip(): void {
		$this->assertSame( '', AccountService::pending_email( 7 ) );
		update_user_meta( 7, AccountService::NEW_EMAIL_META, [ 'hash' => 'abc', 'newemail' => 'new@riviantrackr.test' ] );
		$this->assertSame( 'new@riviantrackr.test', AccountService::pending_email( 7 ) );
		AccountService::cancel_email_change( 7 );
		$this->assertSame( '', AccountService::pending_email( 7 ) );
	}
}
