<?php
/**
 * A software P-256 authenticator that produces spec-shaped WebAuthn
 * registration and assertion responses for tests.
 */

declare(strict_types=1);

final class FakeAuthenticator {

	public const FLAG_UP = 0x01;
	public const FLAG_UV = 0x04;
	public const FLAG_BE = 0x08;
	public const FLAG_BS = 0x10;
	public const FLAG_AT = 0x40;

	/** @var \OpenSSLAsymmetricKey */
	public $private_key;
	public string $credential_id;
	public string $rp_id;
	public int $counter = 0;

	public function __construct( string $rp_id ) {
		$this->rp_id         = $rp_id;
		$this->credential_id = random_bytes( 32 );
		$key                 = openssl_pkey_new(
			[
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			]
		);
		if ( false === $key ) {
			throw new RuntimeException( 'openssl could not create an EC key' );
		}
		$this->private_key = $key;
	}

	/**
	 * @return array{0:string,1:string} [clientDataJSON, attestationObject] (binary)
	 */
	public function register( string $challenge, string $origin, int $flags = self::FLAG_UP | self::FLAG_UV | self::FLAG_AT ): array {
		$client_data = json_encode(
			[
				'type'        => 'webauthn.create',
				'challenge'   => self::b64url( $challenge ),
				'origin'      => $origin,
				'crossOrigin' => false,
			]
		);

		$auth_data = hash( 'sha256', $this->rp_id, true )
			. chr( $flags )
			. pack( 'N', $this->counter )
			. str_repeat( "\0", 16 )                    // AAGUID
			. pack( 'n', strlen( $this->credential_id ) )
			. $this->credential_id
			. $this->cose_public_key();

		$attestation = "\xa3"
			. "\x63fmt" . "\x64none"
			. "\x67attStmt" . "\xa0"
			. "\x68authData" . self::cbor_bytes( $auth_data );

		return [ (string) $client_data, $attestation ];
	}

	/**
	 * @return array{0:string,1:string,2:string} [clientDataJSON, authenticatorData, signature] (binary)
	 */
	public function assert( string $challenge, string $origin, int $flags = self::FLAG_UP | self::FLAG_UV, ?int $counter = null ): array {
		$client_data = json_encode(
			[
				'type'        => 'webauthn.get',
				'challenge'   => self::b64url( $challenge ),
				'origin'      => $origin,
				'crossOrigin' => false,
			]
		);

		if ( null === $counter ) {
			$this->counter++;
			$counter = $this->counter;
		}

		$auth_data = hash( 'sha256', $this->rp_id, true ) . chr( $flags ) . pack( 'N', $counter );
		$signature = '';
		openssl_sign( $auth_data . hash( 'sha256', (string) $client_data, true ), $signature, $this->private_key, OPENSSL_ALGO_SHA256 );

		return [ (string) $client_data, $auth_data, $signature ];
	}

	private function cose_public_key(): string {
		$details = openssl_pkey_get_details( $this->private_key );
		$x       = str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT );
		$y       = str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );

		// {1: 2 (EC2), 3: -7 (ES256), -1: 1 (P-256), -2: x, -3: y}
		return "\xa5"
			. "\x01\x02"
			. "\x03\x26"
			. "\x20\x01"
			. "\x21\x58\x20" . $x
			. "\x22\x58\x20" . $y;
	}

	private static function cbor_bytes( string $bytes ): string {
		$len = strlen( $bytes );
		if ( $len < 24 ) {
			return chr( 0x40 | $len ) . $bytes;
		}
		if ( $len < 256 ) {
			return "\x58" . chr( $len ) . $bytes;
		}
		return "\x59" . pack( 'n', $len ) . $bytes;
	}

	public static function b64url( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}
}
