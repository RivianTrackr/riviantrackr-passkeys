<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\AccountPage;
use RivianTrackr\Passkeys\AccountService;
use RivianTrackr\Passkeys\ChallengeStore;
use RivianTrackr\Passkeys\CredentialStore;
use RivianTrackr\Passkeys\ProfileManager;
use RivianTrackr\Passkeys\RateLimiter;
use RivianTrackr\Passkeys\RestController;
use RivianTrackr\Passkeys\WebAuthnService;

/**
 * The account page's REST routes against a real user table: password link,
 * data export, account deletion, email change confirmation.
 */
final class AccountRestIntegrationTest extends IntegrationTestCase {

	private int $member;

	public function set_up(): void {
		parent::set_up();
		$this->member = (int) self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_login'   => 'jose',
				'user_email'   => 'jose@example.org',
				'display_name' => 'Jose',
			]
		);
		wp_set_current_user( $this->member );
	}

	/**
	 * @return WP_Post[]
	 */
	private function privacy_requests( string $action ): array {
		return get_posts(
			[
				'post_type'   => 'user_request',
				'post_status' => 'any',
				'post_name__in' => [ $action ],
				'numberposts' => -1,
			]
		);
	}

	public function test_routes_need_a_signed_in_member(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'POST', 'account/password/link' )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', 'account/data/export' )->get_status() );
		$this->assertSame( 401, $this->rest( 'DELETE', 'account', [ 'confirm' => 'jose' ] )->get_status() );
	}

	public function test_password_link_emails_the_branded_reset_to_the_member(): void {
		AccountService::mark_no_password( $this->member );

		$response = $this->rest( 'POST', 'account/password/link' );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertStringContainsString( 'jose@example.org', $response->get_data()['message'] );

		$mail = $this->last_mail();
		$this->assertSame( 'jose@example.org', $mail->to[0][0] );
		$this->assertSame( 'Reset your ' . get_bloginfo( 'name' ) . ' password', $mail->subject );
		$parts = $this->reset_link_parts( $mail->body );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( $parts['key'], 'jose' ) );
	}

	public function test_export_request_is_filed_confirmed_and_only_once(): void {
		$response = $this->rest( 'POST', 'account/data/export' );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$requests = $this->privacy_requests( 'export_personal_data' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'request-confirmed', $requests[0]->post_status, 'no confirmation email round trip for a signed-in member' );
		$this->assertSame( 'jose@example.org', $requests[0]->post_title );

		$again = $this->rest( 'POST', 'account/data/export' );
		$this->assertSame( 409, $again->get_status() );
		$this->assertCount( 1, $this->privacy_requests( 'export_personal_data' ) );
	}

	public function test_a_member_deletes_their_account_and_their_passkeys_go_with_it(): void {
		$store = $this->plugin()->credentials();
		$store->insert(
			[
				'user_id'              => $this->member,
				'credential_id_binary' => random_bytes( 16 ),
				'public_key'           => 'pem',
				'sign_count'           => 0,
				'aaguid'               => null,
				'transports'           => [ 'internal' ],
				'name'                 => 'Phone',
				'attestation_format'   => 'none',
				'backup_eligible'      => false,
				'backed_up'            => false,
			]
		);
		$this->assertSame( 1, $store->count_for_user( $this->member ) );

		$wrong = $this->rest( 'DELETE', 'account', [ 'confirm' => 'someone else' ] );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertArrayHasKey( 'confirm', $wrong->get_data()['data']['fields'] );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'id', $this->member ), 'still here' );

		$deleted = $this->rest( 'DELETE', 'account', [ 'confirm' => ' JOSE ' ] );
		$this->assertSame( 200, $deleted->get_status(), wp_json_encode( $deleted->get_data() ) );
		$this->assertTrue( $deleted->get_data()['deleted'] );
		$this->assertSame( home_url( '/' ), $deleted->get_data()['redirect'] );
		$this->assertFalse( get_user_by( 'id', $this->member ) );
		$this->assertSame( 0, $store->count_for_user( $this->member ), 'deleted_user dropped the passkeys' );
		$this->assertSame( 0, get_current_user_id(), 'signed out' );
	}

	public function test_an_editor_or_a_site_that_disabled_it_gets_an_erasure_request_instead(): void {
		$editor = (int) self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => 'ed',
				'user_email' => 'ed@example.org',
			]
		);
		wp_set_current_user( $editor );

		$response = $this->rest( 'DELETE', 'account', [ 'confirm' => 'ed' ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $response->get_data()['deleted'] );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'id', $editor ), 'editors are not deleted on the spot' );
		$requests = $this->privacy_requests( 'remove_personal_data' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'ed@example.org', $requests[0]->post_title );

		$this->set_settings( [ 'self_delete' => false ] );
		wp_set_current_user( $this->member );
		$response = $this->rest( 'DELETE', 'account', [ 'confirm' => 'jose' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['deleted'] );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'id', $this->member ) );
		$this->assertCount( 2, $this->privacy_requests( 'remove_personal_data' ) );
	}

	public function test_an_email_change_is_confirmed_from_the_account_page(): void {
		$account = $this->page_with( '[riviantrackr_account]', 'account' );
		$this->set_settings( [ 'account_page_id' => $account ] );

		$response = $this->rest(
			'POST',
			'account/profile',
			[
				'display_name' => 'Jose',
				'first_name'   => '',
				'last_name'    => '',
				'email'        => 'new@example.org',
				'url'          => '',
				'description'  => '',
			]
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'new@example.org', $response->get_data()['pendingEmail'] );
		$this->assertSame( 'jose@example.org', get_user_by( 'id', $this->member )->user_email, 'not applied yet' );

		$mail = $this->last_mail();
		$this->assertSame( 'new@example.org', $mail->to[0][0] );
		$this->assertSame( 'Confirm your new ' . get_bloginfo( 'name' ) . ' email address', $mail->subject );
		$this->assertStringContainsString( 'text/html', $mail->header );
		$this->assertMatchesRegularExpression( '/newuseremail=([a-f0-9]{32})/', $mail->body );
		preg_match( '/newuseremail=([a-f0-9]{32})/', $mail->body, $m );
		$this->assertStringContainsString( get_permalink( $account ), $mail->body, 'the link lands on the account page, not wp-admin' );

		$settings = $this->plugin()->settings();
		$store    = $this->plugin()->credentials();
		$rest     = new RestController( $settings, $store, new ChallengeStore(), new RateLimiter(), new WebAuthnService( $settings, $store ) );
		$page     = new AccountPage( $settings, $store, new ProfileManager( $settings, $store, $rest ) );

		$this->go_to( add_query_arg( 'newuseremail', 'wrong-hash', get_permalink( $account ) ) );
		$location = $this->expect_redirect( fn() => $page->maybe_confirm_email() );
		$this->assertStringContainsString( 'rtpk_email=invalid', $location );
		$this->assertSame( 'jose@example.org', get_user_by( 'id', $this->member )->user_email );

		$this->go_to( add_query_arg( 'newuseremail', $m[1], get_permalink( $account ) ) );
		$location = $this->expect_redirect( fn() => $page->maybe_confirm_email() );
		$this->assertStringContainsString( 'rtpk_email=changed', $location );
		$this->assertStringEndsWith( '#profile', $location );
		$this->assertSame( 'new@example.org', get_user_by( 'id', $this->member )->user_email );
		$this->assertSame( '', AccountService::pending_email( $this->member ) );
	}
}
