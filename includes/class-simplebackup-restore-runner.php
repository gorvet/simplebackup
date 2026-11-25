<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Restore_Runner {

    const WATCH_HOOK        = 'simplebackup_check_restore_state';
    const LOCK_TTL          = 21600; // 6 hours
    const STALE_TIMEOUT     = 43200; // 12 hours
    const WATCH_DELAY       = 30;    // 30 seconds
    const GDRIVE_FOLDER_NAME = 'SimpleBackup';

    public static function init() {
        add_action( self::WATCH_HOOK, array( __CLASS__, 'check_running_state' ) );
    }

    public static function launch_restore_local( $archive, $source = 'local' ) {
        $archive = sanitize_file_name( (string) $archive );
        $source  = sanitize_key( (string) $source );
        if ( '' === $source ) {
            $source = 'local';
        }

        if ( ! self::is_ai1wm_ready() ) {
            return new WP_Error( 'simplebackup_restore_ai1wm_not_ready', __( 'All-in-One WP Migration no esta disponible para restaurar.', 'simplebackup' ) );
        }

        if ( '' === $archive ) {
            return new WP_Error( 'simplebackup_restore_missing_archive', __( 'Debes seleccionar una copia valida para restaurar.', 'simplebackup' ) );
        }

        $file_path = self::resolve_backup_path( $archive );
        if ( empty( $file_path ) || ! is_file( $file_path ) ) {
            return new WP_Error( 'simplebackup_restore_missing_file', __( 'La copia seleccionada no existe en el servidor.', 'simplebackup' ) );
        }

        self::clear_stale_lock();

        $runtime = self::get_runtime();
        if ( self::is_runtime_running( $runtime ) ) {
            return new WP_Error( 'simplebackup_restore_running_runtime', __( 'Hay una restauracion activa registrada. Espera o usa "Forzar desbloqueo".', 'simplebackup' ) );
        }

        if ( get_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT ) ) {
            return new WP_Error( 'simplebackup_restore_locked', __( 'Ya hay una restauracion en curso. Espera a que termine.', 'simplebackup' ) );
        }

        $run_id  = 'simplebackup-restore-' . wp_generate_password( 10, false, false );
        $storage = 'restore-' . wp_generate_password( 10, false, false );
        $params  = self::build_import_params( $archive, $storage, 5 );

        set_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT, $run_id, self::LOCK_TTL );

        if ( defined( 'AI1WM_STATUS' ) ) {
            update_option( AI1WM_STATUS, array() );
        }

        self::set_runtime(
            array(
                'run_id'            => $run_id,
                'status'            => 'running',
                'source'            => $source,
                'archive'           => $archive,
                'storage'           => $storage,
                'started_at'        => time(),
                'updated_at'        => time(),
                'confirmation_sent' => 0,
                'message'           => '',
            )
        );

        self::schedule_state_check( 10 );

        $response = self::dispatch_import_request( $params );

        if ( is_wp_error( $response ) ) {
            self::finalize_error( self::get_runtime(), $response->get_error_message() );
            return $response;
        }

        return true;
    }

    public static function launch_restore_gdrive( $file_id, $file_name = '' ) {
        $archive = self::prepare_gdrive_archive( $file_id, $file_name );
        if ( is_wp_error( $archive ) ) {
            return $archive;
        }

        return self::launch_restore_local( $archive, 'gdrive' );
    }

    public static function prepare_gdrive_archive( $file_id, $file_name = '' ) {
        $file_id   = sanitize_text_field( (string) $file_id );
        $file_name = sanitize_file_name( (string) $file_name );

        if ( '' === $file_id ) {
            return new WP_Error( 'simplebackup_restore_gdrive_missing_file', __( 'No se recibio un archivo de Google Drive para restaurar.', 'simplebackup' ) );
        }

        if ( ! self::is_ai1wm_ready() ) {
            return new WP_Error( 'simplebackup_restore_ai1wm_not_ready', __( 'All-in-One WP Migration no esta disponible para restaurar.', 'simplebackup' ) );
        }

        $settings = SIMPLEBACKUP_Settings::get_settings();
        $account  = SIMPLEBACKUP_Google_Drive::get_service_account( $settings );
        if ( is_wp_error( $account ) ) {
            return $account;
        }

        $token = SIMPLEBACKUP_Google_Drive::get_access_token( $account );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $metadata = SIMPLEBACKUP_Google_Drive::get_file_metadata( $file_id, $token );
        if ( is_wp_error( $metadata ) ) {
            return $metadata;
        }

        if ( '' === $file_name && ! empty( $metadata['name'] ) ) {
            $file_name = sanitize_file_name( (string) $metadata['name'] );
        }

        $archive = self::unique_archive_name( $file_name );
        $path    = self::resolve_backup_path( $archive );

        if ( empty( $path ) ) {
            return new WP_Error( 'simplebackup_restore_path_error', __( 'No se pudo resolver la ruta local de backups para restaurar.', 'simplebackup' ) );
        }

        @ignore_user_abort( true );
        @set_time_limit( 0 );

        $download = SIMPLEBACKUP_Google_Drive::download_file( $file_id, $token, $path );
        if ( is_wp_error( $download ) ) {
            return $download;
        }

        return $archive;
    }

    public static function delete_local_backup( $archive ) {
        $archive = sanitize_file_name( (string) $archive );
        if ( '' === $archive ) {
            return new WP_Error( 'simplebackup_delete_local_missing_archive', __( 'No se indico una copia local valida para borrar.', 'simplebackup' ) );
        }

        $path = self::resolve_backup_path( $archive );
        if ( empty( $path ) || ! is_file( $path ) ) {
            return new WP_Error( 'simplebackup_delete_local_missing_file', __( 'La copia local ya no existe o no se pudo encontrar.', 'simplebackup' ) );
        }

        wp_delete_file( $path );

        if ( is_file( $path ) ) {
            return new WP_Error( 'simplebackup_delete_local_failed', __( 'No se pudo borrar la copia local seleccionada.', 'simplebackup' ) );
        }

        return true;
    }

    public static function delete_gdrive_backup( $file_id ) {
        return SIMPLEBACKUP_Google_Drive::delete_backup( $file_id );
    }

    public static function check_running_state() {
        $runtime = self::get_runtime();

        if ( empty( $runtime['status'] ) || 'running' !== $runtime['status'] ) {
            return;
        }

        $run_id  = isset( $runtime['run_id'] ) ? sanitize_text_field( (string) $runtime['run_id'] ) : '';
        $archive = isset( $runtime['archive'] ) ? sanitize_file_name( (string) $runtime['archive'] ) : '';
        $storage = isset( $runtime['storage'] ) ? sanitize_file_name( (string) $runtime['storage'] ) : '';
        $start   = isset( $runtime['started_at'] ) ? absint( $runtime['started_at'] ) : 0;

        if ( '' === $run_id || '' === $archive || '' === $storage ) {
            self::finalize_error( $runtime, __( 'Estado inconsistente de la restauracion.', 'simplebackup' ) );
            return;
        }

        if ( $start > 0 && ( time() - $start ) > self::STALE_TIMEOUT ) {
            self::finalize_error( $runtime, __( 'La restauracion no finalizo dentro del tiempo esperado.', 'simplebackup' ) );
            return;
        }

        $status = self::get_ai1wm_status();
        if ( empty( $status['type'] ) ) {
            self::schedule_state_check( self::WATCH_DELAY );
            return;
        }

        $type = sanitize_key( (string) $status['type'] );

        if ( 'done' === $type ) {
            self::finalize_success( $runtime, self::read_status_message( $status ) );
            return;
        }

        if ( 'error' === $type || 'server_cannot_decrypt' === $type ) {
            self::finalize_error( $runtime, self::read_status_message( $status ) );
            return;
        }

        if ( 'backup_is_encrypted' === $type ) {
            self::finalize_error( $runtime, __( 'La copia esta encriptada y requiere password. Esta restauracion debe hacerse manualmente desde AI1WM.', 'simplebackup' ) );
            return;
        }

        if ( 'blogs' === $type ) {
            self::finalize_error( $runtime, __( 'La copia requiere seleccion de blogs (multisite). Esta restauracion debe hacerse manualmente desde AI1WM.', 'simplebackup' ) );
            return;
        }

        if ( 'confirm' === $type ) {
            if ( empty( $runtime['confirmation_sent'] ) ) {
                $response = self::dispatch_import_request( self::build_import_params( $archive, $storage, 150 ) );
                if ( is_wp_error( $response ) ) {
                    self::finalize_error( $runtime, $response->get_error_message() );
                    return;
                }

                self::set_runtime(
                    array(
                        'confirmation_sent' => 1,
                        'updated_at'        => time(),
                        'message'           => __( 'Confirmacion de restauracion enviada.', 'simplebackup' ),
                    )
                );
            }

            self::schedule_state_check( self::WATCH_DELAY );
            return;
        }

        self::set_runtime(
            array(
                'updated_at' => time(),
                'message'    => self::read_status_message( $status ),
            )
        );

        self::schedule_state_check( self::WATCH_DELAY );
    }

    public static function get_local_backups( $limit = 20 ) {
        if ( ! defined( 'AI1WM_BACKUPS_PATH' ) || ! is_dir( AI1WM_BACKUPS_PATH ) ) {
            return array();
        }

        $limit = max( 1, min( 200, absint( $limit ) ) );
        $files = glob( trailingslashit( AI1WM_BACKUPS_PATH ) . '*.wpress' );
        if ( ! is_array( $files ) ) {
            return array();
        }

        usort(
            $files,
            static function ( $a, $b ) {
                return filemtime( $b ) <=> filemtime( $a );
            }
        );

        $files   = array_slice( $files, 0, $limit );
        $backups = array();

        foreach ( $files as $file ) {
            if ( ! is_file( $file ) ) {
                continue;
            }

            $backups[] = array(
                'archive' => basename( $file ),
                'mtime'   => (int) filemtime( $file ),
                'size'    => (int) filesize( $file ),
            );
        }

        return $backups;
    }

    public static function get_gdrive_backups( $limit = 50 ) {
        return SIMPLEBACKUP_Google_Drive::list_backups( $limit );
    }

    public static function force_unlock( $message = '' ) {
        $runtime = self::get_runtime();
        if ( '' === trim( (string) $message ) ) {
            $message = __( 'Restauracion desbloqueada manualmente.', 'simplebackup' );
        }

        delete_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT );
        self::clear_state_check();

        $data = array(
            'status'      => 'error',
            'finished_at' => time(),
            'updated_at'  => time(),
            'message'     => sanitize_text_field( (string) $message ),
        );

        if ( ! empty( $runtime['run_id'] ) ) {
            $data['run_id'] = sanitize_text_field( (string) $runtime['run_id'] );
        }
        if ( ! empty( $runtime['source'] ) ) {
            $data['source'] = sanitize_key( (string) $runtime['source'] );
        }
        if ( ! empty( $runtime['archive'] ) ) {
            $data['archive'] = sanitize_file_name( (string) $runtime['archive'] );
        }

        self::set_runtime( $data );

        if ( defined( 'AI1WM_STATUS' ) ) {
            update_option( AI1WM_STATUS, array() );
        }
    }

    private static function dispatch_import_request( $params ) {
        $base_url = apply_filters(
            'ai1wm_http_import_url',
            add_query_arg( array( 'ai1wm_import' => 1 ), admin_url( 'admin-ajax.php?action=ai1wm_import' ) )
        );

        $urls = array( $base_url, set_url_scheme( $base_url, 'http' ) );
        $urls = array_values( array_unique( array_filter( $urls ) ) );

        $last_error = null;

        foreach ( $urls as $url ) {
            $response = wp_remote_request(
                $url,
                array(
                    'method'    => apply_filters( 'ai1wm_http_import_method', 'POST' ),
                    'timeout'   => apply_filters( 'ai1wm_http_import_timeout', 10 ),
                    'blocking'  => false,
                    'sslverify' => apply_filters( 'ai1wm_http_import_sslverify', false ),
                    'headers'   => apply_filters( 'ai1wm_http_import_headers', array() ),
                    'body'      => apply_filters( 'ai1wm_http_import_body', $params ),
                )
            );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code >= 400 ) {
                $last_error = new WP_Error(
                    'simplebackup_restore_dispatch_http_error',
                    sprintf( __( 'Error HTTP %d al iniciar la restauracion.', 'simplebackup' ), $code )
                );
                continue;
            }

            return true;
        }

        if ( is_wp_error( $last_error ) ) {
            return $last_error;
        }

        return new WP_Error( 'simplebackup_restore_dispatch_failed', __( 'No se pudo iniciar la restauracion.', 'simplebackup' ) );
    }

    private static function build_import_params( $archive, $storage, $priority = 5 ) {
        return array(
            'secret_key'           => self::ensure_ai1wm_secret_key(),
            'archive'              => sanitize_file_name( (string) $archive ),
            'storage'              => sanitize_file_name( (string) $storage ),
            'priority'             => max( 5, absint( $priority ) ),
            'ai1wm_manual_restore' => 1,
        );
    }

    private static function finalize_success( $runtime, $message = '' ) {
        delete_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT );
        self::clear_state_check();

        self::set_runtime(
            array(
                'run_id'      => isset( $runtime['run_id'] ) ? sanitize_text_field( (string) $runtime['run_id'] ) : '',
                'status'      => 'success',
                'source'      => isset( $runtime['source'] ) ? sanitize_key( (string) $runtime['source'] ) : 'local',
                'archive'     => isset( $runtime['archive'] ) ? sanitize_file_name( (string) $runtime['archive'] ) : '',
                'finished_at' => time(),
                'updated_at'  => time(),
                'message'     => $message,
            )
        );
    }

    private static function finalize_error( $runtime, $message ) {
        if ( '' === trim( (string) $message ) ) {
            $message = __( 'No fue posible completar la restauracion.', 'simplebackup' );
        }

        delete_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT );
        self::clear_state_check();

        self::set_runtime(
            array(
                'run_id'      => isset( $runtime['run_id'] ) ? sanitize_text_field( (string) $runtime['run_id'] ) : '',
                'status'      => 'error',
                'source'      => isset( $runtime['source'] ) ? sanitize_key( (string) $runtime['source'] ) : 'local',
                'archive'     => isset( $runtime['archive'] ) ? sanitize_file_name( (string) $runtime['archive'] ) : '',
                'finished_at' => time(),
                'updated_at'  => time(),
                'message'     => sanitize_text_field( (string) $message ),
            )
        );
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

    private static function clear_stale_lock() {
        $lock = get_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT );
        $runtime = self::get_runtime();
        $is_running = self::is_runtime_running( $runtime );

        if ( ! $lock ) {
            if ( $is_running ) {
                $started_at = isset( $runtime['started_at'] ) ? absint( $runtime['started_at'] ) : 0;
                if ( $started_at > 0 && ( time() - $started_at ) > self::STALE_TIMEOUT ) {
                    self::force_unlock( __( 'Se detecto una restauracion atascada sin lock y fue liberada.', 'simplebackup' ) );
                }
            }
            return;
        }

        if ( ! $is_running ) {
            delete_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT );
            return;
        }

        if ( (string) $runtime['run_id'] !== (string) $lock ) {
            delete_transient( SIMPLEBACKUP_RESTORE_LOCK_TRANSIENT );
            return;
        }

        $started_at = isset( $runtime['started_at'] ) ? absint( $runtime['started_at'] ) : 0;
        if ( $started_at > 0 && ( time() - $started_at ) > self::STALE_TIMEOUT ) {
            self::finalize_error( $runtime, __( 'Se detecto un bloqueo vencido de restauracion y fue liberado.', 'simplebackup' ) );
        }
    }

    private static function is_runtime_running( $runtime ) {
        if ( ! is_array( $runtime ) ) {
            return false;
        }
        if ( empty( $runtime['status'] ) ) {
            return false;
        }
        return 'running' === sanitize_key( (string) $runtime['status'] );
    }

    private static function get_ai1wm_status() {
        if ( ! defined( 'AI1WM_STATUS' ) ) {
            return array();
        }

        $status = get_option( AI1WM_STATUS, array() );
        return is_array( $status ) ? $status : array();
    }

    private static function read_status_message( $status ) {
        if ( ! is_array( $status ) ) {
            return '';
        }

        if ( ! empty( $status['message'] ) ) {
            return sanitize_text_field( wp_strip_all_tags( (string) $status['message'] ) );
        }

        if ( ! empty( $status['title'] ) ) {
            return sanitize_text_field( wp_strip_all_tags( (string) $status['title'] ) );
        }

        return '';
    }

    private static function get_runtime() {
        $runtime = get_option( SIMPLEBACKUP_OPTION_RESTORE_RUNTIME, array() );
        return is_array( $runtime ) ? $runtime : array();
    }

    private static function set_runtime( $data ) {
        $runtime = self::get_runtime();
        update_option( SIMPLEBACKUP_OPTION_RESTORE_RUNTIME, array_merge( $runtime, $data ) );
    }

    private static function is_ai1wm_ready() {
        if ( ! class_exists( 'Ai1wm_Import_Controller' ) ) {
            return false;
        }

        if ( ! defined( 'AI1WM_SECRET_KEY' ) ) {
            return false;
        }

        if ( ! function_exists( 'ai1wm_backup_path' ) ) {
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

    private static function unique_archive_name( $file_name ) {
        $file_name = sanitize_file_name( (string) $file_name );
        if ( '' === $file_name ) {
            $file_name = 'gdrive-' . gmdate( 'Ymd-His' ) . '.wpress';
        }

        if ( '.wpress' !== strtolower( substr( $file_name, -7 ) ) ) {
            $file_name .= '.wpress';
        }

        $base = pathinfo( $file_name, PATHINFO_FILENAME );
        $ext  = '.wpress';
        $name = $base . $ext;
        $i    = 1;

        while ( is_file( self::resolve_backup_path( $name ) ) ) {
            $name = sprintf( '%s-%d%s', $base, $i, $ext );
            $i++;
        }

        return $name;
    }
}
