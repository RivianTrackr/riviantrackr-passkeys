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
	}
}
