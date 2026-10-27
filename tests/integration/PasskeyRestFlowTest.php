<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\Helpers;

/**
 * The real REST routes, the real database, a software authenticator:
 * register a passkey, sign in with it, and make sure a challenge is single use.
 */
final class PasskeyRestFlowTest extends IntegrationTestCase {

	private int $user_id;
	private FakeAuthenticator $auth;
	private string $origin;

	public function set_up(): void {
		parent::set_up();
		$this->use_https();
		$this->user_id = (int) self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_login'   => 'jose',
				'display_name' => 'Jose',
			]
		);
		$this->origin  = Helpers::site_origin();
		$this->auth    = new FakeAuthenticator( Helpers::default_rp_id() );
	}

	/**
	 * @return array<string,mixed> The stored credential as the API presents it.
	 */
	private function register_passkey( string $name = 'Test key' ): array {
		wp_set_current_user( $this->user_id );

		$options = $this->rest( 'POST', 'register/options' );
		$this->assertSame( 200, $options->get_status(), wp_json_encode( $options->get_data() ) );
		$data = $options->get_data();
		$this->assertSame( Helpers::default_rp_id(), $data['publicKey']['rp']['id'] );
		$this->assertSame( 'jose', $data['publicKey']['user']['name'] );

		$challenge = Helpers::base64url_decode( $data['publicKey']['challenge'] );
		$this->assertNotNull( $challenge );
		[ $client_data, $attestation ] = $this->auth->register( $challenge, $this->origin );

		$verify = $this->rest(
			'POST',
			'register/verify',
			[
				'challengeId' => $data['challengeId'],
				'name'        => $name,
				'credential'  => [
					'id'       => Helpers::base64url_encode( $this->auth->credential_id ),
					'rawId'    => Helpers::base64url_encode( $this->auth->credential_id ),
					'type'     => 'public-key',
					'response' => [
						'clientDataJSON'    => Helpers::base64url_encode( $client_data ),
						'attestationObject' => Helpers::base64url_encode( $attestation ),
						'transports'        => [ 'internal' ],
					],
				],
			]
		);
		$this->assertSame( 200, $verify->get_status(), wp_json_encode( $verify->get_data() ) );
		return (array) $verify->get_data()['credential'];
	}

	public function test_registration_stores_the_credential_for_the_signed_in_user(): void {
		$credential = $this->register_passkey();

		$this->assertSame( 'Test key', $credential['name'] );
		$this->assertSame( [ 'internal' ], $credential['transports'] );
		$this->assertSame( 1, $this->plugin()->credentials()->count_for_user( $this->user_id ) );

		$list = $this->rest( 'GET', 'credentials' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertCount( 1, $list->get_data()['credentials'] );
	}

	public function test_registration_needs_a_signed_in_user(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'POST', 'register/options' )->get_status() );
	}

	public function test_a_registered_passkey_signs_the_member_in(): void {
		$this->register_passkey();
		wp_set_current_user( 0 );

		$options = $this->rest( 'POST', 'login/options' );
		$this->assertSame( 200, $options->get_status() );
		$data      = $options->get_data();
		$challenge = Helpers::base64url_decode( $data['publicKey']['challenge'] );
		$this->assertArrayNotHasKey( 'allowCredentials', $data['publicKey'], 'discoverable credentials: no list of IDs' );

		[ $client_data, $auth_data, $signature ] = $this->auth->assert( $challenge, $this->origin );
		$payload                                 = [
			'challengeId' => $data['challengeId'],
			'redirectTo'  => home_url( '/news/' ),
			'credential'  => [
				'id'       => Helpers::base64url_encode( $this->auth->credential_id ),
				'rawId'    => Helpers::base64url_encode( $this->auth->credential_id ),
				'type'     => 'public-key',
				'response' => [
					'clientDataJSON'    => Helpers::base64url_encode( $client_data ),
					'authenticatorData' => Helpers::base64url_encode( $auth_data ),
					'signature'         => Helpers::base64url_encode( $signature ),
					'userHandle'        => null,
				],
			],
		];

		$verify = $this->rest( 'POST', 'login/verify', $payload );
		$this->assertSame( 200, $verify->get_status(), wp_json_encode( $verify->get_data() ) );
		$this->assertSame( $this->user_id, get_current_user_id(), 'the member is signed in' );
		$this->assertSame( home_url( '/news/' ), $verify->get_data()['redirect'] );
		$this->assertTrue( $verify->get_data()['success'], 'the script only redirects on success: true' );

		$row = $this->plugin()->credentials()->find_for_user( $this->user_id )[0];
		$this->assertNotNull( $row['last_used_at'], 'last use is recorded' );
		$this->assertSame( $this->auth->counter, (int) $row['sign_count'] );

		wp_set_current_user( 0 );
		$replay = $this->rest( 'POST', 'login/verify', $payload );
		$this->assertSame( 400, $replay->get_status(), 'a challenge is single use' );
		$this->assertSame( 'rtpk_challenge', $replay->get_data()['code'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_an_assertion_for_the_wrong_origin_is_rejected(): void {
		$this->register_passkey();
		wp_set_current_user( 0 );

		$data      = $this->rest( 'POST', 'login/options' )->get_data();
		$challenge = Helpers::base64url_decode( $data['publicKey']['challenge'] );

		[ $client_data, $auth_data, $signature ] = $this->auth->assert( $challenge, 'https://evil.' . Helpers::default_rp_id() );
		$verify                                  = $this->rest(
			'POST',
			'login/verify',
			[
				'challengeId' => $data['challengeId'],
				'credential'  => [
					'id'       => Helpers::base64url_encode( $this->auth->credential_id ),
					'rawId'    => Helpers::base64url_encode( $this->auth->credential_id ),
					'type'     => 'public-key',
					'response' => [
						'clientDataJSON'    => Helpers::base64url_encode( $client_data ),
						'authenticatorData' => Helpers::base64url_encode( $auth_data ),
						'signature'         => Helpers::base64url_encode( $signature ),
						'userHandle'        => null,
					],
				],
			]
		);
		$this->assertSame( 400, $verify->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_renaming_and_removing_a_passkey(): void {
		$credential = $this->register_passkey( 'Phone' );
		$id         = (int) $credential['id'];

		$renamed = $this->rest( 'POST', 'credentials/' . $id, [ 'name' => 'Work phone' ] );
		$this->assertSame( 200, $renamed->get_status() );
		$this->assertSame( 'Work phone', $renamed->get_data()['credential']['name'] );

		$other = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $other );
		$this->assertContains( $this->rest( 'DELETE', 'credentials/' . $id )->get_status(), [ 403, 404 ], 'another member cannot remove it' );
		$this->assertSame( 1, $this->plugin()->credentials()->count_for_user( $this->user_id ) );

		wp_set_current_user( $this->user_id );
		$this->assertSame( 200, $this->rest( 'DELETE', 'credentials/' . $id )->get_status() );
		$this->assertSame( 0, $this->plugin()->credentials()->count_for_user( $this->user_id ) );
	}
}
