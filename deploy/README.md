# Despliegue de GET_Genesys

Destino previsto: **api.labour.agrocalera.app**, VPS **209.126.85.229**, Ubuntu **24.04 LTS**, PHP **8.3.6-0ubuntu0.24.04.13** o una revisión oficial posterior de seguridad, Nginx y MySQL 8/InnoDB. El sistema operativo, rutas, usuarios, certificados, proxy/CDN y servicios reales están pendientes del inventario del administrador.

El paquete está preparado localmente. La conexión SSH no interactiva reconoció la clave, pero no pudo firmar; el usuario eligió ejecutar personalmente el inventario. No se instalaron paquetes ni se modificó el VPS. No hay un script que despliegue o sobrescriba configuración automáticamente.

## 1. Inventario de solo lectura

Desde PowerShell en el proyecto local:

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys'
Get-Content -Raw .\deploy\inventory-vps.sh | ssh -i "$env:USERPROFILE\.ssh\agrocalera_vps" -o StrictHostKeyChecking=yes root@209.126.85.229 "tr -d '\015' | bash -s"
```

La frase de paso se introduce exclusivamente en la terminal SSH. `tr` elimina los retornos de carro que pueda enviar PowerShell; no guarda un script en el VPS. El inventario no lee `.env`, claves privadas, contraseñas MySQL ni el contenido de configuraciones de otros sitios. Compartir su salida para terminar de adaptar las plantillas. Si se prefiere, copiar `inventory-vps.sh` y ejecutarlo manualmente con `bash` dentro del servidor.

## 2. Qué contiene el paquete

- `server/`: código Laravel, migraciones, composer.lock, ejemplo **de producción** y esquema privado de 34 columnas.
- `deploy/`: esta guía, configuración Nginx y pool FPM en formato `.example`, inventario y comprobaciones.
- `manifest.json`: SHA-256 de cada archivo del release.
- `RELEASE.md`: identificación y requisitos.

No incluye `client/`, credenciales SQL, `.env` real, tokens, vendor/, node_modules/, tests/, logs, caches locales ni las 35 354 filas del MySQL local. Contiene recepción y consulta autenticada de costos publicados. El contrato para consumidores está en [docs/API_CONSULTA.md](../docs/API_CONSULTA.md).

## 3. Generar, transferir y verificar

Para regenerar el paquete en Windows:

```powershell
.\scripts\Build-Deployment.ps1
```

Se generan `artifacts/<release>.tar.gz`, su archivo `.sha256` y una carpeta revisable. No borra releases anteriores. Tras revisar el inventario y autorizar la subida, transferir ambos archivos con scp al directorio de entrada acordado del VPS. No subir el repositorio completo ni el `.env` de WampServer.

Antes de modificar la extracción del paquete, verificar en el VPS:

```bash
sha256sum -c NOMBRE_DEL_RELEASE.tar.gz.sha256
# Extraer en un directorio NUEVO de release, nunca encima del sitio existente.
php8.3 deploy/verify-release.php
```

El segundo comando se ejecuta desde la raíz del release extraído. No necesita vendor/ ni acceso a MySQL. Los hashes verifican integridad; se conserva además la autenticación de host SSH.

## 4. Diseño de carpetas a revisar

Propuesta, pendiente de contrastar con el VPS:

```text
/var/www/get-genesys/
  releases/<release>/server/
  releases/<release>/deploy/
  shared/.env
  shared/storage/
  current -> releases/<release>
