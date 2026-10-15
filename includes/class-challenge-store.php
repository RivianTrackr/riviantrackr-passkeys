<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single-use, short-lived WebAuthn challenges backed by transients.
 *
 * Each challenge is addressed by an opaque random token handed to the
 * browser, so no PHP session is required and the challenge can never be
 * chosen by the client.
 */
final class ChallengeStore {

	public const TYPE_REGISTER = 'register';
	public const TYPE_LOGIN    = 'login';

	private const PREFIX = 'riviantrackr_pk_ch_';

	/**
	 * Store a challenge and return the token that references it.
	 */
	public function create( string $type, string $challenge, int $user_id = 0 ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::PREFIX . $token,
			[
				'type'      => $type,
				'challenge' => bin2hex( $challenge ),
				'user_id'   => $user_id,
				'created'   => time(),
			],
			RTPK_CHALLENGE_TTL
		);
		return $token;
	}

	/**
	 * Fetch and immediately invalidate a challenge.
	 *
	 * @return string|null Binary challenge, or null when missing/expired/mismatched.
	 */
	public function consume( string $token, string $type, int $user_id = 0 ): ?string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$key  = self::PREFIX . $token;
		$data = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( ( $data['type'] ?? '' ) !== $type || (int) ( $data['user_id'] ?? -1 ) !== $user_id ) {
			return null;
		}
		if ( time() - (int) ( $data['created'] ?? 0 ) > RTPK_CHALLENGE_TTL ) {
			return null;
		}
		$challenge = hex2bin( (string) ( $data['challenge'] ?? '' ) );
		return is_string( $challenge ) && '' !== $challenge ? $challenge : null;
	}
}
