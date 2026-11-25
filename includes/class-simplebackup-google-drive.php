<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Google_Drive {

    private const FOLDER_NAME = 'SimpleBackup';

    public static function upload_backup( $archive, $file_path ) {
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

        if ( empty( $file_path ) || ! is_file( $file_path ) ) {
            return new WP_Error( 'simplebackup_gdrive_file_not_found', __( 'No se encontro el backup local para subir a Google Drive.', 'simplebackup' ) );
        }

        $token = self::get_access_token( $service_account );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $folder_id = self::resolve_gdrive_folder_id( $token, $settings, true );
        if ( is_wp_error( $folder_id ) ) {
            return $folder_id;
        }

        $upload = self::gdrive_upload_file_stream( $file_path, basename( $file_path ), $folder_id, $token );

        if ( is_wp_error( $upload ) ) {
            return $upload;
        }

        return array( 'status' => 'uploaded', 'file_id' => $upload );
    }

    public static function list_backups( $limit = 50 ) {
        $settings = SIMPLEBACKUP_Settings::get_settings();
        $account  = self::get_service_account( $settings );
        if ( is_wp_error( $account ) ) {
            return $account;
        }

        $token = self::get_access_token( $account );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $folder_id = self::resolve_gdrive_folder_id( $token, $settings, false );
        if ( is_wp_error( $folder_id ) ) {
            return $folder_id;
        }

        if ( empty( $folder_id ) ) {
            return array();
        }

        $limit = max( 1, min( 200, absint( $limit ) ) );
        $files = array();
        $page_token = '';

        do {
            $query = sprintf(
                "'%s' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder' and name contains '.wpress'",
                str_replace( "'", "\\'", $folder_id )
            );

            $args = array(
                'q'         => $query,
                'fields'    => 'nextPageToken,files(id,name,size,modifiedTime)',
                'spaces'    => 'drive',
                'pageSize'  => min( 100, $limit ),
                'orderBy'   => 'modifiedTime desc',
            );

            if ( '' !== $page_token ) {
                $args['pageToken'] = $page_token;
            }

            $response = wp_remote_get(
                add_query_arg( $args, 'https://www.googleapis.com/drive/v3/files' ),
                array(
                    'timeout' => 20,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code < 200 || $code >= 300 ) {
                return new WP_Error( 'simplebackup_gdrive_list_failed', __( 'No se pudieron listar las copias de Google Drive.', 'simplebackup' ) );
            }

            if ( ! empty( $body['files'] ) && is_array( $body['files'] ) ) {
                foreach ( $body['files'] as $item ) {
                    if ( empty( $item['id'] ) || empty( $item['name'] ) ) {
                        continue;
                    }

                    $files[] = array(
                        'id'           => sanitize_text_field( (string) $item['id'] ),
                        'name'         => sanitize_file_name( (string) $item['name'] ),
                        'size'         => isset( $item['size'] ) ? (int) $item['size'] : 0,
                        'modifiedTime' => isset( $item['modifiedTime'] ) ? sanitize_text_field( (string) $item['modifiedTime'] ) : '',
                    );

                    if ( count( $files ) >= $limit ) {
                        break 2;
                    }
                }
            }

            $page_token = ! empty( $body['nextPageToken'] ) ? sanitize_text_field( (string) $body['nextPageToken'] ) : '';
        } while ( '' !== $page_token );

        return $files;
    }

    public static function delete_backup( $file_id ) {
        $file_id = sanitize_text_field( (string) $file_id );
        if ( '' === $file_id ) {
            return new WP_Error( 'simplebackup_delete_gdrive_missing_file', __( 'No se indico un archivo de Google Drive para borrar.', 'simplebackup' ) );
        }

        $settings = SIMPLEBACKUP_Settings::get_settings();
        $account  = self::get_service_account( $settings );
        if ( is_wp_error( $account ) ) {
            return $account;
        }

        $token = self::get_access_token( $account );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_request(
            sprintf( 'https://www.googleapis.com/drive/v3/files/%s', rawurlencode( $file_id ) ),
            array(
                'method'  => 'DELETE',
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 204 === $code || 200 === $code || 404 === $code ) {
            return true;
        }

        return new WP_Error( 'simplebackup_delete_gdrive_failed', __( 'No se pudo borrar la copia en Google Drive.', 'simplebackup' ) );
    }

    public static function get_service_account( $settings ) {
        if ( empty( $settings['gdrive_enabled'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_disabled', __( 'Activa Google Drive en la configuracion para listar y restaurar copias remotas.', 'simplebackup' ) );
        }

        if ( empty( $settings['gdrive_service_account_json'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_missing_json', __( 'Para usar Google Drive debes pegar el JSON de Service Account en la configuracion.', 'simplebackup' ) );
        }

        $service_account = json_decode( (string) $settings['gdrive_service_account_json'], true );
        if ( ! is_array( $service_account ) ) {
            return new WP_Error( 'simplebackup_gdrive_invalid_json', __( 'El JSON de Service Account no es valido.', 'simplebackup' ) );
        }

        if ( empty( $service_account['client_email'] ) || empty( $service_account['private_key'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_missing_fields', __( 'El JSON de Service Account debe incluir client_email y private_key.', 'simplebackup' ) );
        }

        return $service_account;
    }

    public static function get_access_token( $service_account ) {
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

    public static function get_file_metadata( $file_id, $access_token ) {
        $response = wp_remote_get(
            sprintf( 'https://www.googleapis.com/drive/v3/files/%s?fields=id,name,size,modifiedTime', rawurlencode( $file_id ) ),
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

        if ( $code < 200 || $code >= 300 || empty( $body['id'] ) ) {
            return new WP_Error( 'simplebackup_gdrive_file_not_found', __( 'No se pudo leer el archivo seleccionado de Google Drive.', 'simplebackup' ) );
        }

        return $body;
    }

    public static function download_file( $file_id, $access_token, $target_path ) {
        $target_dir = dirname( $target_path );
        if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'simplebackup_restore_mkdir_failed', __( 'No se pudo crear la carpeta local para descargar la copia.', 'simplebackup' ) );
        }

        $response = wp_remote_get(
            sprintf( 'https://www.googleapis.com/drive/v3/files/%s?alt=media', rawurlencode( $file_id ) ),
            array(
                'timeout' => 300,
                'stream'  => true,
                'filename'=> $target_path,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            if ( is_file( $target_path ) ) {
                wp_delete_file( $target_path );
            }
            return new WP_Error( 'simplebackup_restore_gdrive_download_failed', __( 'No se pudo descargar la copia desde Google Drive.', 'simplebackup' ) );
        }

        if ( ! is_file( $target_path ) || filesize( $target_path ) <= 0 ) {
            return new WP_Error( 'simplebackup_restore_gdrive_empty_download', __( 'La descarga desde Google Drive no genero un archivo valido.', 'simplebackup' ) );
        }

        return true;
    }

    private static function resolve_gdrive_folder_id( $access_token, $settings, $create_if_missing = false ) {
        if ( ! empty( $settings['gdrive_folder_id'] ) ) {
            return sanitize_text_field( (string) $settings['gdrive_folder_id'] );
        }

        $folder_name = apply_filters( 'simplebackup_gdrive_folder_name', self::FOLDER_NAME );
        $folder_name = sanitize_text_field( (string) $folder_name );
        if ( '' === $folder_name ) {
            $folder_name = self::FOLDER_NAME;
        }

        $folder_id = self::gdrive_find_root_folder_by_name( $folder_name, $access_token );
        if ( is_wp_error( $folder_id ) ) {
            return $folder_id;
        }

        if ( empty( $folder_id ) && $create_if_missing ) {
            $folder_id = self::gdrive_create_root_folder( $folder_name, $access_token );
            if ( is_wp_error( $folder_id ) ) {
                return $folder_id;
            }
        }

        if ( ! empty( $folder_id ) ) {
            self::store_gdrive_folder_id_cache( $folder_id );
        }

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
                'q'        => $query,
                'fields'   => 'files(id,name)',
                'pageSize' => 1,
                'spaces'   => 'drive',
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
