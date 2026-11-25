<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Admin_Actions {

    public static function init() {
        // Legacy fallback handlers (non-JS flow).
        add_action( 'admin_post_simplebackup_run_backup_now', array( __CLASS__, 'handle_manual_run' ) );
        add_action( 'admin_post_simplebackup_restore_local', array( __CLASS__, 'handle_restore_local' ) );
        add_action( 'admin_post_simplebackup_restore_gdrive', array( __CLASS__, 'handle_restore_gdrive' ) );
        add_action( 'admin_post_simplebackup_delete_local_backup', array( __CLASS__, 'handle_delete_local_backup' ) );
        add_action( 'admin_post_simplebackup_delete_gdrive_backup', array( __CLASS__, 'handle_delete_gdrive_backup' ) );

        // Prepare remote archive (Google Drive) before running AI1WM import modal flow.
        add_action( 'wp_ajax_simplebackup_prepare_restore_gdrive', array( __CLASS__, 'ajax_prepare_restore_gdrive' ) );
        add_action( 'wp_ajax_simplebackup_reset_ai1wm_status', array( __CLASS__, 'ajax_reset_ai1wm_status' ) );
    }

    public static function handle_manual_run() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to run backups.', 'simplebackup' ) );
        }

        check_admin_referer( 'simplebackup_manual_run' );

        $result = SIMPLEBACKUP_Backup_Runner::launch_backup( 'manual' );
        $notice = 'manual_run_started';

        if ( is_wp_error( $result ) ) {
            set_transient( 'simplebackup_manual_error', $result->get_error_message(), 60 );
            $notice = 'manual_run_error';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                => SIMPLEBACKUP_Settings::PAGE_SLUG,
                    'simplebackup_notice' => $notice,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_restore_local() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to run restores.', 'simplebackup' ) );
        }

        check_admin_referer( 'simplebackup_restore_local' );

        $archive = isset( $_POST['archive'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['archive'] ) ) : '';
        $result  = SIMPLEBACKUP_Restore_Runner::launch_restore_local( $archive, 'local' );
        $notice  = 'restore_started';

        if ( is_wp_error( $result ) ) {
            set_transient( 'simplebackup_restore_error', $result->get_error_message(), 90 );
            $notice = 'restore_error';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                => SIMPLEBACKUP_Settings::PAGE_SLUG,
                    'simplebackup_notice' => $notice,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_restore_gdrive() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to run restores.', 'simplebackup' ) );
        }

        check_admin_referer( 'simplebackup_restore_gdrive' );

        $file_id   = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['file_id'] ) ) : '';
        $file_name = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['file_name'] ) ) : '';
        $result    = SIMPLEBACKUP_Restore_Runner::launch_restore_gdrive( $file_id, $file_name );
        $notice    = 'restore_started';

        if ( is_wp_error( $result ) ) {
            set_transient( 'simplebackup_restore_error', $result->get_error_message(), 90 );
            $notice = 'restore_error';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                => SIMPLEBACKUP_Settings::PAGE_SLUG,
                    'simplebackup_notice' => $notice,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_delete_local_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to delete backups.', 'simplebackup' ) );
        }

        check_admin_referer( 'simplebackup_delete_local_backup' );

        $archive = isset( $_POST['archive'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['archive'] ) ) : '';
        $result  = SIMPLEBACKUP_Restore_Runner::delete_local_backup( $archive );
        $notice  = 'delete_backup_ok';

        if ( is_wp_error( $result ) ) {
            set_transient( 'simplebackup_delete_backup_error', $result->get_error_message(), 90 );
            $notice = 'delete_backup_error';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                => SIMPLEBACKUP_Settings::PAGE_SLUG,
                    'simplebackup_notice' => $notice,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_delete_gdrive_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to delete backups.', 'simplebackup' ) );
        }

        check_admin_referer( 'simplebackup_delete_gdrive_backup' );

        $file_id = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['file_id'] ) ) : '';
        $result  = SIMPLEBACKUP_Restore_Runner::delete_gdrive_backup( $file_id );
        $notice  = 'delete_backup_ok';

        if ( is_wp_error( $result ) ) {
            set_transient( 'simplebackup_delete_backup_error', $result->get_error_message(), 90 );
            $notice = 'delete_backup_error';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                => SIMPLEBACKUP_Settings::PAGE_SLUG,
                    'simplebackup_notice' => $notice,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function ajax_prepare_restore_gdrive() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'No autorizado.', 'simplebackup' ),
                ),
                403
            );
        }

        check_ajax_referer( 'simplebackup_ai1wm_modal', 'nonce' );

        $file_id   = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['file_id'] ) ) : '';
        $file_name = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['file_name'] ) ) : '';

        $archive = SIMPLEBACKUP_Restore_Runner::prepare_gdrive_archive( $file_id, $file_name );
        if ( is_wp_error( $archive ) ) {
            wp_send_json_error(
                array(
                    'message' => $archive->get_error_message(),
                )
            );
        }

        wp_send_json_success(
            array(
                'archive' => $archive,
                'message' => __( 'Archivo listo. Iniciando restauracion...', 'simplebackup' ),
            )
        );
    }

    public static function ajax_reset_ai1wm_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'No autorizado.', 'simplebackup' ),
                ),
                403
            );
        }

        check_ajax_referer( 'simplebackup_ai1wm_modal', 'nonce' );

        if ( defined( 'AI1WM_STATUS' ) ) {
            update_option( AI1WM_STATUS, array() );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Estado de All-in-One reiniciado.', 'simplebackup' ),
            )
        );
    }
}
