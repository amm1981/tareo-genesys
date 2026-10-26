# Preparación de despliegue en VPS Ubuntu

Guía únicamente. **No se ejecutaron comandos en el VPS**. Antes de desplegar, obtener autorización e inventariar Ubuntu, Nginx, PHP-FPM/versiones/extensiones, vhosts, certificados, MySQL, aplicaciones existentes, usuarios, permisos, respaldos y topología de proxies. No reemplazar configuraciones globales de otros proyectos.

Dominio confirmado por el usuario: **api.labour.agrocalera.app**. URL base de producción: `https://api.labour.agrocalera.app`. Endpoint inicial de comprobación: `https://api.labour.agrocalera.app/api/health`. La configuración local de WampServer y del cliente sigue activa hasta que el VPS esté preparado y verificado.

La preparación ejecutable está en [deploy/README.md](../deploy/README.md): inventario de solo lectura, plantillas Nginx/FPM, entorno de producción sin secretos, comprobaciones y recuperación. Generar el paquete mediante `scripts/Build-Deployment.ps1`; se guarda en `artifacts/` con su manifiesto y SHA-256. La IP facilitada es **209.126.85.229**; el administrador ejecutará personalmente el inventario SSH.

## Estado previo al despliegue

- Sincronización local real verificada: septiembre de 2026, 35 354 filas y 36 lotes completos.
- API de recepción y consulta implementadas. El acceso externo usa login JSON por usuario/contraseña, token exclusivo `costs:read` y paginación sobre publicaciones mensuales; ver [API_CONSULTA.md](API_CONSULTA.md).
- Pendiente recibir el inventario del VPS y confirmar sistema operativo, versiones y servicios existentes, ruta de instalación y posible proxy/CDN.
- Pendiente confirmar DNS A/AAAA o CNAME del dominio hacia la infraestructura elegida y emitir/verificar un certificado HTTPS válido.
- Pendiente crear configuración, base MySQL y usuario propios de producción, desplegar server/, migrar, copiar el esquema aprobado, emitir el token de producción y probar el primer envío manual al VPS.
- Los registros de MySQL local no aparecen automáticamente en el VPS: una nueva sincronización confirmada debe poblar la base de producción.

## Requisitos y artefactos

- Dominio con certificado HTTPS válido, Nginx y pool PHP-FPM independiente cuando corresponda.
- **Ubuntu 24.04 LTS con PHP 8.3.6, paquete `8.3.6-0ubuntu0.24.04.13`**, según elección del usuario. CLI y PHP-FPM del sitio deben usar la revisión parcheada. Composer 2 y extensiones descritas en README; validar con `composer check-platform-reqs --no-dev`. Ver [PHP_PRODUCCION.md](PHP_PRODUCCION.md) para comprobar la revisión completa; `php -v` por sí solo no acredita los parches de Ubuntu.
- MySQL 8 con InnoDB, disco para staging/histórico/transacciones y respaldo probado.
- Desplegar exclusivamente `server/`, su `composer.lock`, migraciones revisadas y el esquema real aprobado en un directorio privado fuera de `public/`. No subir `client/.runs`, credenciales SQL ni archivos de datos reales de la laptop.
- El cliente Node continúa en la laptop/LAN con acceso a CASABLANCA. La única comunicación al VPS es HTTPS hacia Laravel; no necesita conectividad pública a SQL Server o MySQL.

## Configuración independiente

Preparar un `.env` de servidor con secretos nuevos:

```dotenv
APP_NAME=GET_Genesys
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.labour.agrocalera.app
APP_KEY=
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=get_genesys
DB_USERNAME=get_genesys_app
DB_PASSWORD=
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=single
LOG_LEVEL=warning
```

El administrador debe crear exclusivamente la base/usuario del proyecto. El usuario de ejecución necesita SELECT/INSERT/UPDATE/DELETE sobre esa base (DELETE para administración de tokens). Usar credenciales de migración separadas con los permisos DDL necesarios solo durante el despliegue. No usar root sin contraseña. No permitir acceso remoto al MySQL del VPS desde la laptop.

