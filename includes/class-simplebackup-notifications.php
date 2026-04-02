<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Notifications {

    public static function init() {
        // No bootstrap hooks needed for now.
    }

    public static function send_success( $params ) {
        $settings = SIMPLEBACKUP_Settings::get_settings();
        if ( empty( $settings['notify_success'] ) ) {
            return;
        }

        $recipients = self::get_recipients( $settings );
        if ( empty( $recipients ) ) {
            return;
        }

        $archive = isset( $params['archive'] ) ? sanitize_file_name( $params['archive'] ) : '';
        $size    = '';

        try {
            if ( ! empty( $archive ) ) {
                $size = ai1wm_backup_size( array( 'archive' => $archive ) );
            }
        } catch ( Exception $e ) {
            $size = '';
        }

        $subject = sprintf( '[SimpleBackup] OK - %s', wp_parse_url( site_url(), PHP_URL_HOST ) );

        $lines   = array();
        $lines[] = 'Se completo una copia de seguridad programada.';
        $lines[] = 'Sitio: ' . site_url();
        $lines[] = 'Fecha: ' . wp_date( 'Y-m-d H:i:s' );
        $lines[] = 'Archivo: ' . $archive;
        if ( ! empty( $size ) ) {
            $lines[] = 'Tamano: ' . $size;
        }

        wp_mail( $recipients, $subject, implode( "\n", $lines ) );
    }

    public static function send_error( $params, $error_message ) {
        $settings = SIMPLEBACKUP_Settings::get_settings();
        if ( empty( $settings['notify_error'] ) ) {
            return;
        }

        $recipients = self::get_recipients( $settings );
        if ( empty( $recipients ) ) {
            return;
        }

        $archive = isset( $params['archive'] ) ? sanitize_file_name( $params['archive'] ) : '';
        $subject = sprintf( '[SimpleBackup] ERROR - %s', wp_parse_url( site_url(), PHP_URL_HOST ) );

        $lines   = array();
        $lines[] = 'La copia de seguridad fallo.';
        $lines[] = 'Sitio: ' . site_url();
        $lines[] = 'Fecha: ' . wp_date( 'Y-m-d H:i:s' );
        $lines[] = 'Archivo: ' . $archive;
        $lines[] = 'Error: ' . $error_message;

        wp_mail( $recipients, $subject, implode( "\n", $lines ) );
    }

    private static function get_recipients( $settings ) {
        $emails   = array();
        $admin    = get_option( 'admin_email' );
        $extra    = isset( $settings['emails'] ) ? (string) $settings['emails'] : '';
        $splitter = preg_split( '/[,;\s]+/', $extra );

        if ( is_email( $admin ) ) {
            $emails[] = $admin;
        }

        if ( is_array( $splitter ) ) {
            foreach ( $splitter as $candidate ) {
                $candidate = sanitize_email( $candidate );
                if ( ! empty( $candidate ) && is_email( $candidate ) ) {
                    $emails[] = $candidate;
                }
            }
        }

        return array_values( array_unique( $emails ) );
    }
}
