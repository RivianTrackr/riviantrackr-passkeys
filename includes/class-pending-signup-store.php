<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows in wp_riviantrackr_pending_signups: an email address waiting for
 * its verification link to be clicked. No WordPress user exists yet.
 *
 * Not final so tests can substitute an in-memory implementation.
 */
class PendingSignupStore {

	protected string $table;

	public function __construct() {
		$this->table = Installer::pending_signups_table();
	}

	/**
	 * @param array{token_hash:string,email:string,display_name:string,ip_hash:string,expires_at:string} $data Row data.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			$this->table,
			[
				'token_hash'   => (string) $data['token_hash'],
				'email'        => (string) $data['email'],
				'display_name' => (string) ( $data['display_name'] ?? '' ),
				'ip_hash'      => (string) ( $data['ip_hash'] ?? '' ),
				'created_at'   => Helpers::now(),
				'expires_at'   => (string) $data['expires_at'],
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_by_hash( string $token_hash ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE token_hash = %s", $token_hash ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $this->cast( $row ) : null;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Remove every pending row for an address (a new request replaces the old link).
	 */
	public function delete_for_email( string $email ): void {
		global $wpdb;
		$wpdb->delete( $this->table, [ 'email' => $email ], [ '%s' ] );
	}

	/**
	 * Unexpired rows waiting for a click.
	 */
	public function count_active(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE expires_at > %s", Helpers::now() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function purge_expired(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table} WHERE expires_at <= %s", Helpers::now() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	protected function cast( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'token_hash'   => (string) $row['token_hash'],
			'email'        => (string) $row['email'],
			'display_name' => (string) $row['display_name'],
			'ip_hash'      => (string) $row['ip_hash'],
			'created_at'   => (string) $row['created_at'],
			'expires_at'   => (string) $row['expires_at'],
		];
	}
}
