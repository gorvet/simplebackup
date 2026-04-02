<?php
/**
 * Plugin Name: SimpleBackup
 * Plugin URI: https://www.gorvet.com
 * Description: Addon independiente para automatizar backups con All-in-One WP Migration: horario, frecuencia, notificaciones y opcion de Google Drive.
 * Version: 1.0.0
 * Author: Juank de Gorvet
 * Author URI: https://api.whatsapp.com/send/?phone=5353779424
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simplebackup
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 *
 * @package SimpleBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SIMPLEBACKUP_VERSION', '1.0.0' );
define( 'SIMPLEBACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIMPLEBACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'SIMPLEBACKUP_SLUG', plugin_basename( __FILE__ ) );
define( 'SIMPLEBACKUP_PLUGIN_FILE', __FILE__ );
define( 'SIMPLEBACKUP_OPTION_SETTINGS', 'simplebackup_settings' );
define( 'SIMPLEBACKUP_OPTION_RUNTIME', 'simplebackup_runtime' );
define( 'SIMPLEBACKUP_OPTION_RESTORE_RUNTIME', 'simplebackup_restore_runtime' );
define( 'SIMPLEBACKUP_RUN_LOCK_TRANSIENT', 'simplebackup_run_lock' );
define( 'SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT', 'simplebackup_restore_lock' );
define( 'SIMPLEBACKUP_UPDATE_INFO_URL', 'https://repo.gorvet.com/updates/simplebackup/info.json' );

add_action(
    'plugins_loaded',
    static function () {
        load_plugin_textdomain( 'simplebackup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
);

require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-loader.php';

add_action( 'plugins_loaded', array( 'SIMPLEBACKUP_Loader', 'init' ) );
register_activation_hook( __FILE__, array( 'SIMPLEBACKUP_Loader', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SIMPLEBACKUP_Loader', 'deactivate' ) );
