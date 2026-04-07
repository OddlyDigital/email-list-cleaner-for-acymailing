<?php
/**
 * Uninstall script for Bounce Cleaner for AcyMailing.
 *
 * Runs automatically when the plugin is deleted from the WordPress admin.
 * Removes the audit log table, plugin options, and scheduled events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop the audit log table.
$table = $wpdb->prefix . 'acym_bc_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Remove plugin options.
delete_option( 'acym_bc_db_version' );
delete_option( 'acym_bc_logging_enabled' );
delete_option( 'acym_bc_log_retention' );
delete_option( 'acym_bc_log_anonymise' );

// Clear the scheduled pruning event.
$timestamp = wp_next_scheduled( 'acym_bc_prune_logs' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'acym_bc_prune_logs' );
}
