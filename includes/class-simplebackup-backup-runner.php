<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Backup_Runner {

    const WATCH_HOOK    = 'simplebackup_check_backup_state';
    const LOCK_TTL      = 10800; // 3 hours
    const STALE_TIMEOUT = 21600; // 6 hours
    const WATCH_DELAY   = 60;    // 60 seconds
    const GDRIVE_FOLDER_NAME = 'SimpleBackup';

    public static function init() {
        add_action( 'ai1wm_status_export_init', array( __CLASS__, 'on_export_init' ) );
        add_action( 'ai1wm_status_export_done', array( __CLASS__, 'on_export_done' ) );
        add_action( 'ai1wm_status_export_error', array( __CLASS__, 'on_export_error' ), 10, 2 );

        add_action( self::WATCH_HOOK, array( __CLASS__, 'check_running_state' ) );

        add_filter( 'ai1wm_exclude_content_from_export', array( __CLASS__, 'exclude_backups_from_content_export' ) );
        add_filter( 'ai1wm_exclude_media_from_export', array( __CLASS__, 'exclude_backups_from_media_export' ) );
        add_filter( 'ai1wm_exclude_plugins_from_export', array( __CLASS__, 'exclude_backups_from_plugins_export' ) );
    }

    public static function run_auto() {
        self::launch_backup( 'auto' );
    }

    public static function launch_backup( $trigger = 'auto' ) {
        $trigger = sanitize_key( (string) $trigger );
        if ( '' === $trigger ) {
            $trigger = 'auto';
        }

        if ( ! self::is_ai1wm_ready() ) {
            self::set_runtime(
                array(
                    'status'     => 'error',
                    'message'    => __( 'All-in-One WP Migration no esta disponible.', 'simplebackup' ),
                    'trigger'    => $trigger,
                    'updated_at' => time(),
                )
            );

            return new WP_Error( 'simplebackup_ai1wm_not_ready', __( 'All-in-One WP Migration no esta disponible.', 'simplebackup' ) );
        }

        self::clear_stale_lock();

        if ( get_transient( SIMPLEBACKUP_RUN_LOCK_TRANSIENT ) ) {
            $message = __( 'Ya existe un backup en ejecucion. Espera a que termine.', 'simplebackup' );
            self::set_runtime(
                array(
                    'status'     => 'error',
                    'message'    => $message,
                    'trigger'    => $trigger,
                    'updated_at' => time(),
                )
            );

            return new WP_Error( 'simplebackup_backup_locked', $message );
        }

        $run_id = 'simplebackup-' . wp_generate_password( 10, false, false );
        $params = self::build_export_params( $run_id, $trigger );

        set_transient( SIMPLEBACKUP_RUN_LOCK_TRANSIENT, $run_id, self::LOCK_TTL );

        self::set_runtime(
            array(
                'run_id'      => $run_id,
                'status'      => 'running',
                'trigger'     => $trigger,
                'archive'     => $params['archive'],
                'started_at'  => time(),
                'updated_at'  => time(),
                'message'     => '',
                'gdrive'      => '',
            )
        );

        self::schedule_state_check( 20 );

        $response = self::dispatch_export_request( $params );

        if ( is_wp_error( $response ) ) {
            self::finalize_error( $params, $response->get_error_message() );
            return $response;
        }

        return true;
    }

    public static function on_export_init( $params ) {
        if ( ! self::is_our_run( $params ) ) {
            return;
        }

        self::set_runtime(
            array(
                'run_id'     => $params['simplebackup_run_id'],
                'status'     => 'running',
                'trigger'    => isset( $params['simplebackup_trigger'] ) ? sanitize_key( $params['simplebackup_trigger'] ) : 'auto',
                'archive'    => isset( $params['archive'] ) ? sanitize_file_name( $params['archive'] ) : '',
                'started_at' => isset( $params['simplebackup_started_at'] ) ? absint( $params['simplebackup_started_at'] ) : time(),
                'updated_at' => time(),
                'message'    => '',
            )
        );

        self::schedule_state_check( self::WATCH_DELAY );
    }

    public static function on_export_done( $params ) {
        if ( ! self::is_our_run( $params ) ) {
            return;
        }

        self::finalize_success( $params );
    }

    public static function on_export_error( $params, $exception ) {
        if ( ! self::is_our_run( $params ) ) {
            return;
        }

        $message = __( 'Unknown error', 'simplebackup' );
        if ( $exception instanceof Throwable ) {
            $message = $exception->getMessage();
        }

        self::finalize_error( $params, $message );
    }

    public static function check_running_state() {
        $runtime = self::get_runtime();

        if ( empty( $runtime['status'] ) || 'running' !== $runtime['status'] ) {
            return;
        }

        $run_id  = isset( $runtime['run_id'] ) ? sanitize_text_field( (string) $runtime['run_id'] ) : '';
        $archive = isset( $runtime['archive'] ) ? sanitize_file_name( (string) $runtime['archive'] ) : '';
        $trigger = isset( $runtime['trigger'] ) ? sanitize_key( (string) $runtime['trigger'] ) : 'auto';
        $start   = isset( $runtime['started_at'] ) ? absint( $runtime['started_at'] ) : 0;

        if ( '' === $run_id || '' === $archive ) {
            self::finalize_error(
                array(
                    'simplebackup_run_id'  => $run_id,
                    'simplebackup_trigger' => $trigger,
                    'archive'              => $archive,
                ),
                __( 'Estado inconsistente del backup en ejecucion.', 'simplebackup' )
            );
            return;
        }

        $file_path = self::resolve_backup_path( $archive );
        if ( ! empty( $file_path ) && is_file( $file_path ) ) {
            self::finalize_success(
                array(
                    'simplebackup_run_id'     => $run_id,
                    'simplebackup_trigger'    => $trigger,
                    'simplebackup_started_at' => $start,
                    'archive'                 => $archive,
                )
            );
            return;
        }

        if ( $start > 0 && ( time() - $start ) > self::STALE_TIMEOUT ) {
            self::finalize_error(
                array(
                    'simplebackup_run_id'  => $run_id,
                    'simplebackup_trigger' => $trigger,
                    'archive'              => $archive,
                ),
                __( 'El backup no finalizo dentro del tiempo esperado.', 'simplebackup' )
            );
            return;
        }

        self::schedule_state_check( self::WATCH_DELAY );
    }

    public static function exclude_backups_from_content_export( $filters ) {
        if ( ! self::should_apply_exclusion_filters() ) {
            return $filters;
        }

        if ( ! is_array( $filters ) ) {
            $filters = array();
        }

        if ( defined( 'AI1WM_BACKUPS_PATH' ) ) {
            $filters[] = AI1WM_BACKUPS_PATH;
        }
        if ( defined( 'AI1WM_BACKUPS_NAME' ) ) {
            $filters[] = AI1WM_BACKUPS_NAME;
        }
        $filters[] = 'ai1wm-backups';

        return array_values( array_unique( $filters ) );
    }

    public static function exclude_backups_from_media_export( $filters ) {
        if ( ! self::should_apply_exclusion_filters() ) {
            return $filters;
        }

        if ( ! is_array( $filters ) ) {
            $filters = array();
        }

        if ( defined( 'AI1WM_BACKUPS_PATH' ) ) {
            $filters[] = AI1WM_BACKUPS_PATH;
        }
        $filters[] = 'ai1wm-backups';

        return array_values( array_unique( $filters ) );
    }

    public static function exclude_backups_from_plugins_export( $filters ) {
        if ( ! self::should_apply_exclusion_filters() ) {
            return $filters;
        }

        if ( ! is_array( $filters ) ) {
            $filters = array();
        }

        if ( defined( 'AI1WM_STORAGE_PATH' ) ) {
            $filters[] = AI1WM_STORAGE_PATH;
        }

        if ( defined( 'AI1WM_PATH' ) && function_exists( 'ai1wm_get_plugins_dir' ) ) {
            $plugins_dir = untrailingslashit( ai1wm_get_plugins_dir() );
            $ai1wm_path  = untrailingslashit( AI1WM_PATH );

            if ( 0 === strpos( $ai1wm_path, $plugins_dir ) ) {
                $relative = ltrim( substr( $ai1wm_path, strlen( $plugins_dir ) ), DIRECTORY_SEPARATOR );
                if ( '' !== $relative ) {
                    $filters[] = $relative . DIRECTORY_SEPARATOR . 'storage';
                }
            }
        }

        return array_values( array_unique( $filters ) );
    }

    private static function finalize_success( $params ) {
        if ( ! self::is_our_run( $params ) ) {
            return;
        }

        $run_id  = sanitize_text_field( (string) $params['simplebackup_run_id'] );
        $archive = isset( $params['archive'] ) ? sanitize_file_name( (string) $params['archive'] ) : '';
        $trigger = isset( $params['simplebackup_trigger'] ) ? sanitize_key( (string) $params['simplebackup_trigger'] ) : 'auto';

        $archive_path = self::resolve_backup_path( $archive );
        if ( empty( $archive_path ) || ! is_file( $archive_path ) ) {
            self::finalize_error(
                array(
                    'simplebackup_run_id'  => $run_id,
                    'simplebackup_trigger' => $trigger,
                    'archive'              => $archive,
                ),
                __(
                    'La exportacion termino, pero no se encontro el archivo final del backup. Revisa permisos de escritura y espacio disponible en wp-content/ai1wm-backups.',
                    'simplebackup'
                )
            );
            return;
        }

        $runtime = self::get_runtime();
        if ( isset( $runtime['run_id'], $runtime['status'] ) && $runtime['run_id'] === $run_id && 'success' === $runtime['status'] ) {
            return;
        }

        delete_transient( SIMPLEBACKUP_RUN_LOCK_TRANSIENT );
        self::clear_state_check();

        $runtime_update = array(
            'run_id'      => $run_id,
            'status'      => 'success',
            'trigger'     => $trigger,
            'archive'     => $archive,
            'size'        => self::read_backup_size( $archive ),
            'finished_at' => time(),
            'updated_at'  => time(),
            'message'     => '',
        );

        $gdrive_result = self::upload_to_google_drive( $archive );
        if ( is_wp_error( $gdrive_result ) ) {
            $runtime_update['gdrive']  = 'error';
            $runtime_update['message'] = $gdrive_result->get_error_message();
        } elseif ( is_array( $gdrive_result ) && ! empty( $gdrive_result['status'] ) ) {
            $runtime_update['gdrive'] = $gdrive_result['status'];
        }

        self::set_runtime( $runtime_update );

        SIMPLEBACKUP_Notifications::send_success( $params );
        self::cleanup_local_backups();
    }

    private static function finalize_error( $params, $message ) {
        $run_id  = isset( $params['simplebackup_run_id'] ) ? sanitize_text_field( (string) $params['simplebackup_run_id'] ) : '';
        $archive = isset( $params['archive'] ) ? sanitize_file_name( (string) $params['archive'] ) : '';
        $trigger = isset( $params['simplebackup_trigger'] ) ? sanitize_key( (string) $params['simplebackup_trigger'] ) : 'auto';

        if ( '' === $message ) {
            $message = __( 'No fue posible completar el backup.', 'simplebackup' );
        }

        $runtime = self::get_runtime();
        if ( '' !== $run_id && isset( $runtime['run_id'], $runtime['status'] ) && $runtime['run_id'] === $run_id && 'error' === $runtime['status'] ) {
            return;
        }

        delete_transient( SIMPLEBACKUP_RUN_LOCK_TRANSIENT );
        self::clear_state_check();

        self::set_runtime(
            array(
                'run_id'      => $run_id,
                'status'      => 'error',
                'trigger'     => $trigger,
                'archive'     => $archive,
                'finished_at' => time(),
                'updated_at'  => time(),
                'message'     => $message,
            )
        );

        SIMPLEBACKUP_Notifications::send_error( $params, $message );
    }

    private static function schedule_state_check( $delay = self::WATCH_DELAY ) {
        $delay = max( 10, absint( $delay ) );
        $next  = time() + $delay;

        while ( $existing = wp_next_scheduled( self::WATCH_HOOK ) ) {
            if ( $existing <= ( $next + 15 ) ) {
                return;
            }
            wp_unschedule_event( $existing, self::WATCH_HOOK );
        }

        wp_schedule_single_event( $next, self::WATCH_HOOK );
    }

    private static function clear_state_check() {
        while ( $next = wp_next_scheduled( self::WATCH_HOOK ) ) {
            wp_unschedule_event( $next, self::WATCH_HOOK );
        }
    }

    private static function should_apply_exclusion_filters() {
        $run_id = self::get_current_run_id();
        return '' !== $run_id && 0 === strpos( $run_id, 'simplebackup-' );
    }

    private static function get_current_run_id() {
        global $ai1wm_params;

        if ( is_array( $ai1wm_params ) && ! empty( $ai1wm_params['simplebackup_run_id'] ) ) {
            return sanitize_text_field( (string) $ai1wm_params['simplebackup_run_id'] );
        }

        if ( isset( $_REQUEST['simplebackup_run_id'] ) ) {
            return sanitize_text_field( wp_unslash( (string) $_REQUEST['simplebackup_run_id'] ) );
        }

        return '';
    }

    private static function build_export_params( $run_id, $trigger ) {
        $archive = function_exists( 'ai1wm_archive_file' ) ? ai1wm_archive_file() : gmdate( 'Ymd-His' ) . '.wpress';
        if ( '.wpress' !== substr( $archive, -7 ) ) {
            $archive .= '.wpress';
        }
        $archive = str_replace( '.wpress', '-' . $run_id . '.wpress', $archive );

        return array(
            'secret_key'              => self::ensure_ai1wm_secret_key(),
            'archive'                 => $archive,
            'file'                    => 1,
            'priority'                => 5,
            'simplebackup_run_id'     => $run_id,
            'simplebackup_trigger'    => $trigger,
            'simplebackup_started_at' => time(),
            'options'                 => array(),
        );
    }

    private static function dispatch_export_request( $params ) {
        $base_url = apply_filters(
            'ai1wm_http_export_url',
            add_query_arg( array( 'ai1wm_import' => 1 ), admin_url( 'admin-ajax.php?action=ai1wm_export' ) )
        );

        $urls = array( $base_url, set_url_scheme( $base_url, 'http' ) );
        $urls = array_values( array_unique( array_filter( $urls ) ) );

        $last_error = null;

        foreach ( $urls as $url ) {
            $response = wp_remote_request(
                $url,
                array(
                    'method'    => apply_filters( 'ai1wm_http_export_method', 'POST' ),
                    'timeout'   => apply_filters( 'ai1wm_http_export_timeout', 10 ),
                    'blocking'  => false,
                    'sslverify' => apply_filters( 'ai1wm_http_export_sslverify', false ),
                    'headers'   => apply_filters( 'ai1wm_http_export_headers', array() ),
                    'body'      => apply_filters( 'ai1wm_http_export_body', $params ),
                )
            );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code >= 400 ) {
                $last_error = new WP_Error(
                    'simplebackup_dispatch_http_error',
                    sprintf( __( 'Error HTTP %d al iniciar la exportacion.', 'simplebackup' ), $code )
                );
                continue;
            }

            return true;
        }

        if ( is_wp_error( $last_error ) ) {
            return $last_error;
        }

        return new WP_Error( 'simplebackup_dispatch_failed', __( 'No se pudo iniciar la exportacion.', 'simplebackup' ) );
    }

    private static function is_our_run( $params ) {
        if ( ! is_array( $params ) || empty( $params['simplebackup_run_id'] ) ) {
            return false;
        }

        return 0 === strpos( (string) $params['simplebackup_run_id'], 'simplebackup-' );
    }

    private static function is_ai1wm_ready() {
        if ( ! class_exists( 'Ai1wm_Export_Controller' ) ) {
            return false;
        }

        if ( ! defined( 'AI1WM_SECRET_KEY' ) ) {
            return false;
        }

        if ( ! function_exists( 'ai1wm_archive_file' ) ) {
            return false;
        }

        return '' !== self::ensure_ai1wm_secret_key();
    }

    private static function ensure_ai1wm_secret_key() {
        if ( ! defined( 'AI1WM_SECRET_KEY' ) ) {
            return '';
        }

        $secret = (string) get_option( AI1WM_SECRET_KEY, '' );
        $secret = trim( $secret );

        if ( '' !== $secret ) {
            return $secret;
        }

        if ( function_exists( 'ai1wm_generate_random_string' ) ) {
            $secret = (string) ai1wm_generate_random_string( 12 );
        }

        if ( '' === $secret ) {
            $secret = wp_generate_password( 12, false, false );
        }

        update_option( AI1WM_SECRET_KEY, $secret );
        return $secret;
    }

    private static function clear_stale_lock() {
        $lock = get_transient( SIMPLEBACKUP_RUN_LOCK_TRANSIENT );
        if ( ! $lock ) {
            return;
        }

        $runtime = self::get_runtime();

        if ( empty( $runtime['run_id'] ) || empty( $runtime['status'] ) || 'running' !== $runtime['status'] ) {
            delete_transient( SIMPLEBACKUP_RUN_LOCK_TRANSIENT );
            return;
        }

        if ( (string) $runtime['run_id'] !== (string) $lock ) {
            delete_transient( SIMPLEBACKUP_RUN_LOCK_TRANSIENT );
            return;
        }

        $started_at = isset( $runtime['started_at'] ) ? absint( $runtime['started_at'] ) : 0;
        if ( $started_at > 0 && ( time() - $started_at ) > self::STALE_TIMEOUT ) {
            self::finalize_error(
                array(
                    'simplebackup_run_id'  => (string) $runtime['run_id'],
                    'simplebackup_trigger' => isset( $runtime['trigger'] ) ? (string) $runtime['trigger'] : 'auto',
                    'archive'              => isset( $runtime['archive'] ) ? (string) $runtime['archive'] : '',
                ),
                __( 'Se detecto un bloqueo de backup vencido y fue liberado.', 'simplebackup' )
            );
        }
    }

    private static function get_runtime() {
        $runtime = get_option( SIMPLEBACKUP_OPTION_RUNTIME, array() );
        return is_array( $runtime ) ? $runtime : array();
    }

    private static function set_runtime( $data ) {
        $runtime = self::get_runtime();
        update_option( SIMPLEBACKUP_OPTION_RUNTIME, array_merge( $runtime, $data ) );
    }

    private static function resolve_backup_path( $archive ) {
        $archive = sanitize_file_name( (string) $archive );
        if ( '' === $archive ) {
            return '';
        }

        if ( function_exists( 'ai1wm_backup_path' ) ) {
            try {
                return ai1wm_backup_path( array( 'archive' => $archive ) );
            } catch ( Throwable $e ) {
                // Fallback below.
            }
        }

        if ( defined( 'AI1WM_BACKUPS_PATH' ) ) {
            return trailingslashit( AI1WM_BACKUPS_PATH ) . $archive;
        }

        return '';
    }

    private static function read_backup_size( $archive ) {
        $archive = sanitize_file_name( (string) $archive );
        if ( '' === $archive ) {
            return '';
        }

        if ( function_exists( 'ai1wm_backup_size' ) ) {
            try {
                $size = ai1wm_backup_size( array( 'archive' => $archive ) );
                if ( ! empty( $size ) ) {
                    return $size;
                }
            } catch ( Throwable $e ) {
                // Fallback below.
            }
        }

        $path = self::resolve_backup_path( $archive );
        if ( ! empty( $path ) && is_file( $path ) ) {
            return size_format( filesize( $path ) );
        }

        return '';
    }

    private static function cleanup_local_backups() {
        if ( ! defined( 'AI1WM_BACKUPS_PATH' ) || ! is_dir( AI1WM_BACKUPS_PATH ) ) {
            return;
        }

        $settings = SIMPLEBACKUP_Settings::get_settings();
        $keep     = isset( $settings['keep_local'] ) ? absint( $settings['keep_local'] ) : 5;

        if ( $keep < 1 ) {
            $keep = 1;
        }

        $simplebackup_files = glob( trailingslashit( AI1WM_BACKUPS_PATH ) . '*simplebackup-*.wpress' );
        if ( is_array( $simplebackup_files ) && count( $simplebackup_files ) > $keep ) {
            usort(
                $simplebackup_files,
                static function ( $a, $b ) {
                    return filemtime( $b ) <=> filemtime( $a );
                }
            );

            $remove = array_slice( $simplebackup_files, $keep );
            foreach ( $remove as $file ) {
                if ( is_file( $file ) ) {
                    wp_delete_file( $file );
                }
            }
        }

        if ( ! empty( $settings['auto_delete_old'] ) ) {
            $days = isset( $settings['delete_older_than_days'] ) ? absint( $settings['delete_older_than_days'] ) : 30;
            if ( $days < 1 ) {
                $days = 1;
            }

            $cutoff      = time() - ( $days * DAY_IN_SECONDS );
            $all_backups = glob( trailingslashit( AI1WM_BACKUPS_PATH ) . '*.wpress' );

            if ( is_array( $all_backups ) ) {
                foreach ( $all_backups as $file ) {
                    if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
                        wp_delete_file( $file );
                    }
                }
            }
        }
    }

    private static function upload_to_google_drive( $archive ) {
        $settings = SIMPLEBACKUP_Settings::get_settings();

        if ( empty( $settings['gdrive_enabled'] ) ) {
            return array( 'status' => 'disabled' );
        }

        if ( empty( $settings['gdrive_service_account_json'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_missing_json', __( 'Google Drive habilitado, pero falta el JSON de Service Account.', 'simplebackup' ) );
        }

        $service_account = json_decode( $settings['gdrive_service_account_json'], true );
        if ( ! is_array( $service_account ) ) {
            return new WP_Error( 'simplebackup_gdrive_invalid_json', __( 'El JSON de Service Account no es valido.', 'simplebackup' ) );
        }

        $required = array( 'client_email', 'private_key' );
        foreach ( $required as $field ) {
            if ( empty( $service_account[ $field ] ) ) {
                return new WP_Error( 'simplebackup_gdrive_missing_field', sprintf( __( 'Falta el campo %s en el JSON de Service Account.', 'simplebackup' ), $field ) );
            }
        }

        if ( empty( $archive ) ) {
            return new WP_Error( 'simplebackup_gdrive_missing_archive', __( 'No se encontro el nombre de archivo para subir a Google Drive.', 'simplebackup' ) );
        }

        $file_path = self::resolve_backup_path( $archive );
        if ( empty( $file_path ) || ! is_file( $file_path ) ) {
            return new WP_Error( 'simplebackup_gdrive_file_not_found', __( 'No se encontro el backup local para subir a Google Drive.', 'simplebackup' ) );
        }

        $token = self::gdrive_get_access_token( $service_account );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $folder_id = self::ensure_gdrive_folder_id( $token, $settings );
        if ( is_wp_error( $folder_id ) ) {
            return $folder_id;
        }

        $upload    = self::gdrive_upload_file_stream( $file_path, basename( $file_path ), $folder_id, $token );

        if ( is_wp_error( $upload ) ) {
            return $upload;
        }

        return array( 'status' => 'uploaded', 'file_id' => $upload );
    }

    private static function gdrive_get_access_token( $service_account ) {
        if ( ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'simplebackup_gdrive_openssl', __( 'OpenSSL es requerido para autenticar Google Drive.', 'simplebackup' ) );
        }

        $token_uri = ! empty( $service_account['token_uri'] ) ? $service_account['token_uri'] : 'https://oauth2.googleapis.com/token';
        $now       = time();

        $header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
        $claim  = array(
            'iss'   => $service_account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud'   => $token_uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        );

        $unsigned = self::gdrive_base64url( wp_json_encode( $header ) ) . '.' . self::gdrive_base64url( wp_json_encode( $claim ) );

        $signature = '';
        $ok        = openssl_sign( $unsigned, $signature, $service_account['private_key'], OPENSSL_ALGO_SHA256 );

        if ( ! $ok ) {
            return new WP_Error( 'simplebackup_gdrive_sign_failed', __( 'No se pudo firmar el JWT para Google Drive.', 'simplebackup' ) );
        }

        $jwt = $unsigned . '.' . self::gdrive_base64url( $signature );

        $response = wp_remote_post(
            $token_uri,
            array(
                'timeout' => 20,
                'body'    => array(
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || empty( $body['access_token'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_token_failed', __( 'Google no devolvio un access token valido.', 'simplebackup' ) );
        }

        return $body['access_token'];
    }

    private static function ensure_gdrive_folder_id( $access_token, $settings ) {
        if ( ! empty( $settings['gdrive_folder_id'] ) ) {
            return sanitize_text_field( (string) $settings['gdrive_folder_id'] );
        }

        $folder_name = apply_filters( 'simplebackup_gdrive_folder_name', self::GDRIVE_FOLDER_NAME );
        $folder_name = sanitize_text_field( (string) $folder_name );
        if ( '' === $folder_name ) {
            $folder_name = self::GDRIVE_FOLDER_NAME;
        }

        $folder_id = self::gdrive_find_root_folder_by_name( $folder_name, $access_token );
        if ( is_wp_error( $folder_id ) ) {
            return $folder_id;
        }

        if ( empty( $folder_id ) ) {
            $folder_id = self::gdrive_create_root_folder( $folder_name, $access_token );
            if ( is_wp_error( $folder_id ) ) {
                return $folder_id;
            }
        }

        self::store_gdrive_folder_id_cache( $folder_id );

        return $folder_id;
    }

    private static function gdrive_find_root_folder_by_name( $folder_name, $access_token ) {
        $escaped_name = str_replace( "'", "\\'", $folder_name );
        $query        = sprintf(
            "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false and 'root' in parents",
            $escaped_name
        );

        $url = add_query_arg(
            array(
                'q'       => $query,
                'fields'  => 'files(id,name)',
                'pageSize'=> 1,
                'spaces'  => 'drive',
            ),
            'https://www.googleapis.com/drive/v3/files'
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'simplebackup_gdrive_find_folder_failed', __( 'No se pudo buscar la carpeta de SimpleBackup en Google Drive.', 'simplebackup' ) );
        }

        if ( ! empty( $body['files'][0]['id'] ) ) {
            return sanitize_text_field( (string) $body['files'][0]['id'] );
        }

        return '';
    }

    private static function gdrive_create_root_folder( $folder_name, $access_token ) {
        $response = wp_remote_post(
            'https://www.googleapis.com/drive/v3/files',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ),
                'body'    => wp_json_encode(
                    array(
                        'name'     => $folder_name,
                        'mimeType' => 'application/vnd.google-apps.folder',
                        'parents'  => array( 'root' ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || empty( $body['id'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_create_folder_failed', __( 'No se pudo crear la carpeta raiz de SimpleBackup en Google Drive.', 'simplebackup' ) );
        }

        return sanitize_text_field( (string) $body['id'] );
    }

    private static function store_gdrive_folder_id_cache( $folder_id ) {
        $folder_id = sanitize_text_field( (string) $folder_id );
        if ( '' === $folder_id ) {
            return;
        }

        $settings = SIMPLEBACKUP_Settings::get_settings();
        if ( ! empty( $settings['gdrive_folder_id'] ) && $folder_id === $settings['gdrive_folder_id'] ) {
            return;
        }

        $settings['gdrive_folder_id'] = $folder_id;
        update_option( SIMPLEBACKUP_OPTION_SETTINGS, $settings );
    }

    private static function gdrive_upload_file_stream( $file_path, $file_name, $folder_id, $access_token ) {
        if ( ! function_exists( 'curl_init' ) ) {
            return new WP_Error( 'simplebackup_gdrive_curl_required', __( 'cURL es requerido para subir archivos grandes a Google Drive.', 'simplebackup' ) );
        }

        $metadata = array( 'name' => $file_name );
        if ( ! empty( $folder_id ) ) {
            $metadata['parents'] = array( $folder_id );
        }

        $session_headers = array();
        $ch              = curl_init( 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable' );

        curl_setopt_array(
            $ch,
            array(
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADERFUNCTION => static function ( $curl, $header ) use ( &$session_headers ) {
                    $len = strlen( $header );
                    $key = explode( ':', $header, 2 );
                    if ( count( $key ) === 2 ) {
                        $session_headers[ strtolower( trim( $key[0] ) ) ] = trim( $key[1] );
                    }

                    return $len;
                },
                CURLOPT_HTTPHEADER     => array(
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json; charset=UTF-8',
                    'X-Upload-Content-Type: application/octet-stream',
                    'X-Upload-Content-Length: ' . filesize( $file_path ),
                ),
                CURLOPT_POSTFIELDS     => wp_json_encode( $metadata ),
            )
        );

        curl_exec( $ch );
        $code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );

        if ( $code < 200 || $code >= 300 || empty( $session_headers['location'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_session_failed', __( 'No se pudo crear la sesion de subida a Google Drive.', 'simplebackup' ) . ' ' . $error );
        }

        $upload_url = $session_headers['location'];
        $fh         = fopen( $file_path, 'rb' );

        if ( ! $fh ) {
            return new WP_Error( 'simplebackup_gdrive_file_open_failed', __( 'No se pudo abrir el archivo local para subirlo a Google Drive.', 'simplebackup' ) );
        }

        $ch = curl_init( $upload_url );
        curl_setopt_array(
            $ch,
            array(
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_UPLOAD         => true,
                CURLOPT_INFILE         => $fh,
                CURLOPT_INFILESIZE     => filesize( $file_path ),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => array(
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/octet-stream',
                    'Content-Length: ' . filesize( $file_path ),
                ),
            )
        );

        $body  = curl_exec( $ch );
        $code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error = curl_error( $ch );

        curl_close( $ch );
        fclose( $fh );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'simplebackup_gdrive_upload_failed', __( 'La subida a Google Drive fallo.', 'simplebackup' ) . ' ' . $error );
        }

        $decoded = json_decode( (string) $body, true );
        return ! empty( $decoded['id'] ) ? $decoded['id'] : true;
    }

    private static function gdrive_base64url( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }
}
