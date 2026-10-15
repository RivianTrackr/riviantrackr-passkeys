<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence for registered passkey credentials.
 */
final class CredentialStore {

	private string $table;

	public function __construct() {
		$this->table = Installer::table_name();
	}

	/**
	 * Look up a credential by its raw (binary) credential ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_credential_id( string $binary_id ): ?array {
		global $wpdb;
		$hash = hash( 'sha256', $binary_id );
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE credential_hash = %s LIMIT 1", $hash ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function find_for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY created_at ASC, id ASC", $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return array_map( [ $this, 'cast' ], is_array( $rows ) ? $rows : [] );
	}

	public function count_for_user( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE user_id = %d", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @return array{credentials:int,users:int}
	 */
	public function totals(): array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT COUNT(*) AS credentials, COUNT(DISTINCT user_id) AS users FROM {$this->table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [
			'credentials' => (int) ( $row['credentials'] ?? 0 ),
			'users'       => (int) ( $row['users'] ?? 0 ),
		];
	}

	/**
	 * Insert a new credential and return the stored row.
	 *
	 * @param array<string,mixed> $data Credential fields (credential_id_binary, public_key, …).
	 * @return array<string,mixed>|null
	 */
	public function insert( array $data ): ?array {
		global $wpdb;
		$binary_id = (string) $data['credential_id_binary'];
		$ok        = $wpdb->insert(
			$this->table,
			[
				'user_id'            => (int) $data['user_id'],
				'credential_hash'    => hash( 'sha256', $binary_id ),
				'credential_id'      => Helpers::base64url_encode( $binary_id ),
				'public_key'         => (string) $data['public_key'],
				'sign_count'         => (int) ( $data['sign_count'] ?? 0 ),
				'aaguid'             => $data['aaguid'] ?? null,
				'transports'         => ! empty( $data['transports'] ) ? implode( ',', (array) $data['transports'] ) : null,
				'name'               => (string) ( $data['name'] ?? '' ),
				'attestation_format' => (string) ( $data['attestation_format'] ?? 'none' ),
				'backup_eligible'    => ! empty( $data['backup_eligible'] ) ? 1 : 0,
				'backed_up'          => ! empty( $data['backed_up'] ) ? 1 : 0,
				'created_at'         => Helpers::now(),
				'last_used_at'       => null,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
		if ( false === $ok ) {
			return null;
		}
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Record a successful login: bump the signature counter and timestamp.
	 */
	public function touch( int $id, int $sign_count, bool $backed_up ): void {
		global $wpdb;
		$wpdb->update(
			$this->table,
			[
				'sign_count'   => $sign_count,
				'last_used_at' => Helpers::now(),
				'backed_up'    => $backed_up ? 1 : 0,
			],
			[ 'id' => $id ],
			[ '%d', '%s', '%d' ],
			[ '%d' ]
		);
	}

	public function rename( int $id, int $user_id, string $name ): bool {
		global $wpdb;
		$updated = $wpdb->update(
			$this->table,
			[ 'name' => $name ],
			[
				'id'      => $id,
				'user_id' => $user_id,
			],
			[ '%s' ],
			[ '%d', '%d' ]
		);
		return false !== $updated;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
	}

	public function delete_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table, [ 'user_id' => $user_id ], [ '%d' ] );
	}

	/**
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>
	 */
	private function cast( array $row ): array {
		$row['id']              = (int) $row['id'];
		$row['user_id']         = (int) $row['user_id'];
		$row['sign_count']      = (int) $row['sign_count'];
		$row['backup_eligible'] = (bool) $row['backup_eligible'];
		$row['backed_up']       = (bool) $row['backed_up'];
		$row['transports']      = ! empty( $row['transports'] ) ? explode( ',', (string) $row['transports'] ) : [];
		return $row;
	}
}
