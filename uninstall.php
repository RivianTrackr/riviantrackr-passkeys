<?php
/**
 * Uninstall handler for RivianTrackr Passkeys.
 *
 * Removes the credentials and pending-signup tables, settings, transients and user handles
 * unless "Keep passkeys when the plugin is deleted" is enabled.
 *
 * @package RivianTrackr_Passkeys
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$rtpk_options = get_option( 'riviantrackr_passkeys_options', [] );

// Challenges and rate-limit buckets are always safe to purge.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_riviantrackr_pk_%'
	    OR option_name LIKE '_transient_timeout_riviantrackr_pk_%'"
);

if ( ! empty( $rtpk_options['preserve_data'] ) ) {
	return;
}

delete_option( 'riviantrackr_passkeys_options' );
delete_option( 'riviantrackr_passkeys_invite_options' );
delete_option( 'riviantrackr_passkeys_signup_options' );
delete_option( 'riviantrackr_passkeys_db_version' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'riviantrackr_passkeys_user_handle' ) );

$rtpk_table = $wpdb->prefix . 'riviantrackr_passkeys';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rtpk_table ) );

$rtpk_pending = $wpdb->prefix . 'riviantrackr_pending_signups';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rtpk_pending ) );

wp_cache_flush();
