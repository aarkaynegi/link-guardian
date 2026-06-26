<?php
/**
 * Uninstall handler: remove all Link Guardian data when the plugin is deleted.
 *
 * @package LinkGuardian
 */

// If uninstall is not called from WordPress, bail.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$link_guardian_table = $wpdb->prefix . 'lg_redirects';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$link_guardian_table}" );

delete_option( 'link_guardian_settings' );
delete_option( 'link_guardian_db_version' );

// Remove any leftover per-URL scan transients.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_lg_scan_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_lg_scan_' ) . '%'
	)
);

// Clear any scheduled background link rewrites.
wp_unschedule_hook( 'link_guardian_rewrite_batch' );
