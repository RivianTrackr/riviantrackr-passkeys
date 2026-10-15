<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\PendingSignupStore;

/**
 * In-memory stand-in for the wp_riviantrackr_pending_signups table.
 */
final class FakePendingSignupStore extends PendingSignupStore {

	/** @var array<int,array<string,mixed>> */
	public array $rows = [];
	private int $next  = 1;

	public function __construct() {
		$this->table = 'wp_riviantrackr_pending_signups';
	}

	public function insert( array $data ): int {
		$id                = $this->next++;
		$this->rows[ $id ] = [
			'id'           => $id,
			'token_hash'   => (string) $data['token_hash'],
			'email'        => (string) $data['email'],
			'display_name' => (string) ( $data['display_name'] ?? '' ),
			'ip_hash'      => (string) ( $data['ip_hash'] ?? '' ),
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'expires_at'   => (string) $data['expires_at'],
		];
		return $id;
	}

	public function find_by_hash( string $token_hash ): ?array {
		foreach ( $this->rows as $row ) {
			if ( $row['token_hash'] === $token_hash ) {
				return $row;
			}
		}
		return null;
	}

	public function delete( int $id ): void {
		unset( $this->rows[ $id ] );
	}

	public function delete_for_email( string $email ): void {
		foreach ( $this->rows as $id => $row ) {
			if ( $row['email'] === $email ) {
				unset( $this->rows[ $id ] );
			}
		}
	}

	public function count_active(): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		return count( array_filter( $this->rows, fn( $r ) => $r['expires_at'] > $now ) );
	}

	public function purge_expired(): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $this->rows as $id => $row ) {
			if ( $row['expires_at'] <= $now ) {
				unset( $this->rows[ $id ] );
			}
		}
	}
}
