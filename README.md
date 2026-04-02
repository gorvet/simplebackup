# SimpleBackup

Plugin de WordPress para automatizar copias de seguridad con [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/). Permite programar backups, ejecutarlos manualmente, conservar un numero limitado de copias locales, enviar notificaciones por correo y subir las copias a Google Drive sin depender de la extension Pro de All-in-One WP Migration.

## Que hace este plugin

SimpleBackup actua como una capa de automatizacion sobre All-in-One WP Migration:

- Programa backups automaticos diarios, semanales o mensuales.
- Permite lanzar un backup manual desde el panel.
- Guarda el estado de la ultima ejecucion.
- Puede subir cada archivo `.wpress` a Google Drive usando una Service Account.
- Lista copias locales y remotas para restaurar o borrar.
- Permite restaurar desde archivos locales o desde Google Drive.
- Envia correos cuando el backup termina bien o cuando falla.
- Mantiene actualizaciones remotas del plugin desde un endpoint propio.

## Requisitos

- WordPress 5.8 o superior.
- PHP 7.4 o superior.
- Plugin **All-in-One WP Migration** instalado y activo.
- WP-Cron habilitado para que la automatizacion funcione correctamente.
- Para Google Drive:
  - OpenSSL habilitado en PHP.
  - cURL habilitado en PHP.
  - Una Service Account de Google Cloud con acceso a Drive.

## Instalacion

1. Coloca la carpeta del plugin dentro de `wp-content/plugins/`.
2. Se recomienda que la carpeta se llame `simplebackup`.
3. Activa **SimpleBackup** desde el administrador de WordPress.
4. Verifica que **All-in-One WP Migration** este activo.
5. En el menu lateral de WordPress abre `SimpleBackup`.

## Como funciona

### Flujo de backup

1. SimpleBackup programa una tarea con WP-Cron segun la frecuencia y la hora configuradas.
2. Cuando llega el momento, lanza internamente una exportacion de All-in-One WP Migration.
3. Mientras el backup corre, guarda un lock temporal para evitar duplicados.
4. Cuando el archivo `.wpress` aparece en `wp-content/ai1wm-backups`, marca la ejecucion como exitosa.
5. Si Google Drive esta activo, intenta subir el archivo recien generado.
6. Despues limpia copias locales antiguas segun tu configuracion.
7. Si activaste notificaciones, envia correo de exito o error.

### Flujo de restauracion

Hay dos fuentes de restauracion:

- `Copias locales`: usa un archivo `.wpress` ya existente en el servidor.
- `Google Drive`: descarga primero el archivo desde Drive al directorio local de backups y luego dispara la importacion con All-in-One WP Migration.

El plugin monitoriza el estado de la restauracion y libera locks vencidos si detecta procesos atascados.

## Configuracion disponible

Desde la pantalla del plugin puedes definir:

- `Activar automatizacion`: enciende o apaga los backups automaticos.
- `Frecuencia`: diaria, semanal o mensual.
- `Horario`: hora base segun la zona horaria de WordPress.
- `Copias locales a conservar`: maximo de backups locales creados por SimpleBackup.
- `Borrado automatico por antiguedad`: elimina copias viejas si se activa.
- `Dias maximos`: umbral de antiguedad para el borrado automatico.
- `Notificar exito`: envia email cuando el backup termina correctamente.
- `Notificar error`: envia email cuando ocurre un fallo.
- `Correos adicionales`: destinatarios extra, separados por coma.
- `Google Drive`: activa la subida remota.
- `Service Account JSON`: credenciales completas de Google Cloud para operar con Drive.

## Exportar a Google Drive

SimpleBackup no usa la extension de pago de All-in-One WP Migration para Google Drive. Implementa su propia integracion mediante la API de Google Drive y una **Service Account**.

### Como configurarlo

1. Crea un proyecto en Google Cloud.
2. Habilita la API de Google Drive.
3. Crea una **Service Account**.
4. Genera una clave JSON.
5. Pega el contenido completo del JSON en la configuracion del plugin.
6. Activa la opcion `Google Drive (propio)`.

### Que hace internamente

- Genera un JWT firmado con la `private_key` del JSON.
- Solicita un `access_token` a Google.
- Busca una carpeta raiz llamada `SimpleBackup` en el Drive de la cuenta.
- Si no existe, la crea automaticamente.
- Sube el archivo `.wpress` mediante una subida resumable.
- Guarda el `folder_id` para reutilizarlo en futuras ejecuciones.

