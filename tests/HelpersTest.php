<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\Helpers;

final class HelpersTest extends TestCase {

	public function test_base64url_round_trip(): void {
		for ( $i = 1; $i < 40; $i++ ) {
			$bytes   = random_bytes( $i );
			$encoded = Helpers::base64url_encode( $bytes );
			$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $encoded );
			$this->assertSame( $bytes, Helpers::base64url_decode( $encoded ) );
		}
	}

	public function test_base64url_decode_rejects_garbage(): void {
		$this->assertNull( Helpers::base64url_decode( '' ) );
		$this->assertNull( Helpers::base64url_decode( 'not base64!' ) );
		$this->assertNull( Helpers::base64url_decode( 'a' ) );
		$this->assertNull( Helpers::base64url_decode( 'YW+Jj' ) );
	}

	public function test_base64url_decode_accepts_padded_input(): void {
		$this->assertSame( 'hi', Helpers::base64url_decode( 'aGk=' ) );
	}

	public function test_normalize_origin(): void {
		$this->assertSame( 'https://riviantrackr.com', Helpers::normalize_origin( 'HTTPS://RivianTrackr.com/path?x=1' ) );
		$this->assertSame( 'https://riviantrackr.com', Helpers::normalize_origin( 'https://riviantrackr.com:443' ) );
		$this->assertSame( 'https://riviantrackr.com:8443', Helpers::normalize_origin( 'https://riviantrackr.com:8443' ) );
		$this->assertSame( 'http://localhost:8080', Helpers::normalize_origin( 'http://localhost:8080/' ) );
		$this->assertNull( Helpers::normalize_origin( 'riviantrackr.com' ) );
		$this->assertNull( Helpers::normalize_origin( '' ) );
	}

	public function test_rp_id_validation(): void {
		$this->assertTrue( Helpers::is_valid_rp_id( 'riviantrackr.com', 'riviantrackr.com' ) );
		$this->assertTrue( Helpers::is_valid_rp_id( 'riviantrackr.com', 'www.riviantrackr.com' ) );
		$this->assertTrue( Helpers::is_valid_rp_id( 'RivianTrackr.com', 'www.riviantrackr.com' ) );
		$this->assertFalse( Helpers::is_valid_rp_id( 'com', 'www.riviantrackr.com' ) );
		$this->assertFalse( Helpers::is_valid_rp_id( 'trackr.com', 'www.riviantrackr.com' ) );
		$this->assertFalse( Helpers::is_valid_rp_id( 'evil.com', 'riviantrackr.com' ) );
		$this->assertFalse( Helpers::is_valid_rp_id( 'https://riviantrackr.com', 'riviantrackr.com' ) );
		$this->assertFalse( Helpers::is_valid_rp_id( '', 'riviantrackr.com' ) );
	}

	public function test_known_aaguid_labels(): void {
		$this->assertSame( 'iCloud Keychain', Helpers::provider_from_aaguid( 'FBFC3007-154E-4ECC-8C0B-6E020557D7BD' ) );
		$this->assertSame( '', Helpers::provider_from_aaguid( '00000000-0000-0000-0000-000000000000' ) );
		$this->assertSame( '', Helpers::provider_from_aaguid( null ) );
		$this->assertSame( 'Proton Pass', Helpers::provider_from_aaguid( '50726f74-6f6e-5061-7373-50726f746f6e' ) );
		$this->assertSame( 'Security Key by Yubico', Helpers::provider_from_aaguid( 'f8a011f3-8c0a-4d15-8006-17111f9edc7d' ) );
	}

	public function test_authenticator_names_can_be_extended_or_overridden(): void {
		$GLOBALS['rtpk_test_filter_hooks']['riviantrackr_passkeys_authenticator_names'] = static function ( array $known ): array {
			$known['12345678-1234-1234-1234-123456789ABC'] = ' Acme Key ';
			$known['fbfc3007-154e-4ecc-8c0b-6e020557d7bd'] = 'Apple';
			$known['bad-entry']                            = '';
			return $known;
		};
		$this->assertSame( 'Acme Key', Helpers::provider_from_aaguid( '12345678-1234-1234-1234-123456789abc' ), 'keys are lower-cased, labels trimmed' );
		$this->assertSame( 'Apple', Helpers::provider_from_aaguid( 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' ) );
		$this->assertArrayNotHasKey( 'bad-entry', Helpers::authenticator_names(), 'empty labels are dropped' );
	}
}