```

`server/.env` apuntaría a `shared/.env` y `server/storage` a `shared/storage`. Crear enlaces únicamente dentro del nuevo release y conservar el storage compartido. El esquema del paquete debe copiarse a `shared/storage/app/private/source-schema.json` y mantener el hash `f3b5ba98326f724a41277cd9410b0658c71be750f23d9af17cd538ca57fefc0c`.

Confirmar un usuario de aplicación dedicado y los usuarios/grupos reales de Nginx/FPM. Permisos propuestos: directorios 750, código 640 y acceso de escritura solamente a storage/ y bootstrap/cache para el pool. El archivo de entorno es privado; sin permisos 777. Las rutas y cuentas aún no se consideran existentes.

## 5. Base y entorno de producción

Antes de crear o migrar, confirmar que `get_genesys` no pertenece a otra aplicación. Preparar respaldos si existe. El administrador crea la base y un usuario exclusivo, con contraseña y permisos limitados a esa base. La cuenta de migración puede tener DDL temporalmente; el usuario de ejecución necesita los permisos de lectura/escritura de la aplicación, sin privilegios globales.

Usar `deploy/production.env.example` como base para un archivo **nuevo** y privado. Si ya existe un `.env` de esta aplicación, conservarlo y revisarlo: no regenerar su APP_KEY. Completar DB_PASSWORD en el servidor; no enviar secretos por chat ni guardarlos en el paquete. Las credenciales de producción son diferentes de WampServer.

Con el entorno y los permisos preparados, desde server/:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
# Solo en la instalación inicial y con APP_KEY vacío:
php8.3 artisan key:generate
php8.3 artisan migrate --pretend
# Ejecutar las migraciones revisadas, tras respaldo y autorización:
php8.3 artisan migrate --force
php8.3 artisan config:cache
php8.3 artisan route:cache
```

El comando `composer` debe ejecutar PHP 8.3. Verificarlo o invocar `php8.3 /ruta/verificada/composer.phar`. No usar `composer update`, `--ignore-platform-reqs`, `migrate:fresh`, `db:wipe` ni rollback de migraciones que eliminen datos de origen. Las migraciones de las 34 columnas ya vienen incluidas; no ejecutar `sync:schema:prepare` de nuevo sobre ellas.

Desde la raíz del release:

```bash
php8.3 deploy/check-production.php
```

Esta comprobación hace lecturas de versión, entorno, permisos, esquema y MySQL; no publica datos ni modifica configuración. Debe comprobarse por separado que el pool FPM que atiende el dominio usa la versión parcheada y tiene deshabilitado session upload progress.

## 6. Nginx, PHP-FPM y HTTPS

Revisar y completar todos los marcadores `__...__` antes de instalar las plantillas. No reemplazar el vhost de labour ni el pool www de otros sistemas.

| Marcador | Valor que debe confirmarse |
| --- | --- |
| `__APP_CURRENT__` | Enlace al release activo, propuesto `/var/www/get-genesys/current` |
| `__APP_SHARED__` | Directorio persistente de esta aplicación |
| `__APP_USER__`, `__APP_GROUP__` | Cuenta exclusiva del pool y permisos del código |
| `__NGINX_USER__`, `__NGINX_GROUP__` | Usuario/grupo real de Nginx para acceder al socket |
| `__TLS_FULLCHAIN__`, `__TLS_PRIVATE_KEY__` | Rutas del certificado válido y su clave en el VPS |
| `__ACME_WEBROOT__` | Webroot ACME si se utiliza ese método de renovación |

Confirmar DNS A/AAAA o CNAME hacia la infraestructura elegida. No publicar AAAA que apunte a un servidor diferente. Verificar si existe un certificado compatible antes de solicitar uno. La plantilla TLS requiere que los archivos de certificado ya existan; la emisión/renovación se adapta al mecanismo existente, sin reemplazar otros certificados.

El socket propuesto para este sitio es `/run/php/get-genesys-php83.sock`. Nginx sirve únicamente `server/public`, envía index.php a ese pool y declara HTTPS en FastCGI. No usar `php artisan serve` en producción.

El pool aplica `session.upload_progress.enabled=0` antes de analizar peticiones. No sustituirlo con `.user.ini` o código Laravel. Se coordinan cuerpos HTTP de 12 MiB y timeouts de 300 segundos, con lotes de payload de 4 MiB. Revisar memoria del VPS y max_allowed_packet; no cambiar valores globales de otros proyectos automáticamente.

