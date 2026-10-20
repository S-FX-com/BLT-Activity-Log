<?php
/**
 * Uninstall cleanup for BLT Activity Log.
 *
 * Data deletion is OPT-IN (Settings > Advanced). By default the log, the
 * settings and the capability are all kept, so deleting and reinstalling the
 * plugin loses nothing.
 *
 * Nothing here touches the shared BLT family store (`blt_family_shared`,
 * `blt_family_opt_in`): other BLT plugins on the site may still read it.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$blt_activity_log_settings = get_option( 'blt_activity_log_settings', array() );

if ( empty( $blt_activity_log_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// The log.
$blt_activity_log_table = $wpdb->prefix . 'blt_activity_log_entries';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is built from the prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$blt_activity_log_table}" );

// Options.
foreach ( array( 'blt_activity_log_settings', 'blt_activity_log_db_version', 'blt_activity_log_snapshot', 'blt_activity_log_state' ) as $blt_activity_log_option ) {
	delete_option( $blt_activity_log_option );
}

// The capability, from every role that holds it.
$blt_activity_log_roles = wp_roles();

foreach ( $blt_activity_log_roles->role_objects as $blt_activity_log_role ) {
	if ( $blt_activity_log_role->has_cap( 'blt_view_activity_log' ) ) {
		$blt_activity_log_role->remove_cap( 'blt_view_activity_log' );
	}
}

// Each person's "entries per page" screen option. User meta is shared by every site of a network,
// so another site that still runs the plugin keeps its people's setting.
if ( ! is_multisite() ) {
	delete_metadata( 'user', 0, 'blt_activity_log_per_page', '', true );
}

// Scheduled jobs, with any arguments.
foreach ( array( 'blt_activity_log_prune', 'blt_activity_log_snapshot', 'blt_activity_log_prune_continue' ) as $blt_activity_log_hook ) {
	wp_unschedule_hook( $blt_activity_log_hook );
}

// plugin-update-checker state.
delete_option( 'external_updates-blt-activity-log' );
wp_clear_scheduled_hook( 'puc_cron_check_updates-blt-activity-log' );
