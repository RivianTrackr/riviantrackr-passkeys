<?php
/**
 * Uninstall handler for RivianTrackr Passkeys.
 *
 * Removes the credentials and pending-signup tables, settings, transients, the scheduled
 * cleanup and the plugin's user meta (handles, invitations, password flag, avatar
 * pointer, garage; uploaded photos stay in the media library) unless "Keep passkeys when the plugin is deleted" is enabled.
 *
 * @package RivianTrackr_Passkeys
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$rtpk_options = get_option( 'riviantrackr_passkeys_options', [] );

wp_clear_scheduled_hook( 'riviantrackr_passkeys_purge_signups' );

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
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s, %s, %s, %s, %s )",
		'riviantrackr_passkeys_user_handle',
		'riviantrackr_passkeys_invite',
		'riviantrackr_passkeys_invited_at',
		'riviantrackr_passkeys_no_password',
		'riviantrackr_passkeys_avatar',
		'riviantrackr_passkeys_garage'
	)
);

$rtpk_table = $wpdb->prefix . 'riviantrackr_passkeys';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rtpk_table ) );

$rtpk_pending = $wpdb->prefix . 'riviantrackr_pending_signups';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rtpk_pending ) );

wp_cache_flush();