Antes de activar el sitio, verificar la sintaxis de Nginx y PHP-FPM. El administrador revisa la salida de `nginx -t` y `php-fpm8.3 -t` privadamente. Renovar los workers del servicio correcto para cargar los parches; una recarga afecta a todos los pools de esa instancia y debe planificarse con sus otros sitios.

Si hay Cloudflare, proxy o balanceador, confirmar TLS hasta Nginx y la IP de origen. No habilitar confianza en proxies arbitrarios ni desactivar la validación de certificados del cliente.

## 7. Validación y token de producción

Desde PowerShell local, cuando DNS y HTTPS estén operativos:

```powershell
.\deploy\Smoke-Test.ps1
```

Debe comprobar salud mínima 200 y rechazo anónimo 401. Después emitir un token propio, desde server/ en una terminal privada del VPS:

```bash
php8.3 artisan sync:token --days=30
```

El token se muestra una sola vez para copiarlo a client/.env local. No usar el token de WampServer, no registrar su salida y no usar `--save-client-env` en producción. Probarlo sin incluirlo en argumentos del proceso:

```powershell
.\deploy\Smoke-Test.ps1 -WithToken
```

El script lo pide de forma oculta, consulta un UUID inexistente y espera 404 después de autenticar. No envía registros.

Para cada sistema consumidor crear una cuenta con `php8.3 artisan api:usuario mi_sistema --nombre="Sistema consumidor"`. La consola solicita la contraseña oculta; no se crea ninguna cuenta por defecto. La migración adicional de users conserva las cuentas existentes y las deja sin acceso de consulta hasta habilitarlas expresamente. Probar el login JSON y los endpoints `/api/v1/costos/periodos` y `/api/v1/costos` con el token de lectura según `docs/API_CONSULTA.md`. La consulta requiere datos publicados: sin el primer envío se devuelve 404 para el mes. Mantener `API_QUERY_TOKEN_TTL_MINUTES=60` salvo que se acuerde otra vigencia.

## 8. Primer envío al VPS

Una vez validada la infraestructura, editar **manualmente** client/.env en la laptop:

```dotenv
API_BASE_URL=https://api.labour.agrocalera.app
API_TOKEN=TOKEN_NUEVO_EMITIDO_EN_EL_VPS
```

Conservar las credenciales SQL locales. Ejecutar desde client/:

```powershell
npm run sync -- --anio 2026 --mes 9 --dry-run
npm run sync -- --anio 2026 --mes 9
```

Contrastar los conteos y confirmar `ENVIAR 2026-09`. La extracción local ya verificada fue de 35 354 filas y 36 lotes; el origen puede cambiar, por lo que se revisa el resumen de esta nueva ejecución. Reanudar un UUID local preparado para WampServer contra el VPS no es el procedimiento de instalación: crear una ejecución nueva con el destino ya validado.

Comprobar estado completado, cantidades y snapshot activo de septiembre en la base del VPS. No abrir MySQL ni SQL Server a Internet. La laptop se comunica con producción solo mediante HTTPS.

## 9. Recuperación y operación manual

- Conservar el release anterior y su configuración antes de activar el nuevo.
- Si falla un lote, reanudar el UUID con la misma extracción; una transferencia incompleta conserva el snapshot publicado.
- Para abandonar una transferencia, `php8.3 artisan sync:abort UUID` libera su período conservando los datos.
- Si falla el código nuevo, volver al release previo y recargar de forma revisada el pool si fuera necesario. No invertir migraciones para borrar columnas o versiones.
- Respaldar MySQL, shared/.env y el esquema privado con los mecanismos existentes del VPS. Mantener la clave APP_KEY en futuras actualizaciones.
- Definir retención y renovación/revocación manual de tokens. No se instalan cron, colas, workers de Laravel ni tareas automáticas.

La instalación y validación real del VPS quedan pendientes de su inventario y autorización de cambios. La recepción y la consulta se verifican localmente antes de generar el paquete.
