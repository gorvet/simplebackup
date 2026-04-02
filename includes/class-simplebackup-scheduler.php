<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Scheduler {

    const AUTO_HOOK = 'simplebackup_run_backup_auto';
    const MISSED_GRACE = 120; // seconds

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_monthly_schedule' ) );
        add_action( self::AUTO_HOOK, array( 'SIMPLEBACKUP_Backup_Runner', 'run_auto' ) );
        add_action( 'init', array( __CLASS__, 'ensure_auto_is_scheduled' ), 20 );
    }

    public static function activate() {
        self::reschedule_auto();
    }

    public static function deactivate() {
        self::clear_all();
    }

    public static function register_monthly_schedule( $schedules ) {
        $schedules['simplebackup_weekly'] = array(
            'display'  => __( 'Once weekly', 'simplebackup' ),
            'interval' => WEEK_IN_SECONDS,
        );

        $schedules['simplebackup_monthly'] = array(
            'display'  => __( 'Once monthly', 'simplebackup' ),
            'interval' => 30 * DAY_IN_SECONDS,
        );

        return $schedules;
    }

    public static function clear_all() {
        wp_clear_scheduled_hook( self::AUTO_HOOK );
    }

    public static function reschedule_auto( $settings = null ) {
        wp_clear_scheduled_hook( self::AUTO_HOOK );

        if ( null === $settings ) {
            $settings = SIMPLEBACKUP_Settings::get_settings();
        }

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $recurrence = self::map_recurrence( $settings );
        $timestamp  = self::next_auto_timestamp( $settings );

        if ( $timestamp > 0 && ! empty( $recurrence ) ) {
            wp_schedule_event( $timestamp, $recurrence, self::AUTO_HOOK );
        }
    }

    public static function ensure_auto_is_scheduled() {
        $settings = SIMPLEBACKUP_Settings::get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $next = wp_next_scheduled( self::AUTO_HOOK );
        if ( ! $next ) {
            self::reschedule_auto( $settings );
            return;
        }

        // Recover missed cron executions instead of silently skipping to the next recurrence.
        if ( $next <= ( time() - self::MISSED_GRACE ) ) {
            SIMPLEBACKUP_Backup_Runner::run_auto();
            self::reschedule_auto( $settings );
        }
    }

    private static function map_recurrence( $settings ) {
        $frequency = isset( $settings['frequency'] ) ? sanitize_key( $settings['frequency'] ) : 'daily';

        switch ( $frequency ) {
            case 'weekly':
                return 'simplebackup_weekly';
            case 'monthly':
                return 'simplebackup_monthly';
            case 'daily':
            default:
                return 'daily';
        }
    }

    private static function next_auto_timestamp( $settings ) {
        $timezone  = wp_timezone();
        $now       = new DateTimeImmutable( 'now', $timezone );
        $frequency = isset( $settings['frequency'] ) ? sanitize_key( (string) $settings['frequency'] ) : 'daily';

        $time    = isset( $settings['time'] ) ? $settings['time'] : '02:00';
        $hour    = 2;
        $minute  = 0;
        $matches = array();

        if ( preg_match( '/^(\d{2}):(\d{2})$/', $time, $matches ) ) {
            $hour   = max( 0, min( 23, (int) $matches[1] ) );
            $minute = max( 0, min( 59, (int) $matches[2] ) );
        }

        $next = $now->setTime( $hour, $minute );
        if ( $next->getTimestamp() <= $now->getTimestamp() ) {
            if ( 'weekly' === $frequency ) {
                $next = $next->modify( '+1 week' );
            } elseif ( 'monthly' === $frequency ) {
                $next = $next->modify( '+1 month' );
            } else {
                $next = $next->modify( '+1 day' );
            }
        }

        return $next->getTimestamp();
    }
}
