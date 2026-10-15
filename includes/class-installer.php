<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the credentials and pending-signups tables.
 */
final class Installer {

	public const DB_VERSION_OPTION = 'riviantrackr_passkeys_db_version';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'riviantrackr_passkeys';
	}

	public static function pending_signups_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'riviantrackr_pending_signups';
	}

	public static function activate(): void {
		self::create_table();
		update_option( self::DB_VERSION_OPTION, RTPK_DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::DB_VERSION_OPTION, '' ) === RTPK_DB_VERSION ) {
			return;
		}
		self::create_table();
		update_option( self::DB_VERSION_OPTION, RTPK_DB_VERSION );
	}

	private static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			credential_hash char(64) NOT NULL,
			credential_id text NOT NULL,
			public_key text NOT NULL,
			sign_count bigint(20) unsigned NOT NULL DEFAULT 0,
			aaguid char(36) DEFAULT NULL,
			transports varchar(191) DEFAULT NULL,
			name varchar(100) NOT NULL DEFAULT '',
			attestation_format varchar(32) NOT NULL DEFAULT 'none',
			backup_eligible tinyint(1) NOT NULL DEFAULT 0,
			backed_up tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			last_used_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY credential_hash (credential_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sql );

		$pending = self::pending_signups_table();
		$sql     = "CREATE TABLE {$pending} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			email varchar(191) NOT NULL,
			display_name varchar(100) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY email (email),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
