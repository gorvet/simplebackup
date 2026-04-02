<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package SIMPLEBACKUP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

delete_option( 'simplebackup_settings' );
delete_option( 'simplebackup_runtime' );
delete_transient( 'simplebackup_run_lock' );

wp_clear_scheduled_hook( 'simplebackup_run_backup_auto' );
wp_clear_scheduled_hook( 'simplebackup_run_backup_manual' );
