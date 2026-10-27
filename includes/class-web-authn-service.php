<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, opinionated wrapper around lbuchs\WebAuthn.
 *
 * Builds the options handed to navigator.credentials.create()/get() and
 * verifies the responses. All binary values are base64url in JSON.
 */
final class WebAuthnService {

	public const USER_HANDLE_META = 'riviantrackr_passkeys_user_handle';

	private const FORMATS = [ 'none', 'packed', 'apple', 'android-key', 'android-safetynet', 'fido-u2f', 'tpm' ];

	public function __construct(
		private Settings $settings,
		private CredentialStore $store
	) {}

	private function client(): WebAuthn {
		return new WebAuthn( $this->settings->rp_name(), $this->settings->rp_id(), self::FORMATS, true );
	}

	/**
	 * Options for navigator.credentials.create().
	 *
	 * @return array{options:array<string,mixed>,challenge:string}
	 */
	public function registration_options( \WP_User $user ): array {
		$client  = $this->client();
		$exclude = [];
		foreach ( $this->store->find_for_user( $user->ID ) as $credential ) {
			$binary = Helpers::base64url_decode( (string) $credential['credential_id'] );
			if ( null !== $binary ) {
				$exclude[] = $binary;
			}
		}

		$args = $client->getCreateArgs(
			$this->user_handle( $user ),
			$user->user_login,
			'' !== $user->display_name ? $user->display_name : $user->user_login,
			$this->settings->timeout(),
			'required',                                  // Discoverable credential = a real passkey.
			$this->settings->user_verification(),
			null,
			$exclude
		);

		// Passkeys do not need attestation; asking for none avoids consent prompts.
		$args->publicKey->attestation           = 'none';
		$args->publicKey->extensions            = new \stdClass();
		$args->publicKey->extensions->credProps = true;

		$options = json_decode( (string) wp_json_encode( $args ), true );

		return [
			'options'   => is_array( $options ) ? $options : [],
			'challenge' => $client->getChallenge()->getBinaryString(),
		];
	}

	/**
	 * Verify a registration response.
	 *
	 * @return array<string,mixed> Normalised credential data ready to store.
	 * @throws WebAuthnException When the response fails verification.
	 */
	public function verify_registration( string $client_data_json, string $attestation_object, string $challenge ): array {
		$this->assert_origin( $client_data_json );

		$client = $this->client();
		$data   = $client->processCreate(
			$client_data_json,
			$attestation_object,
			$challenge,
			$this->settings->requires_user_verification(),
			true,   // User presence is always required.
			false,  // No root certificates configured.
			false   // Do not require Google CTS profile match.
		);

		return [
			'credential_id_binary' => $this->to_binary( $data->credentialId ),
			'public_key'           => (string) $data->credentialPublicKey,
			'sign_count'           => (int) ( $data->signatureCounter ?? 0 ),
			'aaguid'               => $this->format_aaguid( $this->to_binary( $data->AAGUID ) ),
			'attestation_format'   => (string) ( $data->attestationFormat ?? 'none' ),
			'backup_eligible'      => (bool) ( $data->isBackupEligible ?? false ),
			'backed_up'            => (bool) ( $data->isBackedUp ?? false ),
			'user_verified'        => (bool) ( $data->userVerified ?? false ),
		];
	}

	/**
	 * Options for navigator.credentials.get(). allowCredentials is left empty
	 * so the authenticator offers its discoverable credentials.
	 *
	 * @return array{options:array<string,mixed>,challenge:string}
	 */
	public function login_options(): array {
		$client = $this->client();
		$args   = $client->getGetArgs(
			[],
			$this->settings->timeout(),
			true,
			true,
			true,
			true,
			true,
			$this->settings->user_verification()
		);

		$options = json_decode( (string) wp_json_encode( $args ), true );

		return [
			'options'   => is_array( $options ) ? $options : [],
			'challenge' => $client->getChallenge()->getBinaryString(),
		];
	}

