<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Loader {

    public static function init() {
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-settings.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-scheduler.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-backup-runner.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-restore-runner.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-notifications.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-google-drive.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-update.php';

        SIMPLEBACKUP_Scheduler::init();
        SIMPLEBACKUP_Backup_Runner::init();
        SIMPLEBACKUP_Restore_Runner::init();
        SIMPLEBACKUP_Notifications::init();
        SIMPLEBACKUP_Update::init();

        if ( is_admin() ) {
            require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-admin-actions.php';

            SIMPLEBACKUP_Settings::init();
            SIMPLEBACKUP_Admin_Actions::init();
        }
    }

    public static function activate() {
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-settings.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-scheduler.php';
        SIMPLEBACKUP_Scheduler::activate();
    }

    public static function deactivate() {
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-scheduler.php';
        SIMPLEBACKUP_Scheduler::deactivate();
    }
}
