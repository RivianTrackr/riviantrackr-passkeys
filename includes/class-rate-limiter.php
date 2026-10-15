<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed-window rate limiter backed by transients.
 */
final class RateLimiter {

	private const PREFIX = 'riviantrackr_pk_rl_';

	/**
	 * Count one hit and report whether the caller is now over the limit.
	 *
	 * @param string $scope    Logical bucket, e.g. "login".
	 * @param string $identity IP, user ID or other identity.
	 * @param int    $limit    Maximum hits per window.
	 * @param int    $window   Window length in seconds.
	 */
	public function is_limited( string $scope, string $identity, int $limit, int $window = 60 ): bool {
		$key  = self::PREFIX . substr( hash( 'sha256', $scope . '|' . $identity ), 0, 32 );
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || (int) ( $data['reset'] ?? 0 ) <= $now ) {
			$data = [
				'count' => 0,
				'reset' => $now + $window,
			];
		}

		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, max( 1, (int) $data['reset'] - $now ) );

		return $data['count'] > $limit;
	}
}