	/**
	 * Verify an assertion. user_verified is the authenticator's UV flag: the
	 * device confirmed the person (biometric or PIN), not just a tap.
	 *
	 * @return array{sign_count:int,backed_up:bool,user_verified:bool}
	 * @throws WebAuthnException When the response fails verification.
	 */
	public function verify_login(
		string $client_data_json,
		string $authenticator_data,
		string $signature,
		string $public_key_pem,
		string $challenge,
		int $previous_sign_count
	): array {
		$this->assert_origin( $client_data_json );

		$client = $this->client();
		$client->processGet(
			$client_data_json,
			$authenticator_data,
			$signature,
			$public_key_pem,
			$challenge,
			$previous_sign_count,
			$this->settings->requires_user_verification(),
			true
		);

		$auth_data = new \lbuchs\WebAuthn\Attestation\AuthenticatorData( $authenticator_data );

		return [
			'sign_count'    => (int) $auth_data->getSignCount(),
			'backed_up'     => (bool) $auth_data->getIsBackup(),
			'user_verified' => (bool) $auth_data->getUserVerified(),
		];
	}

	/**
	 * Strict origin check: clientDataJSON.origin must exactly match one of
	 * the configured origins. This is tighter than the library's suffix match.
	 *
	 * @throws WebAuthnException When the response fails verification.
	 */
	public function assert_origin( string $client_data_json ): void {
		$client_data = json_decode( $client_data_json, true );
		$origin      = is_array( $client_data ) ? (string) ( $client_data['origin'] ?? '' ) : '';
		$normalized  = Helpers::normalize_origin( $origin );

		if ( null === $normalized || ! in_array( $normalized, $this->settings->allowed_origins(), true ) ) {
			throw new WebAuthnException( 'invalid origin', WebAuthnException::INVALID_ORIGIN ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal, not output.
		}
	}

	/**
	 * Stable, opaque 32-byte user handle (never the username or numeric ID).
	 * Stored base64url in user meta; returned as binary.
	 */
	public function user_handle( \WP_User $user ): string {
		$stored = (string) get_user_meta( $user->ID, self::USER_HANDLE_META, true );
		$binary = '' !== $stored ? Helpers::base64url_decode( $stored ) : null;

		if ( null === $binary || 32 !== strlen( $binary ) ) {
			$binary = random_bytes( 32 );
			update_user_meta( $user->ID, self::USER_HANDLE_META, Helpers::base64url_encode( $binary ) );
		}

		return $binary;
	}

	/**
	 * Translate a library error into a user-facing message.
	 */
	public static function describe_error( WebAuthnException $e ): string {
		switch ( $e->getCode() ) {
			case WebAuthnException::INVALID_CHALLENGE:
				return __( 'This sign-in request has expired. Please try again.', 'riviantrackr-passkeys' );
			case WebAuthnException::INVALID_ORIGIN:
			case WebAuthnException::INVALID_RELYING_PARTY:
				return __( 'This passkey was created for a different site address.', 'riviantrackr-passkeys' );
			case WebAuthnException::USER_VERIFICATED:
				return __( 'Your device did not verify you (Face ID, Touch ID, PIN or fingerprint). Please try again.', 'riviantrackr-passkeys' );
			case WebAuthnException::USER_PRESENT:
				return __( 'Your device did not confirm your presence. Please try again.', 'riviantrackr-passkeys' );
			case WebAuthnException::SIGNATURE_COUNTER:
				return __( 'This passkey failed a security check and was rejected. If this keeps happening, remove and re-add it.', 'riviantrackr-passkeys' );
			case WebAuthnException::INVALID_SIGNATURE:
				return __( 'The passkey signature could not be verified.', 'riviantrackr-passkeys' );
			default:
				return __( 'The passkey response could not be verified.', 'riviantrackr-passkeys' );
		}
	}

	/**
	 * @param mixed $value ByteBuffer or binary string.
	 */
	private function to_binary( $value ): string {
		if ( $value instanceof ByteBuffer ) {
			return $value->getBinaryString();
		}
		return (string) $value;
	}

	private function format_aaguid( string $binary ): ?string {
		if ( 16 !== strlen( $binary ) ) {
			return null;
		}
		$hex = bin2hex( $binary );
		if ( '00000000000000000000000000000000' === $hex ) {
			return null;
		}
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
