<?php

defined( 'ABSPATH' ) || exit;

class SIMPLEBACKUP_Update {

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_updates' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
    }

    public static function check_for_updates( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $info = self::fetch_remote_info();
        if ( empty( $info ) || empty( $info->version ) ) {
            return $transient;
        }

        if ( version_compare( $info->version, SIMPLEBACKUP_VERSION, '>' ) ) {
            $transient->response[ SIMPLEBACKUP_SLUG ] = (object) array(
                'slug'          => ! empty( $info->slug ) ? $info->slug : 'simplebackup-ai1wm',
                'plugin'        => SIMPLEBACKUP_SLUG,
                'new_version'   => $info->version,
                'url'           => ! empty( $info->homepage ) ? $info->homepage : '',
                'package'       => ! empty( $info->download_url ) ? $info->download_url : '',
                'tested'        => ! empty( $info->tested ) ? $info->tested : '6.8.2',
                'requires'      => ! empty( $info->requires ) ? $info->requires : '5.8',
                'requires_php'  => ! empty( $info->requires_php ) ? $info->requires_php : '7.4',
                'icons'         => array(
                    'default' => ! empty( $info->icon ) ? $info->icon : SIMPLEBACKUP_URL . 'assets/icon.png',
                ),
                'upgrade_notice' => ! empty( $info->upgrade_notice ) ? $info->upgrade_notice : __( 'Recommended update for stability and security.', 'simplebackup' ),
            );
        }

        return $transient;
    }

    public static function plugins_api( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'simplebackup-ai1wm' !== $args->slug ) {
            return $result;
        }

        $info = self::fetch_remote_info();
        if ( empty( $info ) || empty( $info->version ) ) {
            return $result;
        }

        return (object) array(
            'name'          => ! empty( $info->name ) ? $info->name : 'SimpleBackup',
            'slug'          => ! empty( $info->slug ) ? $info->slug : 'simplebackup-ai1wm',
            'version'       => $info->version,
            'author'        => ! empty( $info->author ) ? $info->author : 'Juank de Gorvet',
            'requires'      => ! empty( $info->requires ) ? $info->requires : '5.8',
            'tested'        => ! empty( $info->tested ) ? $info->tested : '6.8.2',
            'requires_php'  => ! empty( $info->requires_php ) ? $info->requires_php : '7.4',
            'last_updated'  => ! empty( $info->last_updated ) ? $info->last_updated : gmdate( 'Y-m-d' ),
            'sections'      => ! empty( $info->sections ) ? (array) $info->sections : array(
                'description' => __( 'SimpleBackup addon for All-in-One WP Migration.', 'simplebackup' ),
            ),
            'homepage'      => ! empty( $info->homepage ) ? $info->homepage : '',
            'download_link' => ! empty( $info->download_url ) ? $info->download_url : '',
            'banners'       => ! empty( $info->banners ) ? (array) $info->banners : array(),
        );
    }

    private static function fetch_remote_info() {
        if ( empty( SIMPLEBACKUP_UPDATE_INFO_URL ) ) {
            return null;
        }

        $response = wp_remote_get( SIMPLEBACKUP_UPDATE_INFO_URL, array( 'timeout' => 5 ) );
        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return null;
        }

        $data = json_decode( $body );
        if ( ! is_object( $data ) ) {
            return null;
        }

        return $data;
    }
}