Después de comprobar HTTPS y `/api/health`, actualizar el `.env` del cliente con `API_BASE_URL=https://api.labour.agrocalera.app` y un nuevo `API_TOKEN` emitido en producción. Conservar las credenciales SQL exclusivamente en la laptop/LAN. No usar el token local ni copiar el `.env` de WampServer al VPS.

## Secuencia a revisar y autorizar

1. Respaldar base/configuración actual y comprobar restauración. Elegir ruta de release y rollback sin sobrescribir otro sitio.
2. Instalar dependencias con `composer install --no-dev --prefer-dist --optimize-autoloader` y verificar plataforma.
3. Crear `.env` privado. Generar APP_KEY solo en la instalación inicial; conservarla en futuras versiones. Asignar permisos mínimos a `storage` y `bootstrap/cache` para el usuario PHP-FPM, sin permisos 777.
4. Revisar `php artisan migrate --pretend`; verificar tipos DECIMAL de la migración real y ejecutar migraciones aprobadas. Nunca `migrate:fresh` en producción.
5. Copiar el mismo `source-schema.json` aprobado por el cliente (SHA-256 idéntico). Si se prepara el esquema en VPS, generar/revisar la migración antes de ejecutarla. No instalar el fixture de pruebas.
6. Configurar el vhost con document root `server/public`, reescritura a `index.php`, FastCGI hacia el pool existente validado, denegación de archivos ocultos y TLS. Mantener MySQL y SQL Server fuera de Internet.
7. Coordinar límites: `client_max_body_size` al menos 12 MiB; `post_max_size` al menos 12 MiB; timeouts PHP-FPM/Nginx suficientes para completar el mayor snapshot. Revisar `max_allowed_packet` y recursos de MySQL antes de cambiarlos. Ajustar `REQUEST_TIMEOUT_MS` del cliente dentro del límite admitido; la finalización es idempotente si la respuesta se pierde.
8. Si hay proxy/CDN, configurar solo proxies confiables y verificar HTTPS efectivo; no confiar globalmente en `X-Forwarded-Proto` de cualquier IP.
   Antes de habilitar el sitio, comprobar la versión de los paquetes CLI/FPM con `dpkg-query` y el pool PHP 8.3 que atenderá el dominio. Aplicar `php_admin_flag[session.upload_progress.enabled] = off` en el pool de esta aplicación, o su equivalente en un php.ini que se cargue antes de analizar peticiones; esta medida procede del aviso adicional de PHP del 23 de octubre. No usar `ini_set()` ni `.user.ini` como sustituto. El reinicio de workers se planifica tras revisar los servicios compartidos del VPS.
9. Ejecutar `php artisan config:cache`, `route:cache` y verificar `/api/health`, rechazo sin token, emisión de token exclusivo y estado autenticado. No ejecutar pruebas destructivas contra la base desplegada.
10. Emitir el token con `php artisan sync:token --days=30` en una terminal privada. Guardarlo en `client/.env` y cambiar `API_BASE_URL` a HTTPS. No usar `--save-client-env` fuera de local.
11. Primer envío solo después de dry-run, comparación con SSMS y confirmación manual explícita del período. Validar conteos y referencias activas sin imprimir filas personales en logs.
12. Crear una cuenta de lectura por sistema mediante `php8.3 artisan api:usuario mi_sistema`. La contraseña se introduce oculta en consola. Verificar login, consulta por año/mes y logout con el token de lectura, sin reutilizar el token de sincronización.

No instalar cron ni workers. La automatización solicitada para Windows Task Scheduler se prepara por separado, con revisión y activación posterior según [AUTOMATIZACION.md](AUTOMATIZACION.md); no se instalaron tareas. Documentar renovación/revocación de tokens, respaldos, retención de staging/versiones y monitoreo de espacio. Un fallo de transferencia conserva el snapshot anterior; un rollback de aplicación debe conservar datos/esquema compatible y no ejecutar migraciones inversas que eliminen columnas de origen.

Referencias primarias consultadas durante la implementación: [Laravel 12](https://laravel.com/docs/12.x/installation), [Sanctum](https://laravel.com/docs/12.x/sanctum), [tipos de tedious](https://github.com/tediousjs/tedious/blob/master/src/data-type.ts). Para actualizaciones, contrastar las referencias con las versiones fijadas por los lockfiles.
