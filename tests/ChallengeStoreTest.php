<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\ChallengeStore;

final class ChallengeStoreTest extends TestCase {

	protected function setUp(): void {
		rtpk_test_reset();
	}

	public function test_create_returns_opaque_token_and_consume_returns_challenge(): void {
		$store     = new ChallengeStore();
		$challenge = random_bytes( 32 );
		$token     = $store->create( ChallengeStore::TYPE_LOGIN, $challenge );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
		$this->assertSame( $challenge, $store->consume( $token, ChallengeStore::TYPE_LOGIN ) );
	}

	public function test_challenge_is_single_use(): void {
		$store = new ChallengeStore();
		$token = $store->create( ChallengeStore::TYPE_LOGIN, random_bytes( 32 ) );

		$this->assertNotNull( $store->consume( $token, ChallengeStore::TYPE_LOGIN ) );
		$this->assertNull( $store->consume( $token, ChallengeStore::TYPE_LOGIN ) );
	}

	public function test_type_and_user_must_match(): void {
		$store = new ChallengeStore();

		$token = $store->create( ChallengeStore::TYPE_REGISTER, random_bytes( 32 ), 7 );
		$this->assertNull( $store->consume( $token, ChallengeStore::TYPE_LOGIN, 7 ), 'wrong type' );

		$token = $store->create( ChallengeStore::TYPE_REGISTER, random_bytes( 32 ), 7 );
		$this->assertNull( $store->consume( $token, ChallengeStore::TYPE_REGISTER, 8 ), 'wrong user' );

		$token = $store->create( ChallengeStore::TYPE_REGISTER, random_bytes( 32 ), 7 );
		$this->assertNotNull( $store->consume( $token, ChallengeStore::TYPE_REGISTER, 7 ) );
	}

	public function test_malformed_tokens_are_rejected_without_lookup(): void {
		$store = new ChallengeStore();
		$this->assertNull( $store->consume( '', ChallengeStore::TYPE_LOGIN ) );
		$this->assertNull( $store->consume( '../../etc', ChallengeStore::TYPE_LOGIN ) );
		$this->assertNull( $store->consume( str_repeat( 'z', 32 ), ChallengeStore::TYPE_LOGIN ) );
	}

	public function test_expired_challenge_is_rejected(): void {
		$store = new ChallengeStore();
		$token = $store->create( ChallengeStore::TYPE_LOGIN, random_bytes( 32 ) );

		// Age the stored record past the TTL.
		$key = 'riviantrackr_pk_ch_' . $token;
		$GLOBALS['rtpk_test_transients'][ $key ]['value']['created'] = time() - RTPK_CHALLENGE_TTL - 1;

		$this->assertNull( $store->consume( $token, ChallengeStore::TYPE_LOGIN ) );
	}
}
