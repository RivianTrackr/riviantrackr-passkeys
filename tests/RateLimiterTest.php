<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\RateLimiter;

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		rtpk_test_reset();
	}

	public function test_allows_up_to_limit_then_blocks(): void {
		$limiter = new RateLimiter();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $limiter->is_limited( 'login', '1.2.3.4', 5 ), "hit $i should pass" );
		}
		$this->assertTrue( $limiter->is_limited( 'login', '1.2.3.4', 5 ) );
		$this->assertTrue( $limiter->is_limited( 'login', '1.2.3.4', 5 ) );
	}

	public function test_scopes_and_identities_are_independent(): void {
		$limiter = new RateLimiter();
		$this->assertFalse( $limiter->is_limited( 'login', '1.2.3.4', 1 ) );
		$this->assertTrue( $limiter->is_limited( 'login', '1.2.3.4', 1 ) );
		$this->assertFalse( $limiter->is_limited( 'login', '5.6.7.8', 1 ) );
		$this->assertFalse( $limiter->is_limited( 'register', '1.2.3.4', 1 ) );
	}

	public function test_window_resets(): void {
		$limiter = new RateLimiter();
		$this->assertFalse( $limiter->is_limited( 'login', '1.2.3.4', 1, 60 ) );
		$this->assertTrue( $limiter->is_limited( 'login', '1.2.3.4', 1, 60 ) );

		// Force the window to expire.
		foreach ( $GLOBALS['rtpk_test_transients'] as $key => &$row ) {
			$row['value']['reset'] = time() - 1;
		}
		unset( $row );

		$this->assertFalse( $limiter->is_limited( 'login', '1.2.3.4', 1, 60 ) );
	}
}
