<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\Settings;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		rtpk_test_reset();
	}

	public function test_defaults_derive_from_site(): void {
		$settings = new Settings();
		$this->assertSame( 'RivianTrackr', $settings->rp_name() );
		$this->assertSame( 'riviantrackr.test', $settings->rp_id() );
		$this->assertSame( 'preferred', $settings->user_verification() );
		$this->assertFalse( $settings->requires_user_verification() );
		$this->assertSame( 60, $settings->timeout() );
		$this->assertSame( [ 'https://riviantrackr.test' ], $settings->allowed_origins() );
	}

	public function test_sanitize_clamps_and_validates(): void {
		$settings = new Settings();
		$out      = $settings->sanitize(
			[
				'rp_name'           => '  <b>Rivian</b>Trackr ',
				'rp_id'             => 'evil.com',
				'user_verification' => 'bogus',
				'timeout'           => '9999',
				'allowed_origins'   => "https://App.RivianTrackr.test/\nnot-a-url\nhttps://app.riviantrackr.test",
				'login_button'      => '1',
			]
		);

		$this->assertSame( 'RivianTrackr', $out['rp_name'] );
		$this->assertSame( '', $out['rp_id'], 'invalid RP ID is reset' );
		$this->assertNotEmpty( $GLOBALS['rtpk_test_errors'] );
		$this->assertSame( 'preferred', $out['user_verification'] );
		$this->assertSame( 300, $out['timeout'] );
		$this->assertSame( 'https://app.riviantrackr.test', $out['allowed_origins'] );
		$this->assertTrue( $out['login_button'] );
		$this->assertFalse( $out['conditional_ui'] );
		$this->assertFalse( $out['preserve_data'] );
	}

	public function test_sanitize_accepts_parent_domain_rp_id(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [ 'rp_id' => 'RivianTrackr.test', 'timeout' => 10 ] );
		$this->assertSame( 'riviantrackr.test', $out['rp_id'] );
		$this->assertSame( 30, $out['timeout'] );
		$this->assertEmpty( $GLOBALS['rtpk_test_errors'] );
	}

	public function test_allowed_origins_merge_site_and_extras(): void {
		update_option( RTPK_OPTION_NAME, [ 'allowed_origins' => "https://app.riviantrackr.test\nhttps://riviantrackr.test" ] );
		$settings = new Settings();
		$this->assertSame( [ 'https://riviantrackr.test', 'https://app.riviantrackr.test' ], $settings->allowed_origins() );
	}
}
