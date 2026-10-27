<?php
declare(strict_types=1);

use lbuchs\WebAuthn\WebAuthnException;
use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\CredentialStore;
use RivianTrackr\Passkeys\Settings;
use RivianTrackr\Passkeys\WebAuthnService;

/**
 * Drives the real verification path with a software authenticator.
 */
final class WebAuthnServiceTest extends TestCase {

	private const ORIGIN = 'https://riviantrackr.test';
	private const RP_ID  = 'riviantrackr.test';

	private Settings $settings;
	private WebAuthnService $service;
	private WP_User $user;

	protected function setUp(): void {
		rtpk_test_reset();
		$this->settings = new Settings();
		$this->service  = new WebAuthnService( $this->settings, new CredentialStore() );
		$this->user     = new WP_User( 42, 'jose', 'Jose' );
	}

	/* ------------------------------------------------------------ options */

	public function test_registration_options_describe_a_discoverable_passkey(): void {
		$built = $this->service->registration_options( $this->user );
		$pk    = $built['options']['publicKey'];

		$this->assertSame( 32, strlen( $built['challenge'] ) );
		$this->assertSame( FakeAuthenticator::b64url( $built['challenge'] ), $pk['challenge'] );
		$this->assertSame( self::RP_ID, $pk['rp']['id'] );
		$this->assertSame( 'RivianTrackr', $pk['rp']['name'] );
		$this->assertSame( 'jose', $pk['user']['name'] );
		$this->assertSame( 'Jose', $pk['user']['displayName'] );
		$this->assertSame( 'required', $pk['authenticatorSelection']['residentKey'] );
		$this->assertTrue( $pk['authenticatorSelection']['requireResidentKey'] );
		$this->assertSame( 'preferred', $pk['authenticatorSelection']['userVerification'] );
		$this->assertSame( 'none', $pk['attestation'] );
		$this->assertSame( 60000, $pk['timeout'] );
		$this->assertContains( -7, array_column( $pk['pubKeyCredParams'], 'alg' ), 'ES256 offered' );
		$this->assertContains( -257, array_column( $pk['pubKeyCredParams'], 'alg' ), 'RS256 offered' );
		$this->assertTrue( $pk['extensions']['credProps'] );
	}

	public function test_user_handle_is_opaque_stable_and_32_bytes(): void {
		$first  = $this->service->user_handle( $this->user );
		$second = $this->service->user_handle( $this->user );

		$this->assertSame( 32, strlen( $first ) );
		$this->assertSame( $first, $second );
		$this->assertStringNotContainsString( 'jose', $first );
		$this->assertNotSame( $first, $this->service->user_handle( new WP_User( 43, 'jose', 'Jose' ) ), 'not derived from the login alone' );
		$this->assertNotSame( str_pad( '42', 32, "\0" ), $first, 'not the user ID' );

		$built = $this->service->registration_options( $this->user );
		$this->assertSame( FakeAuthenticator::b64url( $first ), $built['options']['publicKey']['user']['id'] );
	}

	public function test_login_options_leave_allow_credentials_empty(): void {
		$built = $this->service->login_options();
		$pk    = $built['options']['publicKey'];

		$this->assertSame( 32, strlen( $built['challenge'] ) );
		$this->assertSame( self::RP_ID, $pk['rpId'] );
		$this->assertSame( 'preferred', $pk['userVerification'] );
		$this->assertArrayNotHasKey( 'allowCredentials', $pk );
	}

	/* ------------------------------------------------------- registration */

	public function test_registration_round_trip_produces_usable_public_key(): void {
		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );

		[ $client_data, $attestation ] = $auth->register( $built['challenge'], self::ORIGIN );
		$verified                       = $this->service->verify_registration( $client_data, $attestation, $built['challenge'] );

