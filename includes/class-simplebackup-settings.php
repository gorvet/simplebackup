<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Settings {
    const PAGE_SLUG = 'simplebackup';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

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
            plugin_dir_url( __FILE__ ) . '../assets/icon.png',
            81
        );
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_' . self::PAGE_SLUG !== (string) $hook ) {
            return;
        }

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
                    'page'                => self::PAGE_SLUG,
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
                    'page'                => self::PAGE_SLUG,
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
                    'page'                => self::PAGE_SLUG,
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
                    'page'                => self::PAGE_SLUG,
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
                    'page'                => self::PAGE_SLUG,
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

        $modal_config = array(
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

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'SimpleBackup', 'simplebackup' ); ?></h1>
            <p><?php esc_html_e( 'Automatiza copias de seguridad con All-in-One WP Migration.', 'simplebackup' ); ?></p>
            <style>
                .simplebackup-list {
                    max-width: 1000px;
                    margin: 12px 0 24px;
                }
                .simplebackup-actions {
                    position: relative;
                    display: inline-flex;
                    justify-content: flex-end;
                }
                .simplebackup-actions-toggle {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 34px;
                    height: 34px;
                    border: 1px solid #dcdcde;
                    border-radius: 999px;
                    background: #fff;
                    color: #1d2327;
                    cursor: pointer;
                }
                .simplebackup-actions-toggle:hover,
                .simplebackup-actions-toggle[aria-expanded="true"] {
                    border-color: #2271b1;
                    color: #2271b1;
                }
                .simplebackup-actions-menu {
                    position: absolute;
                    top: calc(100% + 8px);
                    right: 0;
                    z-index: 20;
                    min-width: 220px;
                    padding: 8px;
                    border: 1px solid #dcdcde;
                    border-radius: 10px;
                    background: #fff;
                    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
                    display: none;
                }
                .simplebackup-actions.is-open .simplebackup-actions-menu {
                    display: block;
                }
                .simplebackup-actions-menu a,
                .simplebackup-actions-menu button {
                    display: flex;
                    width: 100%;
                    align-items: center;
                    justify-content: flex-start;
                    gap: 8px;
                    padding: 9px 10px;
                    border: 0;
                    border-radius: 8px;
                    background: transparent;
                    color: #1d2327;
                    text-align: left;
                    text-decoration: none;
                    cursor: pointer;
                }
                .simplebackup-actions-menu a:hover,
                .simplebackup-actions-menu button:hover {
                    background: #f0f6fc;
                    color: #2271b1;
                }
                .simplebackup-actions-menu form {
                    margin: 0;
                }
                .simplebackup-actions-menu .is-danger:hover {
                    background: #fcf0f1;
                    color: #b32d2e;
                }
            </style>

            <?php if ( ! $ai1wm_ready ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'All-in-One no esta disponible en este contexto. Se usa flujo interno sin modal AI1WM.', 'simplebackup' ); ?></p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width: 900px; margin: 16px 0;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'All-in-One detectado', 'simplebackup' ); ?></th>
                        <td><?php echo $ai1wm_ready ? esc_html__( 'Si', 'simplebackup' ) : esc_html__( 'No', 'simplebackup' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Proximo backup automatico', 'simplebackup' ); ?></th>
                        <td>
                            <?php
                            if ( $next_auto ) {
                                echo esc_html( wp_date( 'Y-m-d H:i:s', $next_auto ) );
                            } else {
                                esc_html_e( 'No programado', 'simplebackup' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Ultimo estado backup', 'simplebackup' ); ?></th>
                        <td><?php echo isset( $runtime['status'] ) ? esc_html( $runtime['status'] ) : esc_html__( 'Sin ejecuciones', 'simplebackup' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Ultimo mensaje backup', 'simplebackup' ); ?></th>
                        <td><?php echo ! empty( $runtime['message'] ) ? esc_html( $runtime['message'] ) : '-'; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Ultimo archivo backup', 'simplebackup' ); ?></th>
                        <td><?php echo isset( $runtime['archive'] ) ? esc_html( $runtime['archive'] ) : '-'; ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="options.php" style="max-width: 900px;">
                <?php settings_fields( 'simplebackup_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Activar automatizacion', 'simplebackup' ); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[enabled]" value="1" <?php checked( 1, (int) $settings['enabled'] ); ?> /> <?php esc_html_e( 'Habilitado', 'simplebackup' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Frecuencia', 'simplebackup' ); ?></th>
                            <td>
                                <select name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[frequency]">
                                    <option value="daily" <?php selected( $settings['frequency'], 'daily' ); ?>><?php esc_html_e( 'Diario', 'simplebackup' ); ?></option>
                                    <option value="weekly" <?php selected( $settings['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Semanal', 'simplebackup' ); ?></option>
                                    <option value="monthly" <?php selected( $settings['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Mensual', 'simplebackup' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Diario, semanal o mensual a la hora indicada.', 'simplebackup' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Horario (24h)', 'simplebackup' ); ?></th>
                            <td>
                                <input type="time" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[time]" value="<?php echo esc_attr( $settings['time'] ); ?>" />
                                <p class="description">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: WordPress timezone string. */
                                            __( 'Se programa con la zona horaria de WordPress (%s). El navegador puede mostrar el selector en formato AM/PM, pero se guarda en 24h.', 'simplebackup' ),
                                            $wp_timezone
                                        )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Copias locales a conservar', 'simplebackup' ); ?></th>
                            <td>
                                <input type="number" min="1" max="100" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[keep_local]" value="<?php echo esc_attr( $settings['keep_local'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Cantidad maxima de copias locales creadas por SimpleBackup que se mantendran antes de eliminar las mas antiguas.', 'simplebackup' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Borrado automatico por antiguedad', 'simplebackup' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[auto_delete_old]" value="1" <?php checked( 1, (int) $settings['auto_delete_old'] ); ?> /> <?php esc_html_e( 'Activar borrado automatico de copias antiguas', 'simplebackup' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Borrar copias con mas de (dias)', 'simplebackup' ); ?></th>
                            <td>
                                <input type="number" min="1" max="3650" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[delete_older_than_days]" value="<?php echo esc_attr( $settings['delete_older_than_days'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Solo aplica si activas el borrado automatico.', 'simplebackup' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Notificar exito', 'simplebackup' ); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[notify_success]" value="1" <?php checked( 1, (int) $settings['notify_success'] ); ?> /> <?php esc_html_e( 'Enviar email cuando termine bien', 'simplebackup' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Notificar error', 'simplebackup' ); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[notify_error]" value="1" <?php checked( 1, (int) $settings['notify_error'] ); ?> /> <?php esc_html_e( 'Enviar email cuando falle', 'simplebackup' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Correos adicionales', 'simplebackup' ); ?></th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[emails]" value="<?php echo esc_attr( $settings['emails'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Separados por coma. El correo admin siempre se incluye.', 'simplebackup' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Google Drive (propio)', 'simplebackup' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[gdrive_enabled]" value="1" <?php checked( 1, (int) $settings['gdrive_enabled'] ); ?> /> <?php esc_html_e( 'Subir copia tambien a Google Drive (sin extension de AI1WM).', 'simplebackup' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Service Account JSON', 'simplebackup' ); ?></th>
                            <td>
                                <textarea name="<?php echo esc_attr( SIMPLEBACKUP_OPTION_SETTINGS ); ?>[gdrive_service_account_json]" rows="10" class="large-text code"><?php echo esc_textarea( $settings['gdrive_service_account_json'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Pega aqui el JSON completo de una cuenta de servicio de Google Cloud. La carpeta destino se crea automaticamente en la raiz de Drive.', 'simplebackup' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Guardar cambios', 'simplebackup' ) ); ?>
            </form>

            <h2 style="margin-top: 28px;"><?php esc_html_e( 'Backup manual', 'simplebackup' ); ?></h2>
            <?php if ( $ai1wm_ready ) : ?>
                <button type="button" id="simplebackup-ai1wm-run-backup" class="button button-secondary simplebackup-ai1wm-action" data-busy-label="<?php echo esc_attr__( 'Creando copia de seguridad...', 'simplebackup' ); ?>"><?php esc_html_e( 'Crear copia de seguridad', 'simplebackup' ); ?></button>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
                    <?php wp_nonce_field( 'simplebackup_manual_run' ); ?>
                    <input type="hidden" name="action" value="simplebackup_run_backup_now" />
                    <?php submit_button( __( 'Lanzar backup manual', 'simplebackup' ), 'secondary', 'submit', false ); ?>
                </form>
            <?php endif; ?>

            <h2 style="margin-top: 32px;"><?php esc_html_e( 'Restaurar desde copias locales', 'simplebackup' ); ?></h2>
            <p><?php esc_html_e( 'Estas copias ya existen en el servidor y se restauran usando el mismo motor de importacion de All-in-One WP Migration.', 'simplebackup' ); ?></p>

            <?php if ( empty( $local_backups ) ) : ?>
                <p><?php esc_html_e( 'No hay copias locales disponibles.', 'simplebackup' ); ?></p>
            <?php else : ?>
                <table class="widefat striped simplebackup-list">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Archivo', 'simplebackup' ); ?></th>
                            <th><?php esc_html_e( 'Fecha', 'simplebackup' ); ?></th>
                            <th><?php esc_html_e( 'Tamano', 'simplebackup' ); ?></th>
                            <th><?php esc_html_e( 'Accion', 'simplebackup' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $local_backups as $backup ) : ?>
                            <tr>
                                <td><?php echo esc_html( $backup['archive'] ); ?></td>
                                <td><?php echo ! empty( $backup['mtime'] ) ? esc_html( wp_date( 'Y-m-d H:i:s', (int) $backup['mtime'] ) ) : '-'; ?></td>
                                <td><?php echo isset( $backup['size'] ) ? esc_html( size_format( (int) $backup['size'] ) ) : '-'; ?></td>
                                <td>
                                    <?php if ( $ai1wm_ready ) : ?>
                                        <div class="simplebackup-actions">
                                            <button type="button" class="simplebackup-actions-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Mas acciones', 'simplebackup' ); ?>">
                                                <span class="dashicons dashicons-ellipsis"></span>
                                            </button>
                                            <div class="simplebackup-actions-menu">
                                                <button type="button" class="simplebackup-ai1wm-action simplebackup-ai1wm-restore-local" data-busy-label="<?php echo esc_attr__( 'Restaurando...', 'simplebackup' ); ?>" data-archive="<?php echo esc_attr( $backup['archive'] ); ?>">
                                                    <span class="dashicons dashicons-update"></span>
                                                    <span><?php esc_html_e( 'Restaurar', 'simplebackup' ); ?></span>
                                                </button>
                                                <?php if ( $local_downloadable ) : ?>
                                                    <a href="<?php echo esc_url( ai1wm_backup_url( array( 'archive' => $backup['archive'] ) ) ); ?>" download="<?php echo esc_attr( $backup['archive'] ); ?>">
                                                        <span class="dashicons dashicons-download"></span>
                                                        <span><?php esc_html_e( 'Descargar', 'simplebackup' ); ?></span>
                                                    </a>
                                                <?php endif; ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_attr__( 'Vas a eliminar esta copia local. Deseas continuar?', 'simplebackup' ); ?>');">
                                                    <?php wp_nonce_field( 'simplebackup_delete_local_backup' ); ?>
                                                    <input type="hidden" name="action" value="simplebackup_delete_local_backup" />
                                                    <input type="hidden" name="archive" value="<?php echo esc_attr( $backup['archive'] ); ?>" />
                                                    <button type="submit" class="is-danger">
                                                        <span class="dashicons dashicons-trash"></span>
                                                        <span><?php esc_html_e( 'Borrar', 'simplebackup' ); ?></span>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php else : ?>
                                        <div class="simplebackup-actions">
                                            <button type="button" class="simplebackup-actions-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Mas acciones', 'simplebackup' ); ?>">
                                                <span class="dashicons dashicons-ellipsis"></span>
                                            </button>
                                            <div class="simplebackup-actions-menu">
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                    <?php wp_nonce_field( 'simplebackup_restore_local' ); ?>
                                                    <input type="hidden" name="action" value="simplebackup_restore_local" />
                                                    <input type="hidden" name="archive" value="<?php echo esc_attr( $backup['archive'] ); ?>" />
                                                    <button type="submit">
                                                        <span class="dashicons dashicons-update"></span>
                                                        <span><?php esc_html_e( 'Restaurar', 'simplebackup' ); ?></span>
                                                    </button>
                                                </form>
                                                <?php if ( $local_downloadable ) : ?>
                                                    <a href="<?php echo esc_url( ai1wm_backup_url( array( 'archive' => $backup['archive'] ) ) ); ?>" download="<?php echo esc_attr( $backup['archive'] ); ?>">
                                                        <span class="dashicons dashicons-download"></span>
                                                        <span><?php esc_html_e( 'Descargar', 'simplebackup' ); ?></span>
                                                    </a>
                                                <?php endif; ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_attr__( 'Vas a eliminar esta copia local. Deseas continuar?', 'simplebackup' ); ?>');">
                                                    <?php wp_nonce_field( 'simplebackup_delete_local_backup' ); ?>
                                                    <input type="hidden" name="action" value="simplebackup_delete_local_backup" />
                                                    <input type="hidden" name="archive" value="<?php echo esc_attr( $backup['archive'] ); ?>" />
                                                    <button type="submit" class="is-danger">
                                                        <span class="dashicons dashicons-trash"></span>
                                                        <span><?php esc_html_e( 'Borrar', 'simplebackup' ); ?></span>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top: 32px;"><?php esc_html_e( 'Restaurar desde Google Drive', 'simplebackup' ); ?></h2>
            <p><?php esc_html_e( 'Se listan las copias .wpress de la carpeta SimpleBackup en tu Drive. Al restaurar, se descarga primero al servidor y luego se ejecuta el motor de importacion de All-in-One.', 'simplebackup' ); ?></p>

            <?php if ( is_wp_error( $gdrive_backups ) ) : ?>
                <p><strong><?php echo esc_html( $gdrive_backups->get_error_message() ); ?></strong></p>
            <?php elseif ( empty( $gdrive_backups ) ) : ?>
                <p><?php esc_html_e( 'No hay copias en Google Drive o aun no se pudo acceder a la carpeta.', 'simplebackup' ); ?></p>
            <?php else : ?>
                <table class="widefat striped simplebackup-list">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Archivo', 'simplebackup' ); ?></th>
                            <th><?php esc_html_e( 'Fecha', 'simplebackup' ); ?></th>
                            <th><?php esc_html_e( 'Tamano', 'simplebackup' ); ?></th>
                            <th><?php esc_html_e( 'Accion', 'simplebackup' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $gdrive_backups as $backup ) : ?>
                            <tr>
                                <td><?php echo esc_html( $backup['name'] ); ?></td>
                                <td>
                                    <?php
                                    $timestamp = ! empty( $backup['modifiedTime'] ) ? strtotime( $backup['modifiedTime'] ) : false;
                                    echo $timestamp ? esc_html( wp_date( 'Y-m-d H:i:s', $timestamp ) ) : '-';
                                    ?>
                                </td>
                                <td><?php echo ! empty( $backup['size'] ) ? esc_html( size_format( (int) $backup['size'] ) ) : '-'; ?></td>
                                <td>
                                    <?php if ( $ai1wm_ready ) : ?>
                                        <div class="simplebackup-actions">
                                            <button type="button" class="simplebackup-actions-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Mas acciones', 'simplebackup' ); ?>">
                                                <span class="dashicons dashicons-ellipsis"></span>
                                            </button>
                                            <div class="simplebackup-actions-menu">
                                                <button type="button" class="simplebackup-ai1wm-action simplebackup-ai1wm-restore-gdrive" data-busy-label="<?php echo esc_attr__( 'Preparando restauracion...', 'simplebackup' ); ?>" data-file-id="<?php echo esc_attr( $backup['id'] ); ?>" data-file-name="<?php echo esc_attr( $backup['name'] ); ?>">
                                                    <span class="dashicons dashicons-update"></span>
                                                    <span><?php esc_html_e( 'Restaurar', 'simplebackup' ); ?></span>
                                                </button>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_attr__( 'Vas a eliminar esta copia de Google Drive. Deseas continuar?', 'simplebackup' ); ?>');">
                                                    <?php wp_nonce_field( 'simplebackup_delete_gdrive_backup' ); ?>
                                                    <input type="hidden" name="action" value="simplebackup_delete_gdrive_backup" />
                                                    <input type="hidden" name="file_id" value="<?php echo esc_attr( $backup['id'] ); ?>" />
                                                    <button type="submit" class="is-danger">
                                                        <span class="dashicons dashicons-trash"></span>
                                                        <span><?php esc_html_e( 'Borrar', 'simplebackup' ); ?></span>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php else : ?>
                                        <div class="simplebackup-actions">
                                            <button type="button" class="simplebackup-actions-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Mas acciones', 'simplebackup' ); ?>">
                                                <span class="dashicons dashicons-ellipsis"></span>
                                            </button>
                                            <div class="simplebackup-actions-menu">
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                    <?php wp_nonce_field( 'simplebackup_restore_gdrive' ); ?>
                                                    <input type="hidden" name="action" value="simplebackup_restore_gdrive" />
                                                    <input type="hidden" name="file_id" value="<?php echo esc_attr( $backup['id'] ); ?>" />
                                                    <input type="hidden" name="file_name" value="<?php echo esc_attr( $backup['name'] ); ?>" />
                                                    <button type="submit">
                                                        <span class="dashicons dashicons-update"></span>
                                                        <span><?php esc_html_e( 'Restaurar', 'simplebackup' ); ?></span>
                                                    </button>
                                                </form>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_attr__( 'Vas a eliminar esta copia de Google Drive. Deseas continuar?', 'simplebackup' ); ?>');">
                                                    <?php wp_nonce_field( 'simplebackup_delete_gdrive_backup' ); ?>
                                                    <input type="hidden" name="action" value="simplebackup_delete_gdrive_backup" />
                                                    <input type="hidden" name="file_id" value="<?php echo esc_attr( $backup['id'] ); ?>" />
                                                    <button type="submit" class="is-danger">
                                                        <span class="dashicons dashicons-trash"></span>
                                                        <span><?php esc_html_e( 'Borrar', 'simplebackup' ); ?></span>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $ai1wm_ready ) : ?>
                <form id="ai1wm-export-form" method="post" style="display:none;"></form>
                <form id="ai1wm-import-form" method="post" style="display:none;"></form>
                <script>
                    (function($) {
                        const cfg = <?php echo wp_json_encode( $modal_config ); ?>;
                        const actionButtons = Array.prototype.slice.call(document.querySelectorAll('.simplebackup-ai1wm-action'));
                        const backupButton = document.getElementById('simplebackup-ai1wm-run-backup');
                        let busy = false;
                        let lastFlow = '';
                        let lastImportType = '';
                        let lastExportType = '';
                        let reloadOnModalClose = false;
                        let reloadScheduled = false;

                        const finalImportTypes = {
                            done: true,
                            error: true,
                            pro: true,
                            backup_is_encrypted: true,
                            server_cannot_decrypt: true
                        };

                        const finalExportTypes = {
                            done: true,
                            error: true,
                            download: true
                        };

                        function applyModalBrandingAndLocale() {
                            if (window.ai1wm_locale && cfg.modalLocale) {
                                Object.keys(cfg.modalLocale).forEach(function(key) {
                                    window.ai1wm_locale[key] = cfg.modalLocale[key];
                                });
                            }

                            if (document.getElementById('simplebackup-ai1wm-brand-style')) {
                                return;
                            }

                            const style = document.createElement('style');
                            style.id = 'simplebackup-ai1wm-brand-style';
                            style.textContent = `
                                .ai1wm-modal-container {
                                    border-top: 4px solid #2271b1;
                                }
                                .ai1wm-modal-container .ai1wm-loader,
                                .ai1wm-modal-container section h1 .ai1wm-loader {
                                    width: 44px;
                                    height: 44px;
                                    border-radius: 50%;
                                    background-image: url("${cfg.modalLogoUrl}") !important;
                                    background-size: 28px 28px !important;
                                    background-repeat: no-repeat !important;
                                    background-position: center !important;
                                    background-color: #eef6ff !important;
                                    box-shadow: 0 0 0 0 rgba(34,113,177,.45);
                                    animation: simplebackupPulse 1.6s ease-in-out infinite;
                                }
                                @keyframes simplebackupPulse {
                                    0%   { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,113,177,.45); }
                                    70%  { transform: scale(1);    box-shadow: 0 0 0 12px rgba(34,113,177,0); }
                                    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,113,177,0); }
                                }
                            `;
                            document.head.appendChild(style);
                        }

                        function patchAi1wmModalBehaviour() {
                            if (!window.Ai1wm || !Ai1wm.Export || !Ai1wm.Import) {
                                return;
                            }

                            if (!Ai1wm.Export.prototype.__simplebackupPatched) {
                                const originalExportSetStatus = Ai1wm.Export.prototype.setStatus;
                                Ai1wm.Export.prototype.setStatus = function(status) {
                                    return originalExportSetStatus.call(this, status);
                                };
                                Ai1wm.Export.prototype.__simplebackupPatched = true;
                            }

                            if (!Ai1wm.Import.prototype.__simplebackupPatched) {
                                const originalImportSetStatus = Ai1wm.Import.prototype.setStatus;
                                Ai1wm.Import.prototype.setStatus = function(status) {
                                    if (status && String(status.type).toLowerCase() === 'confirm') {
                                        status = $.extend({}, status, {
                                            title: cfg.restoreConfirmTitle,
                                            message: cfg.restoreConfirmMessage
                                        });
                                    }

                                    return originalImportSetStatus.call(this, status);
                                };
                                Ai1wm.Import.prototype.__simplebackupPatched = true;
                            }
                        }

                        function setBusy(flag, forcedLabel) {
                            busy = !!flag;
                            actionButtons.forEach(function(btn) {
                                if (!btn.dataset.defaultLabel) {
                                    btn.dataset.defaultLabel = btn.textContent;
                                }
                                btn.disabled = busy;
                                if (busy) {
                                    btn.textContent = forcedLabel || btn.dataset.busyLabel || cfg.busyLabel;
                                } else {
                                    btn.textContent = btn.dataset.defaultLabel;
                                }
                            });
                        }

                        function bindActionMenus() {
                            document.querySelectorAll('.simplebackup-actions-toggle').forEach(function(toggle) {
                                toggle.addEventListener('click', function(event) {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    const holder = toggle.closest('.simplebackup-actions');
                                    const willOpen = !holder.classList.contains('is-open');
                                    document.querySelectorAll('.simplebackup-actions.is-open').forEach(function(openMenu) {
                                        openMenu.classList.remove('is-open');
                                        const openToggle = openMenu.querySelector('.simplebackup-actions-toggle');
                                        if (openToggle) {
                                            openToggle.setAttribute('aria-expanded', 'false');
                                        }
                                    });
                                    holder.classList.toggle('is-open', willOpen);
                                    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                                });
                            });

                            document.addEventListener('click', function(event) {
                                if (event.target.closest('.simplebackup-actions')) {
                                    return;
                                }
                                document.querySelectorAll('.simplebackup-actions.is-open').forEach(function(openMenu) {
                                    openMenu.classList.remove('is-open');
                                    const openToggle = openMenu.querySelector('.simplebackup-actions-toggle');
                                    if (openToggle) {
                                        openToggle.setAttribute('aria-expanded', 'false');
                                    }
                                });
                            });
                        }

                        function clearAi1wmStatus() {
                            const body = new URLSearchParams();
                            body.set('action', 'simplebackup_reset_ai1wm_status');
                            body.set('nonce', cfg.ajaxNonce);

                            return window.fetch(cfg.ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: body.toString()
                            })
                                .then(function(response) {
                                    return response.json();
                                })
                                .then(function(json) {
                                    if (!json || !json.success) {
                                        const message = json && json.data && json.data.message ? json.data.message : cfg.unknownErrorMessage;
                                        throw new Error(message);
                                    }
                                    return json.data || {};
                                });
                        }

                        function reloadPage(delayMs) {
                            if (reloadScheduled) {
                                return;
                            }
                            reloadScheduled = true;
                            window.setTimeout(function() {
                                window.location.reload();
                            }, Math.max(0, delayMs || 0));
                        }

                        function canUseAi1wmEngine() {
                            return (
                                window.Ai1wm &&
                                window.Ai1wm.Util &&
                                window.Ai1wm.Import &&
                                window.Ai1wm.Export &&
                                window.ai1wm_import &&
                                window.ai1wm_export
                            );
                        }

                        applyModalBrandingAndLocale();
                        patchAi1wmModalBehaviour();
                        bindActionMenus();

                        function randomStorage() {
                            return (window.Ai1wm && Ai1wm.Util && Ai1wm.Util.random)
                                ? Ai1wm.Util.random(12)
                                : String(Date.now());
                        }

                        function listForm(selector) {
                            if (window.Ai1wm && Ai1wm.Util && Ai1wm.Util.form) {
                                return Ai1wm.Util.form(selector);
                            }
                            return [];
                        }

                        function startExportFlow() {
                            applyModalBrandingAndLocale();
                            patchAi1wmModalBehaviour();
                            reloadOnModalClose = false;
                            lastFlow = 'export';
                            lastExportType = '';
                            const model = new Ai1wm.Export();
                            const storage = randomStorage();
                            const params = listForm('#ai1wm-export-form')
                                .concat({ name: 'storage', value: storage })
                                .concat({ name: 'file', value: 1 })
                                .concat({ name: 'ai1wm_manual_export', value: 1 });

                            model.setParams(params);
                            model.start();
                        }

                        function startImportFlow(archiveName) {
                            applyModalBrandingAndLocale();
                            patchAi1wmModalBehaviour();
                            reloadOnModalClose = false;
                            lastFlow = 'import';
                            lastImportType = '';
                            const model = new Ai1wm.Import();
                            const storage = randomStorage();
                            const params = listForm('#ai1wm-import-form')
                                .concat({ name: 'storage', value: storage })
                                .concat({ name: 'archive', value: archiveName })
                                .concat({ name: 'ai1wm_manual_restore', value: 1 });

                            model.setParams(params);
                            model.start();
                        }

                        function startGdriveRestoreFlow(fileId, fileName) {
                            applyModalBrandingAndLocale();
                            patchAi1wmModalBehaviour();
                            reloadOnModalClose = false;
                            lastFlow = 'import';
                            lastImportType = '';
                            const model = new Ai1wm.Import();
                            model.setStatus({ type: 'info', message: cfg.prepareGdriveMessage });

                            window.onbeforeunload = function() {
                                return (window.ai1wm_locale && ai1wm_locale.stop_importing_your_website) ? ai1wm_locale.stop_importing_your_website : '';
                            };

                            const body = new URLSearchParams();
                            body.set('action', 'simplebackup_prepare_restore_gdrive');
                            body.set('nonce', cfg.ajaxNonce);
                            body.set('file_id', fileId);
                            body.set('file_name', fileName || '');

                            window.fetch(cfg.ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: body.toString()
                            })
                                .then(function(response) {
                                    return response.json();
                                })
                                .then(function(json) {
                                    if (!json || !json.success || !json.data || !json.data.archive) {
                                        const message = (json && json.data && json.data.message) ? json.data.message : cfg.prepareErrorMessage;
                                        throw new Error(message);
                                    }

                                    startImportFlow(json.data.archive);
                                })
                                .catch(function(err) {
                                    window.onbeforeunload = null;
                                    setBusy(false);
                                    model.setStatus({
                                        type: 'error',
                                        title: (window.ai1wm_locale && ai1wm_locale.unable_to_import) ? ai1wm_locale.unable_to_import : 'Import failed',
                                        message: (err && err.message) ? err.message : cfg.unknownErrorMessage
                                    });
                                });
                        }

                        function ensureReleasedWhenModalClosed() {
                            if (!$('.ai1wm-modal-container:visible').length) {
                                if (busy) {
                                    setBusy(false);
                                }
                                if (reloadOnModalClose) {
                                    reloadOnModalClose = false;
                                    reloadPage(300);
                                }
                            }
                        }

                        setInterval(ensureReleasedWhenModalClosed, 1200);

                        $(document).on('ai1wm-import-status.simplebackup', function(_event, status) {
                            if (!status || !status.type) {
                                return;
                            }
                            const type = String(status.type).toLowerCase();
                            lastImportType = type;
                            if (finalImportTypes[type]) {
                                window.onbeforeunload = null;
                                setBusy(false);
                                if (type === 'done') {
                                    reloadOnModalClose = true;
                                }
                            }
                        });

                        $(document).on('ai1wm-export-status.simplebackup', function(_event, status) {
                            if (!status || !status.type) {
                                return;
                            }
                            const type = String(status.type).toLowerCase();
                            lastExportType = type;
                            if (finalExportTypes[type]) {
                                window.onbeforeunload = null;
                                setBusy(false);
                                if (type === 'done' || type === 'download') {
                                    reloadOnModalClose = true;
                                }
                            }
                        });

                        $(document).on('click', '.ai1wm-modal-container .ai1wm-button-red, .ai1wm-modal-container .ai1wm-button-gray, .ai1wm-modal-container .ai1wm-button-green', function() {
                            if ((lastFlow === 'import' && lastImportType === 'done') || (lastFlow === 'export' && (lastExportType === 'done' || lastExportType === 'download'))) {
                                reloadOnModalClose = true;
                            }
                            setTimeout(ensureReleasedWhenModalClosed, 600);
                        });

                        if (backupButton) {
                            backupButton.addEventListener('click', function() {
                                if (busy) {
                                    return;
                                }
                                if (!canUseAi1wmEngine()) {
                                    window.alert(cfg.missingAi1wmMessage);
                                    return;
                                }

                                setBusy(true, cfg.backupBusyLabel);
                                clearAi1wmStatus()
                                    .then(function() {
                                        startExportFlow();
                                    })
                                    .catch(function(err) {
                                        setBusy(false);
                                        window.alert((err && err.message) ? err.message : cfg.unknownErrorMessage);
                                    });
                            });
                        }

                        document.querySelectorAll('.simplebackup-ai1wm-restore-local').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                if (busy) {
                                    return;
                                }
                                if (!canUseAi1wmEngine()) {
                                    window.alert(cfg.missingAi1wmMessage);
                                    return;
                                }

                                const archive = btn.getAttribute('data-archive') || '';
                                if (!archive) {
                                    window.alert(cfg.unknownErrorMessage);
                                    return;
                                }

                                setBusy(true, cfg.restoreBusyLabel);
                                clearAi1wmStatus()
                                    .then(function() {
                                        startImportFlow(archive);
                                    })
                                    .catch(function(err) {
                                        setBusy(false);
                                        window.alert((err && err.message) ? err.message : cfg.unknownErrorMessage);
                                    });
                            });
                        });

                        document.querySelectorAll('.simplebackup-ai1wm-restore-gdrive').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                if (busy) {
                                    return;
                                }
                                if (!canUseAi1wmEngine()) {
                                    window.alert(cfg.missingAi1wmMessage);
                                    return;
                                }

                                const fileId = btn.getAttribute('data-file-id') || '';
                                const fileName = btn.getAttribute('data-file-name') || '';
                                if (!fileId) {
                                    window.alert(cfg.unknownErrorMessage);
                                    return;
                                }

                                setBusy(true, cfg.prepareBusyLabel);
                                clearAi1wmStatus()
                                    .then(function() {
                                        startGdriveRestoreFlow(fileId, fileName);
                                    })
                                    .catch(function(err) {
                                        window.onbeforeunload = null;
                                        setBusy(false);
                                        window.alert((err && err.message) ? err.message : cfg.unknownErrorMessage);
                                    });
                            });
                        });
                    })(jQuery);
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
