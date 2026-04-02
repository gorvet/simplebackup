# SimpleBackup

Plugin de WordPress para automatizar copias de seguridad con [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/). Permite programar backups, ejecutarlos manualmente, conservar un numero limitado de copias locales, enviar notificaciones por correo y subir las copias a Google Drive sin depender de la extension Pro de All-in-One WP Migration.

## Que hace este plugin

SimpleBackup actúa como una capa de automatización sobre All-in-One WP Migration:

- Programa backups automáticos diarios, semanales o mensuales.
- Permite lanzar un backup manual desde el panel.
- Guarda el estado de la última ejecución.
- Puede subir cada archivo `.wpress` a Google Drive usando una Service Account.
- Lista copias locales y remotas para restaurar o borrar.
- Permite restaurar desde archivos locales o desde Google Drive.
- Envía correos cuando el backup termina bien o cuando falla.
- Mantiene actualizaciones remotas del plugin desde un endpoint propio.

## Requisitos

- WordPress 5.8 o superior.
- PHP 7.4 o superior.
- Plugin **All-in-One WP Migration** instalado y activo .
- WP-Cron habilitado para que la automatización funcione correctamente.
- Para Google Drive:
  - OpenSSL habilitado en PHP.
  - cURL habilitado en PHP.
  - Una Service Account de Google Cloud con acceso a Drive.

## Instalación
1. Debes tener instalado y activo [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/).
2. Descarga el contendio de este repositorio cono zip y llamalo `simplebackup.zip`.
3.  En el menu lateral de WordPress ve a plugins/añadir plugin, clickea en el boton `Subir plugin` y sube el simplebackup.zip.
4. Activa **SimpleBackup**.
5. En el menu lateral de WordPress abre `SimpleBackup`.

## Como funciona

### Flujo de backup

1. SimpleBackup programa una tarea con WP-Cron según la frecuencia y la hora configuradas.
2. Cuando llega el momento, lanza internamente una exportación de All-in-One WP Migration.
3. Mientras el backup corre, guarda un lock temporal para evitar duplicados.
4. Cuando el archivo `.wpress` aparece en `wp-content/ai1wm-backups`, marca la ejecución como exitosa.
5. Si Google Drive está activo, intenta subir el archivo recien generado.
6. Despues limpia copias locales antiguas según tu configuracion.
7. Si activaste notificaciones, envía correo de éxito o error.

### Flujo de restauración

Hay dos fuentes de restauración:

- `Copias locales`: usa un archivo `.wpress` ya existente en el servidor.
- `Google Drive`: descarga primero el archivo desde Drive al directorio local de backups y luego dispara la importación con All-in-One WP Migration.

El plugin monitorea el estado de la restauración y libera locks vencidos si detecta procesos atascados.

## Configuracion disponible

Desde la pantalla del plugin puedes definir:

- `Activar automatización`: enciende o apaga los backups automáticos.
- `Frecuencia`: diaria, semanal o mensual.
- `Horario`: hora base según la zona horaria de WordPress.
- `Copias locales a conservar`: máximo de backups locales creados por SimpleBackup.
- `Borrado automatico por antigüedad`: elimina copias viejas si se activa.
- `Días máximos`: umbral de antigüedad para el borrado automatico.
- `Notificar éxito`: envía email cuando el backup termina correctamente.
- `Notificar error`: envía email cuando ocurre un fallo.
- `Correos adicionales`: destinatarios extra, separados por coma.
- `Google Drive`: activa la subida remota.
- `Service Account JSON`: credenciales completas de Google Cloud para operar con Drive.

## Exportar a Google Drive

SimpleBackup no usa la extensión de pago de All-in-One WP Migration para Google Drive. Implementa su propia integración mediante la API de Google Drive y una **Service Account**.

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
- Busca una carpeta raíz llamada `SimpleBackup` en el Drive de la cuenta.
- Si no existe, la crea automáticamente.
- Sube el archivo `.wpress` mediante una subida reanudable.
- Guarda el `folder_id` para reutilizarlo en futuras ejecuciones.

### Importante sobre la Service Account

La carpeta se crea en el Drive accesible para la propia Service Account. Si quieres ver o gestionar esas copias desde otra cuenta de Google, normalmente tendrás que compartir la carpeta o trabajar dentro de una unidad compartida configurada para esa cuenta de servicio.

### Limitaciones de Google Drive

- Si OpenSSL no está disponible, no podrá autenticarse.
- Si cURL no está disponible, no podrá subir archivos grandes.
- Si el JSON es invalido o incompleto, la subida fallara.
- El plugin sube el backup despues de generarlo localmente; no evita el almacenamiento temporal en el servidor.

## Restaurar backups

### Restaurar desde copias locales

- El plugin lista los archivos `.wpress` encontrados en la carpeta de backups de All-in-One WP Migration.
- Puedes restaurar, descargar o borrar cada copia.

### Restaurar desde Google Drive

- El plugin lista los `.wpress` dentro de la carpeta `SimpleBackup` del Drive configurado.
- Al restaurar, descarga primero el archivo al servidor.
- Luego lanza el motor de importación de All-in-One WP Migration.

### Casos que requieren restauración manual

Hay escenarios que el plugin detecta pero no resuelve automáticamente:

- Backups cifrados con contraseña.
- Copias de entornos multisite que requieren selección de blogs.

En esos casos hay que restaurar manualmente desde la interfaz nativa de All-in-One WP Migration.

## Notificaciones por correo

El plugin puede enviar emails en dos situaciones:

- `Exito`: incluye sitio, fecha, archivo y tamaño si está disponible.
- `Error`: incluye sitio, fecha, archivo y el mensaje de error.

Siempre intenta incluir el correo administrador de WordPress y opcionalmente los correos adicionales configurados.

## Limpieza y retención

SimpleBackup gestiona la limpieza local en dos niveles:

- Conserva solo las ultimas `N` copias generadas por el propio plugin.
- Opcionalmente elimina cualquier `.wpress` con más de cierta cantidad de días.

## Actualizaciones del plugin

El plugin incluye un verificador de actualizaciones propio que consulta:

- `https://repo.gorvet.com/updates/simplebackup/info.json`

Desde ese endpoint puede obtener:

- versión nueva
- paquete descargable
- changelog o secciones informativas
- requisitos de WordPress y PHP

## Que no hace

- No reemplaza All-in-One WP Migration.
- No crea backups sin AI1WM.
- No gestiona destinos remotos distintos de Google Drive.
- No resuelve restauraciones cifradas o casos avanzados de multisite.
- No garantiza ejecuciones exactas al minuto si WP-Cron no recibe tráfico.


## Datos que guarda

Opciones y transients usados por el plugin:

- `simplebackup_settings`
- `simplebackup_runtime`
- `simplebackup_restore_runtime`
- `simplebackup_run_lock`
- `simplebackup_restore_lock`

Al desinstalar, el plugin elimina parte de su configuración y limpia tareas programadas relacionadas con backups.

## Licencia

Este proyecto se distribuye bajo licencia **GPL-2.0-or-later**. Revisa el archivo [`LICENSE`](LICENSE).

