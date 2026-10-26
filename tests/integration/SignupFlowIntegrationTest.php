<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\AccountService;
use RivianTrackr\Passkeys\Installer;
use RivianTrackr\Passkeys\SignupPage;
use RivianTrackr\Passkeys\SignupService;

/**
 * Open signup end to end: REST request, emailed token, landing page that
 * creates and signs in the member, hand-off to passkey setup.
 */
final class SignupFlowIntegrationTest extends IntegrationTestCase {

	private int $setup_page;
	private int $join_page;
	private SignupPage $landing;

	public function set_up(): void {
		parent::set_up();
		update_option( SignupService::OPTION, [ 'enabled' => true ] );
		$this->plugin()->signup()->flush();

		$this->setup_page = $this->page_with( '[riviantrackr_passkey_setup]', 'passkey' );
		$this->join_page  = $this->page_with( '[riviantrackr_signup]', 'join' );
		$this->set_settings(
			[
				'setup_page_id'    => $this->setup_page,
				'register_page_id' => $this->join_page,
			]
		);
		$this->landing = new SignupPage( $this->plugin()->signup(), $this->plugin()->invites(), $this->plugin()->settings() );
	}

	/**
	 * A form token old enough to pass the minimum-time check.
	 *
	 * @return array{ts:int,sig:string}
	 */
	private function aged_form_token(): array {
		$ts = time() - RTPK_SIGNUP_MIN_SECONDS - 5;
		return [
			'ts'  => $ts,
			'sig' => (string) wp_hash( 'rtpk-signup|' . $ts ),
		];
	}

	/**
	 * @param array<string,string> $extra More request fields.
	 */
	private function request_signup( string $email = 'new.member@example.org', array $extra = [] ): string {
		$token    = $this->aged_form_token();
		$response = $this->rest(
			'POST',
			'signup/request',
			array_merge(
				[
					'email'   => $email,
					'name'    => 'New Member',
					'website' => '',
					'ts'      => (string) $token['ts'],
					'sig'     => $token['sig'],
				],
				$extra
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['success'] );

		$mail = $this->last_mail();
		$this->assertSame( $email, $mail->to[0][0] );
		$this->assertMatchesRegularExpression( '/token=([A-Za-z0-9_-]+)/', $mail->body );
		preg_match( '/token=([A-Za-z0-9_-]+)/', $mail->body, $m );
		return $m[1];
	}

	public function test_the_token_route_hands_out_a_fresh_uncached_token(): void {
		$response = $this->rest( 'GET', 'signup/token' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEqualsWithDelta( time(), $data['ts'], 5 );
		$this->assertSame( 'early', $this->plugin()->signup()->form_token_problem( (string) $data['ts'], $data['sig'] ), 'signed by us and still subject to the minimum time' );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );

		update_option( SignupService::OPTION, [ 'enabled' => false ] );
		$this->plugin()->signup()->flush();
		$this->assertSame( 403, $this->rest( 'GET', 'signup/token' )->get_status() );
	}

	public function test_a_token_from_a_cached_page_is_reported_as_expired(): void {
		$ts       = time() - 3 * DAY_IN_SECONDS;
		$response = $this->rest(
			'POST',
			'signup/request',
			[
				'email' => 'new.member@example.org',
				'ts'    => (string) $ts,
				'sig'   => (string) wp_hash( 'rtpk-signup|' . $ts ),
			]
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rtpk_signup_form_expired', $response->get_data()['code'] );
	}

	public function test_no_user_exists_until_the_link_is_clicked(): void {
		$this->request_signup();
		$this->assertFalse( get_user_by( 'email', 'new.member@example.org' ) );
	}

	public function test_the_emailed_link_creates_the_member_signs_them_in_and_sends_them_to_setup(): void {
		$token    = $this->request_signup();
		$_GET     = [
			'action' => SignupService::ACTION,
			'token'  => $token,
		];
		$_REQUEST = $_GET;

		$location = $this->expect_redirect( fn() => $this->landing->handle() );

		$user = get_user_by( 'email', 'new.member@example.org' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'New Member', $user->display_name );
		$this->assertSame( [ 'subscriber' ], $user->roles );
		$this->assertSame( $user->ID, get_current_user_id(), 'signed in' );
		$this->assertTrue( AccountService::has_no_password( $user->ID ), 'flagged so the account page offers "Set a password"' );
		$this->assertStringStartsWith( get_permalink( $this->setup_page ), $location, 'the owner\'s setup page, not wp-login.php' );

		// The link is spent.
		wp_set_current_user( 0 );
		$location = $this->expect_redirect( fn() => $this->landing->handle() );
		$this->assertSame( add_query_arg( 'rtpk_signup', 'expired', get_permalink( $this->join_page ) ), $location );
	}

	public function test_a_member_who_joined_from_a_post_lands_back_on_it_after_setup(): void {
		$post  = self::factory()->post->create();
		$back  = get_permalink( $post ) . '#respond';
		$token = $this->request_signup( 'new.member@example.org', [ 'redirect_to' => $back ] );

		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT redirect_to FROM ' . Installer::pending_signups_table() . ' WHERE email = %s', 'new.member@example.org' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $back, $stored, 'kept on the pending row, not in the email' );
		$this->assertStringNotContainsString( 'redirect_to', $this->last_mail()->body );

		$_GET     = [
			'action' => SignupService::ACTION,
			'token'  => $token,
		];
		$_REQUEST = $_GET;
		$location = $this->expect_redirect( fn() => $this->landing->handle() );

		$this->assertSame( add_query_arg( 'redirect_to', rawurlencode( $back ), get_permalink( $this->setup_page ) ), $location, 'passkey setup first, then the post' );
	}

	public function test_an_off_site_return_address_is_ignored(): void {
		$token    = $this->request_signup( 'new.member@example.org', [ 'redirect_to' => 'https://evil.example/' ] );
		$_GET     = [
			'action' => SignupService::ACTION,
			'token'  => $token,
		];
		$_REQUEST = $_GET;
		$location = $this->expect_redirect( fn() => $this->landing->handle() );
		$this->assertStringNotContainsString( 'evil.example', $location );
	}

	public function test_a_bad_token_lands_on_the_join_page_with_the_expired_notice(): void {
		$_GET     = [
			'action' => SignupService::ACTION,
			'token'  => 'not-a-token',
		];
		$_REQUEST = $_GET;
		$this->assertSame( add_query_arg( 'rtpk_signup', 'expired', get_permalink( $this->join_page ) ), $this->expect_redirect( fn() => $this->landing->handle() ) );
	}

	public function test_the_new_member_can_ask_for_a_password_from_the_account_page(): void {
		$token    = $this->request_signup();
		$_GET     = [
			'action' => SignupService::ACTION,
			'token'  => $token,
		];
		$_REQUEST = $_GET;
		$this->expect_redirect( fn() => $this->landing->handle() );
		reset_phpmailer_instance();

		$response = $this->rest( 'POST', 'account/password/link' );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$mail = $this->last_mail();
		$this->assertSame( 'new.member@example.org', $mail->to[0][0] );
		$parts = $this->reset_link_parts( $mail->body );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( $parts['key'], $parts['login'] ) );
	}
}
