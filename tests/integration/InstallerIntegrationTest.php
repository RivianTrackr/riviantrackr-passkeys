<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\Installer;

/**
 * Activation side effects against the real database and cron.
 */
final class InstallerIntegrationTest extends IntegrationTestCase {

	public function test_tables_exist_after_activation(): void {
		global $wpdb;
		foreach ( [ Installer::table_name(), Installer::pending_signups_table() ] as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "{$table} exists" );
		}
		$this->assertSame( RTPK_DB_VERSION, (string) get_option( Installer::DB_VERSION_OPTION ) );
	}

	public function test_the_daily_purge_is_scheduled_and_wired(): void {
		$this->assertNotFalse( wp_next_scheduled( Installer::PURGE_EVENT ), 'scheduled on activation' );
		$this->assertNotFalse( has_action( Installer::PURGE_EVENT ), 'the plugin listens for it' );

		Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( Installer::PURGE_EVENT ), 'deactivation clears it' );

		Installer::maybe_upgrade();
		$this->assertNotFalse( wp_next_scheduled( Installer::PURGE_EVENT ), 'an upgraded site without the event gets it back' );
	}

	public function test_running_the_purge_drops_only_expired_pending_signups(): void {
		global $wpdb;
		$table = Installer::pending_signups_table();
		$wpdb->insert(
			$table,
			[
				'token_hash'   => str_repeat( 'a', 64 ),
				'email'        => 'old@example.org',
				'display_name' => '',
				'ip_hash'      => '',
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			]
		);
		$wpdb->insert(
			$table,
			[
				'token_hash'   => str_repeat( 'b', 64 ),
				'email'        => 'fresh@example.org',
				'display_name' => '',
				'ip_hash'      => '',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		do_action( Installer::PURGE_EVENT );

		$emails = $wpdb->get_col( "SELECT email FROM {$table} ORDER BY email" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( [ 'fresh@example.org' ], $emails );
	}
}
