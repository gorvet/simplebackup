<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Update {

    private const SLUG = 'simplebackup';
    private const LEGACY_SLUG = 'simplebackup-ai1wm';

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_updates' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
    }

    public static function check_for_updates( $transient ) {
        if ( ! isset( $transient->checked[ SIMPLEBACKUP_SLUG ] ) ) {
            return $transient;
        }

        $installed_version = $transient->checked[ SIMPLEBACKUP_SLUG ];
        $info = self::fetch_remote_info();

        $offer = (object) array(
            'id'           => SIMPLEBACKUP_UPDATE_INFO_URL,
            'slug'         => self::SLUG,
            'plugin'       => SIMPLEBACKUP_SLUG,
            'new_version'  => $info->version ?? $installed_version,
            'url'          => $info->homepage ?? 'https://github.com/gorvet/simplebackup',
            'package'      => $info->download_url ?? '',
            'tested'       => $info->tested ?? '6.8',
            'requires'     => $info->requires ?? '5.8',
            'requires_php' => $info->requires_php ?? '7.4',
            'icons'        => array( 'default' => $info->icon ?? SIMPLEBACKUP_URL . 'assets/icon.png' ),
        );

        unset( $transient->response[ SIMPLEBACKUP_SLUG ], $transient->no_update[ SIMPLEBACKUP_SLUG ] );

        if ( $info && version_compare( $info->version, $installed_version, '>' ) ) {
            $offer->upgrade_notice = $info->upgrade_notice ?? '';
            $transient->response[ SIMPLEBACKUP_SLUG ] = $offer;
        } else {
            $transient->no_update[ SIMPLEBACKUP_SLUG ] = $offer;
        }

        return $transient;
    }

    public static function plugins_api( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! in_array( $args->slug ?? '', array( self::SLUG, self::LEGACY_SLUG ), true ) ) {
            return $result;
        }

        $info = self::fetch_remote_info();
        if ( ! $info ) {
            return $result;
        }

        return (object) array(
            'name'          => $info->name ?? 'SimpleBackup',
            'slug'          => self::SLUG,
            'version'       => $info->version,
            'author'        => $info->author ?? 'Juank de Gorvet',
            'requires'      => $info->requires ?? '5.8',
            'tested'        => $info->tested ?? '6.8',
            'requires_php'  => $info->requires_php ?? '7.4',
            'last_updated'  => $info->last_updated ?? '',
            'sections'      => (array) ( $info->sections ?? array(
                'description' => __( 'SimpleBackup addon for All-in-One WP Migration.', 'simplebackup' ),
            ) ),
            'homepage'      => $info->homepage ?? 'https://github.com/gorvet/simplebackup',
            'download_link' => $info->download_url,
            'banners'       => (array) ( $info->banners ?? array() ),
        );
    }

    private static function fetch_remote_info() {
        static $info = false;
        if ( false !== $info ) {
            return $info;
        }

        $info = null;
        $response = wp_safe_remote_get( SIMPLEBACKUP_UPDATE_INFO_URL, array( 'timeout' => 5 ) );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! is_object( $data ) || ! isset( $data->version, $data->download_url ) ||
            ! is_string( $data->version ) || ! preg_match( '/^\d+(?:\.\d+)+(?:[-+][a-z0-9][a-z0-9.+-]*)?$/iD', $data->version ) ||
            ! is_string( $data->download_url ) || ! filter_var( $data->download_url, FILTER_VALIDATE_URL ) ||
            'https' !== wp_parse_url( $data->download_url, PHP_URL_SCHEME ) ||
            ! in_array( $data->slug ?? self::SLUG, array( self::SLUG, self::LEGACY_SLUG ), true )
        ) {
            return null;
        }

        foreach ( array( 'name', 'author', 'homepage', 'requires', 'tested', 'requires_php', 'last_updated', 'icon', 'upgrade_notice' ) as $field ) {
            if ( isset( $data->$field ) && ( ! is_string( $data->$field ) || '' === $data->$field ) ) {
                unset( $data->$field );
            }
        }

        $info = $data;
        return $info;
    }
}
