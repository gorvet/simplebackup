<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Loader {

    public static function init() {
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-settings.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-scheduler.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-backup-runner.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-restore-runner.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-notifications.php';
        require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-update.php';

        SIMPLEBACKUP_Scheduler::init();
        SIMPLEBACKUP_Backup_Runner::init();
        SIMPLEBACKUP_Restore_Runner::init();
        SIMPLEBACKUP_Notifications::init();
        SIMPLEBACKUP_Update::init();

        if ( is_admin() ) {
            SIMPLEBACKUP_Settings::init();
        }
    }

    public static function activate() {
        if ( ! class_exists( 'SIMPLEBACKUP_Settings' ) ) {
            require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-settings.php';
        }

        if ( ! class_exists( 'SIMPLEBACKUP_Scheduler' ) ) {
            require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-scheduler.php';
        }

        SIMPLEBACKUP_Scheduler::activate();
    }

    public static function deactivate() {
        if ( ! class_exists( 'SIMPLEBACKUP_Scheduler' ) ) {
            require_once SIMPLEBACKUP_DIR . 'includes/class-simplebackup-scheduler.php';
        }

        SIMPLEBACKUP_Scheduler::deactivate();
    }
}