		$this->assertSame( $auth->credential_id, $verified['credential_id_binary'] );
		$this->assertStringContainsString( 'BEGIN PUBLIC KEY', $verified['public_key'] );
		$this->assertNotFalse( openssl_pkey_get_public( $verified['public_key'] ) );
		$this->assertSame( 'none', $verified['attestation_format'] );
		$this->assertNull( $verified['aaguid'], 'all-zero AAGUID is normalised to null' );
		$this->assertTrue( $verified['user_verified'] );
		$this->assertFalse( $verified['backed_up'] );
	}

	public function test_registration_records_backup_flags(): void {
		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );
		$flags = FakeAuthenticator::FLAG_UP | FakeAuthenticator::FLAG_UV | FakeAuthenticator::FLAG_AT | FakeAuthenticator::FLAG_BE | FakeAuthenticator::FLAG_BS;

		[ $client_data, $attestation ] = $auth->register( $built['challenge'], self::ORIGIN, $flags );
		$verified                       = $this->service->verify_registration( $client_data, $attestation, $built['challenge'] );

		$this->assertTrue( $verified['backup_eligible'] );
		$this->assertTrue( $verified['backed_up'] );
	}

	public function test_registration_rejects_wrong_challenge(): void {
		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );

		[ $client_data, $attestation ] = $auth->register( random_bytes( 32 ), self::ORIGIN );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::INVALID_CHALLENGE );
		$this->service->verify_registration( $client_data, $attestation, $built['challenge'] );
	}

	public function test_registration_rejects_lookalike_origin_even_though_suffix_matches(): void {
		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );

		// The bundled library would accept this (suffix match); our exact-origin check must not.
		[ $client_data, $attestation ] = $auth->register( $built['challenge'], 'https://evilriviantrackr.test' );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::INVALID_ORIGIN );
		$this->service->verify_registration( $client_data, $attestation, $built['challenge'] );
	}

	public function test_registration_rejects_subdomain_origin_unless_allowlisted(): void {
		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );
		[ $client_data, $attestation ] = $auth->register( $built['challenge'], 'https://app.riviantrackr.test' );

		try {
			$this->service->verify_registration( $client_data, $attestation, $built['challenge'] );
			$this->fail( 'subdomain origin should be rejected by default' );
		} catch ( WebAuthnException $e ) {
			$this->assertSame( WebAuthnException::INVALID_ORIGIN, $e->getCode() );
		}

		update_option( RTPK_OPTION_NAME, [ 'allowed_origins' => 'https://app.riviantrackr.test' ] );
		$this->settings->flush();

		$verified = $this->service->verify_registration( $client_data, $attestation, $built['challenge'] );
		$this->assertSame( $auth->credential_id, $verified['credential_id_binary'] );
	}

	public function test_registration_rejects_http_origin(): void {
		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );
		[ $client_data, $attestation ] = $auth->register( $built['challenge'], 'http://riviantrackr.test' );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::INVALID_ORIGIN );
		$this->service->verify_registration( $client_data, $attestation, $built['challenge'] );
	}

	public function test_registration_requires_user_verification_when_configured(): void {
		update_option( RTPK_OPTION_NAME, [ 'user_verification' => 'required' ] );
		$this->settings->flush();

		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );
		$this->assertSame( 'required', $built['options']['publicKey']['authenticatorSelection']['userVerification'] );

		[ $client_data, $attestation ] = $auth->register( $built['challenge'], self::ORIGIN, FakeAuthenticator::FLAG_UP | FakeAuthenticator::FLAG_AT );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::USER_VERIFICATED );
		$this->service->verify_registration( $client_data, $attestation, $built['challenge'] );
	}

	/* -------------------------------------------------------------- login */

	private function registered_authenticator(): array {
		$auth  = new FakeAuthenticator( self::RP_ID );
		$built = $this->service->registration_options( $this->user );
		[ $client_data, $attestation ] = $auth->register( $built['challenge'], self::ORIGIN );
		$verified = $this->service->verify_registration( $client_data, $attestation, $built['challenge'] );
		return [ $auth, $verified['public_key'] ];
	}

	public function test_login_round_trip(): void {
		[ $auth, $pem ] = $this->registered_authenticator();

		$built = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN );

		$result = $this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
		$this->assertSame( 1, $result['sign_count'] );
		$this->assertFalse( $result['backed_up'] );
		$this->assertTrue( $result['user_verified'], 'the device confirmed the person' );

		// A second login with a higher counter succeeds.
		$built = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN );
		$result = $this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 1 );
		$this->assertSame( 2, $result['sign_count'] );
	}

	public function test_login_rejects_signature_from_another_key(): void {
		[ , $pem ]  = $this->registered_authenticator();
		$impostor   = new FakeAuthenticator( self::RP_ID );
		$built      = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $impostor->assert( $built['challenge'], self::ORIGIN );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::INVALID_SIGNATURE );
		$this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
	}

	public function test_login_rejects_tampered_client_data(): void {
		[ $auth, $pem ] = $this->registered_authenticator();
		$built          = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN );

		$tampered = str_replace( '"crossOrigin":false', '"crossOrigin":true', $client_data );
		$this->assertNotSame( $client_data, $tampered );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::INVALID_SIGNATURE );
		$this->service->verify_login( $tampered, $auth_data, $signature, $pem, $built['challenge'], 0 );
	}

	public function test_login_rejects_replayed_counter(): void {
		[ $auth, $pem ] = $this->registered_authenticator();
		$built          = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN, FakeAuthenticator::FLAG_UP | FakeAuthenticator::FLAG_UV, 5 );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::SIGNATURE_COUNTER );
		$this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 5 );
	}

	public function test_login_allows_zero_counters_from_synced_passkeys(): void {
		[ $auth, $pem ] = $this->registered_authenticator();
		$built          = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN, FakeAuthenticator::FLAG_UP | FakeAuthenticator::FLAG_UV | FakeAuthenticator::FLAG_BE | FakeAuthenticator::FLAG_BS, 0 );

		$result = $this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
		$this->assertSame( 0, $result['sign_count'] );
		$this->assertTrue( $result['backed_up'] );
	}

	public function test_login_rejects_wrong_rp_id_hash(): void {
		[ , $pem ] = $this->registered_authenticator();
		$other     = new FakeAuthenticator( 'other.test' );
		$built     = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $other->assert( $built['challenge'], self::ORIGIN );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::INVALID_RELYING_PARTY );
		$this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
	}

	public function test_login_reports_a_tap_without_user_verification(): void {
		[ $auth, $pem ] = $this->registered_authenticator();
		$built          = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN, FakeAuthenticator::FLAG_UP );

		$result = $this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
		$this->assertFalse( $result['user_verified'], 'presence only, as from a security key without a PIN' );
	}

	public function test_login_requires_user_presence(): void {
		[ $auth, $pem ] = $this->registered_authenticator();
		$built          = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN, FakeAuthenticator::FLAG_UV );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::USER_PRESENT );
		$this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
	}

	public function test_login_requires_user_verification_when_configured(): void {
		[ $auth, $pem ] = $this->registered_authenticator();

		update_option( RTPK_OPTION_NAME, [ 'user_verification' => 'required' ] );
		$this->settings->flush();

		$built = $this->service->login_options();
		[ $client_data, $auth_data, $signature ] = $auth->assert( $built['challenge'], self::ORIGIN, FakeAuthenticator::FLAG_UP );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::USER_VERIFICATED );
		$this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
	}

	public function test_login_rejects_registration_type_client_data(): void {
		[ $auth, $pem ] = $this->registered_authenticator();
		$built          = $this->service->login_options();
		[ $client_data, $attestation ] = $auth->register( $built['challenge'], self::ORIGIN );
		[ , $auth_data, $signature ]   = $auth->assert( $built['challenge'], self::ORIGIN );

		$this->expectException( WebAuthnException::class );
		$this->expectExceptionCode( WebAuthnException::INVALID_TYPE );
		$this->service->verify_login( $client_data, $auth_data, $signature, $pem, $built['challenge'], 0 );
	}

	public function test_error_messages_are_user_facing(): void {
		$codes = [
			WebAuthnException::INVALID_CHALLENGE,
			WebAuthnException::INVALID_ORIGIN,
			WebAuthnException::USER_VERIFICATED,
			WebAuthnException::SIGNATURE_COUNTER,
			WebAuthnException::INVALID_SIGNATURE,
			999,
		];
		foreach ( $codes as $code ) {
			$message = WebAuthnService::describe_error( new WebAuthnException( 'internal', $code ) );
			$this->assertNotSame( 'internal', $message );
			$this->assertNotSame( '', $message );
		}
	}
}
