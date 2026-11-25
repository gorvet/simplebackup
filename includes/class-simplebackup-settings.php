<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Settings {
    const PAGE_SLUG = 'simplebackup';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    public static function get_defaults() {
        return array(
            'enabled'                     => 0,
            'frequency'                   => 'daily',
            'time'                        => '02:00',
            'notify_success'              => 1,
            'notify_error'                => 1,
            'emails'                      => '',
            'keep_local'                  => 5,
            'auto_delete_old'             => 0,
            'delete_older_than_days'      => 30,
            'gdrive_enabled'              => 0,
            'gdrive_folder_id'            => '',
            'gdrive_service_account_json' => '',
        );
    }

    public static function get_settings() {
        $saved = get_option( SIMPLEBACKUP_OPTION_SETTINGS, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return array_merge( self::get_defaults(), $saved );
    }

    public static function register_settings() {
        register_setting(
            'simplebackup_settings_group',
            SIMPLEBACKUP_OPTION_SETTINGS,
            array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) )
        );
    }

    public static function sanitize_settings( $input ) {
        $defaults = self::get_defaults();
        $old      = self::get_settings();
        $output   = $defaults;

        if ( ! is_array( $input ) ) {
            $input = array();
        }

        $output['enabled']         = empty( $input['enabled'] ) ? 0 : 1;
        $output['notify_success']  = empty( $input['notify_success'] ) ? 0 : 1;
        $output['notify_error']    = empty( $input['notify_error'] ) ? 0 : 1;
        $output['gdrive_enabled']  = empty( $input['gdrive_enabled'] ) ? 0 : 1;
        $output['auto_delete_old'] = empty( $input['auto_delete_old'] ) ? 0 : 1;

        $allowed_frequency   = array( 'daily', 'weekly', 'monthly' );
        $frequency           = isset( $input['frequency'] ) ? sanitize_key( $input['frequency'] ) : $defaults['frequency'];
        $output['frequency'] = in_array( $frequency, $allowed_frequency, true ) ? $frequency : $defaults['frequency'];

        $time = isset( $input['time'] ) ? sanitize_text_field( $input['time'] ) : $defaults['time'];
        if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
            $time = $defaults['time'];
        }
        $output['time'] = $time;

        $output['emails'] = isset( $input['emails'] ) ? sanitize_text_field( $input['emails'] ) : '';

        $keep_local                       = isset( $input['keep_local'] ) ? absint( $input['keep_local'] ) : $defaults['keep_local'];
        $output['keep_local']             = max( 1, min( 100, $keep_local ) );
        $delete_days                      = isset( $input['delete_older_than_days'] ) ? absint( $input['delete_older_than_days'] ) : $defaults['delete_older_than_days'];
        $output['delete_older_than_days'] = max( 1, min( 3650, $delete_days ) );
        $output['gdrive_folder_id']       = isset( $old['gdrive_folder_id'] ) ? sanitize_text_field( $old['gdrive_folder_id'] ) : '';

        $json = '';
        if ( isset( $input['gdrive_service_account_json'] ) ) {
            $json = trim( wp_unslash( (string) $input['gdrive_service_account_json'] ) );
        }
        if ( strlen( $json ) > 200000 ) {
            $json = '';
        }
        $output['gdrive_service_account_json'] = $json;

        if ( isset( $old['gdrive_service_account_json'] ) && $old['gdrive_service_account_json'] !== $output['gdrive_service_account_json'] ) {
            $output['gdrive_folder_id'] = '';
        }

        if ( $old !== $output ) {
            SIMPLEBACKUP_Scheduler::reschedule_auto( $output );
        }

        return $output;
    }

    public static function add_admin_page() {
        add_menu_page(
            __( 'SimpleBackup', 'simplebackup' ),
            __( 'SimpleBackup', 'simplebackup' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' ),
            SIMPLEBACKUP_URL . 'assets/icon.png',
            81
        );
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_' . self::PAGE_SLUG !== (string) $hook ) {
            return;
        }

        wp_enqueue_style( 'simplebackup-admin', SIMPLEBACKUP_URL . 'assets/css/admin.css', array(), SIMPLEBACKUP_VERSION );

        $styles = array( 'ai1wm_servmask', 'ai1wm_import', 'ai1wm_export' );
        foreach ( $styles as $handle ) {
            if ( wp_style_is( $handle, 'registered' ) ) {
                wp_enqueue_style( $handle );
            }
        }

        $scripts = array( 'ai1wm_util', 'ai1wm_servmask', 'ai1wm_import', 'ai1wm_export' );
        foreach ( $scripts as $handle ) {
            if ( wp_script_is( $handle, 'registered' ) ) {
                wp_enqueue_script( $handle );
            }
        }
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'simplebackup' ) );
        }

        $settings = self::get_settings();
        SIMPLEBACKUP_Backup_Runner::check_running_state();
        SIMPLEBACKUP_Scheduler::ensure_auto_is_scheduled();

        $runtime        = get_option( SIMPLEBACKUP_OPTION_RUNTIME, array() );
        $next_auto      = wp_next_scheduled( SIMPLEBACKUP_Scheduler::AUTO_HOOK );
        $wp_timezone    = wp_timezone_string();
        if ( '' === $wp_timezone ) {
            $wp_timezone = 'UTC';
        }
        $ai1wm_ready    = class_exists( 'Ai1wm_Export_Controller' ) && defined( 'AI1WM_SECRET_KEY' ) && wp_script_is( 'ai1wm_import', 'registered' ) && wp_script_is( 'ai1wm_export', 'registered' );
        $local_backups  = SIMPLEBACKUP_Restore_Runner::get_local_backups( 25 );
        $gdrive_backups = SIMPLEBACKUP_Restore_Runner::get_gdrive_backups( 25 );
        $local_downloadable = $ai1wm_ready && function_exists( 'ai1wm_backup_url' );

        $notice = isset( $_GET['simplebackup_notice'] ) ? sanitize_key( wp_unslash( $_GET['simplebackup_notice'] ) ) : '';

        if ( 'manual_run_started' === $notice ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Backup manual lanzado.', 'simplebackup' ) . '</p></div>';
        }

        if ( 'manual_run_error' === $notice ) {
            $error_message = get_transient( 'simplebackup_manual_error' );
            delete_transient( 'simplebackup_manual_error' );
            if ( empty( $error_message ) ) {
                $error_message = __( 'No fue posible iniciar el backup manual.', 'simplebackup' );
            }
            echo '<div class="notice notice-error"><p>' . esc_html( $error_message ) . '</p></div>';
        }

        if ( 'restore_started' === $notice ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Restauracion lanzada.', 'simplebackup' ) . '</p></div>';
        }

        if ( 'restore_error' === $notice ) {
            $error_message = get_transient( 'simplebackup_restore_error' );
            delete_transient( 'simplebackup_restore_error' );
            if ( empty( $error_message ) ) {
                $error_message = __( 'No fue posible iniciar la restauracion.', 'simplebackup' );
            }
            echo '<div class="notice notice-error"><p>' . esc_html( $error_message ) . '</p></div>';
        }

        if ( 'delete_backup_ok' === $notice ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Copia eliminada correctamente.', 'simplebackup' ) . '</p></div>';
        }

        if ( 'delete_backup_error' === $notice ) {
            $error_message = get_transient( 'simplebackup_delete_backup_error' );
            delete_transient( 'simplebackup_delete_backup_error' );
            if ( empty( $error_message ) ) {
                $error_message = __( 'No se pudo eliminar la copia seleccionada.', 'simplebackup' );
            }
            echo '<div class="notice notice-error"><p>' . esc_html( $error_message ) . '</p></div>';
        }

        $modal_config = array();
        if ( $ai1wm_ready ) {
            wp_enqueue_script( 'simplebackup-admin', SIMPLEBACKUP_URL . 'assets/js/admin.js', array( 'jquery' ), SIMPLEBACKUP_VERSION, true );
            $modal_config = self::get_modal_config();
        }

        require SIMPLEBACKUP_DIR . 'views/admin-page.php';
    }

    private static function get_modal_config() {
        return array(
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'ajaxNonce'            => wp_create_nonce( 'simplebackup_ai1wm_modal' ),
            'modalLogoUrl'         => SIMPLEBACKUP_URL . 'assets/icon.png',
            'confirmRestore'       => __( 'Esta accion reemplazara contenido del sitio. Deseas continuar?', 'simplebackup' ),
            'busyLabel'            => __( 'Procesando...', 'simplebackup' ),
            'backupBusyLabel'      => __( 'Creando copia de seguridad...', 'simplebackup' ),
            'restoreBusyLabel'     => __( 'Restaurando...', 'simplebackup' ),
            'prepareBusyLabel'     => __( 'Preparando restauracion...', 'simplebackup' ),
            'prepareGdriveMessage' => __( 'Descargando copia desde Google Drive...', 'simplebackup' ),
            'prepareErrorMessage'  => __( 'No se pudo preparar la copia desde Google Drive.', 'simplebackup' ),
            'missingAi1wmMessage'  => __( 'No se pudo cargar el motor de All-in-One WP Migration en esta pantalla.', 'simplebackup' ),
            'unknownErrorMessage'  => __( 'Ocurrio un error inesperado.', 'simplebackup' ),
            'exportReadyTitle'     => __( 'Copia de seguridad lista', 'simplebackup' ),
            'exportReadyStep'      => __( 'Copia de seguridad completada', 'simplebackup' ),
            'exportWorkingStep'    => __( 'Procesando copia de seguridad', 'simplebackup' ),
            'exportKeepOpenNotice' => __( 'No cierres esta ventana hasta que el backup termine.', 'simplebackup' ),
            'restoreConfirmTitle'  => __( 'Confirmar restauracion', 'simplebackup' ),
            'restoreConfirmMessage' => __( 'Al restaurar esta copia solo se reemplazara el contenido coincidente del sitio. Los demas elementos permaneceran sin cambios. Asegurate de tener una copia reciente antes de continuar.', 'simplebackup' ),
            'modalLocale'          => array(
                'stop_exporting_your_website'      => __( 'Seguro que deseas detener la copia de seguridad?', 'simplebackup' ),
                'preparing_to_export'              => __( 'Creando copia de seguridad...', 'simplebackup' ),
                'unable_to_export'                 => __( 'La copia de seguridad fallo', 'simplebackup' ),
                'unable_to_start_the_export'       => __( 'No se pudo iniciar el backup. Recarga e intenta otra vez.', 'simplebackup' ),
                'unable_to_run_the_export'         => __( 'No se pudo ejecutar el backup. Recarga e intenta otra vez.', 'simplebackup' ),
                'unable_to_stop_the_export'        => __( 'No se pudo detener el backup. Recarga e intenta otra vez.', 'simplebackup' ),
                'please_wait_stopping_the_export'  => __( 'Deteniendo backup, espera...', 'simplebackup' ),
                'close_export'                     => __( 'Cerrar', 'simplebackup' ),
                'stop_export'                      => __( 'Cancelar copia', 'simplebackup' ),
                'stop_importing_your_website'      => __( 'Seguro que deseas detener la restauracion?', 'simplebackup' ),
                'preparing_to_import'              => __( 'Preparando restauracion...', 'simplebackup' ),
                'unable_to_import'                 => __( 'La restauracion fallo', 'simplebackup' ),
                'unable_to_start_the_import'       => __( 'No se pudo iniciar la restauracion. Recarga e intenta otra vez.', 'simplebackup' ),
                'unable_to_confirm_the_import'     => __( 'No se pudo confirmar la restauracion. Recarga e intenta otra vez.', 'simplebackup' ),
                'unable_to_check_decryption_password' => __( 'No se pudo validar la clave de desencriptado.', 'simplebackup' ),
                'unable_to_prepare_blogs_on_import'   => __( 'No se pudo preparar la restauracion en multisitio.', 'simplebackup' ),
                'unable_to_stop_the_import'        => __( 'No se pudo detener la restauracion. Recarga e intenta otra vez.', 'simplebackup' ),
                'please_wait_stopping_the_import'  => __( 'Deteniendo restauracion, espera...', 'simplebackup' ),
                'close_import'                     => __( 'Cancelar', 'simplebackup' ),
                'stop_import'                      => __( 'Detener restauracion', 'simplebackup' ),
                'finish_import'                    => __( 'Finalizar restauracion', 'simplebackup' ),
                'confirm_import'                   => __( 'Continuar restauracion', 'simplebackup' ),
                'confirm_disk_space'               => __( 'Tengo espacio suficiente', 'simplebackup' ),
                'continue_import'                  => __( 'Continuar', 'simplebackup' ),
                'please_do_not_close_this_browser' => __( 'No cierres esta ventana o la restauracion fallara', 'simplebackup' ),
                'backup_encrypted'                 => __( 'La copia esta cifrada', 'simplebackup' ),
                'backup_encrypted_message'         => __( 'Introduce una contraseña para restaurar la copia', 'simplebackup' ),
                'submit'                           => __( 'Enviar', 'simplebackup' ),
                'enter_password'                   => __( 'Introduce una contraseña', 'simplebackup' ),
                'repeat_password'                  => __( 'Repite la contraseña', 'simplebackup' ),
                'passwords_do_not_match'           => __( 'Las contraseñas no coinciden', 'simplebackup' ),
                'view_error_log_button'            => __( 'Ver log de errores', 'simplebackup' ),
                'archive_browser_download_error'   => __( 'No se pudo descargar la copia', 'simplebackup' ),
            ),
        );
    }
}