### Importante sobre la Service Account

La carpeta se crea en el Drive accesible para la propia Service Account. Si quieres ver o gestionar esas copias desde otra cuenta de Google, normalmente tendras que compartir la carpeta o trabajar dentro de una unidad compartida configurada para esa cuenta de servicio.

### Limitaciones de Google Drive

- Si OpenSSL no esta disponible, no podra autenticarse.
- Si cURL no esta disponible, no podra subir archivos grandes.
- Si el JSON es invalido o incompleto, la subida fallara.
- El plugin sube el backup despues de generarlo localmente; no evita el almacenamiento temporal en el servidor.

## Restaurar backups

### Restaurar desde copias locales

- El plugin lista los archivos `.wpress` encontrados en la carpeta de backups de All-in-One WP Migration.
- Puedes restaurar, descargar o borrar cada copia.

### Restaurar desde Google Drive

- El plugin lista los `.wpress` dentro de la carpeta `SimpleBackup` del Drive configurado.
- Al restaurar, descarga primero el archivo al servidor.
- Luego lanza el motor de importacion de All-in-One WP Migration.

### Casos que requieren restauracion manual

Hay escenarios que el plugin detecta pero no resuelve automaticamente:

- Backups cifrados con contrasena.
- Copias de entornos multisite que requieren seleccion de blogs.

En esos casos hay que restaurar manualmente desde la interfaz nativa de All-in-One WP Migration.

## Notificaciones por correo

El plugin puede enviar emails en dos situaciones:

- `Exito`: incluye sitio, fecha, archivo y tamano si esta disponible.
- `Error`: incluye sitio, fecha, archivo y el mensaje de error.

Siempre intenta incluir el correo administrador de WordPress y opcionalmente los correos adicionales configurados.

## Limpieza y retencion

SimpleBackup gestiona la limpieza local en dos niveles:

- Conserva solo las ultimas `N` copias generadas por el propio plugin.
- Opcionalmente elimina cualquier `.wpress` con mas de cierta cantidad de dias.

## Actualizaciones del plugin

El plugin incluye un verificador de actualizaciones propio que consulta:

- `https://repo.gorvet.com/updates/simplebackup/info.json`

Desde ese endpoint puede obtener:

- version nueva
- paquete descargable
- changelog o secciones informativas
- requisitos de WordPress y PHP

## Que no hace

- No reemplaza All-in-One WP Migration.
- No crea backups sin AI1WM.
- No gestiona destinos remotos distintos de Google Drive.
- No resuelve restauraciones cifradas o casos avanzados de multisite.
- No garantiza ejecuciones exactas al minuto si WP-Cron no recibe trafico.

## Estructura del proyecto

```text
simplebackup/
|-- assets/
|   `-- icon.png
|-- includes/
|   |-- class-simplebackup-backup-runner.php
|   |-- class-simplebackup-loader.php
|   |-- class-simplebackup-notifications.php
|   |-- class-simplebackup-restore-runner.php
|   |-- class-simplebackup-scheduler.php
|   |-- class-simplebackup-settings.php
|   `-- class-simplebackup-update.php
|-- languages/
|-- index.php
|-- simplebackup.php
`-- uninstall.php
```

## Datos que guarda

Opciones y transients usados por el plugin:

- `simplebackup_settings`
- `simplebackup_runtime`
- `simplebackup_restore_runtime`
- `simplebackup_run_lock`
- `simplebackup_restore_lock`

Al desinstalar, el plugin elimina parte de su configuracion y limpia tareas programadas relacionadas con backups.

## Licencia

Este proyecto se distribuye bajo licencia **GPL-2.0-or-later**. Revisa el archivo [`LICENSE`](LICENSE).

## Recomendacion de nombre del repo

El nombre actual del codigo ya esta alineado con `SimpleBackup` en:

- archivo principal `simplebackup.php`
- text domain `simplebackup`
- constantes y clases internas

Por coherencia, tiene sentido que:

- la carpeta del plugin sea `simplebackup`
- el repositorio de GitHub tambien se llame `simplebackup`

## Autor

- Autor: Juank de Gorvet
- Sitio: [gorvet.com](https://www.gorvet.com)
