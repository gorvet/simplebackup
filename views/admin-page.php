<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
    <h1><?php esc_html_e( 'SimpleBackup', 'simplebackup' ); ?></h1>
    <p><?php esc_html_e( 'Automatiza copias de seguridad con All-in-One WP Migration.', 'simplebackup' ); ?></p>

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
        <script type="application/json" id="simplebackup-modal-config"><?php echo wp_json_encode( $modal_config, JSON_HEX_TAG ); ?></script>
    <?php endif; ?>
</div>
