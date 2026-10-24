# PHP elegido para producción

Decisión del usuario: **PHP 8.3.6, revisión Ubuntu `.13`**. El paquete completo objetivo es **`8.3.6-0ubuntu0.24.04.13` para Ubuntu 24.04 LTS (Noble)**. No se instalará PHP 8.5 para este despliegue.

Canonical identifica esa revisión como corregida para CVE-2026-109618 y CVE-2026-109619 en el [aviso oficial USN-8948-1, del 21 de octubre de 2026](https://ubuntu.com/security/notices/USN-8948-1). Ubuntu conserva el número upstream y añade los arreglos a su paquete. Una distribución genérica PHP 8.3.6 sin esa revisión no acredita esos arreglos.

## Compatibilidad del proyecto

`server/composer.json` declara `php: ^8.3.6` y fija `config.platform.php: 8.3.6` para resolver dependencias reproducibles compatibles con el intérprete del VPS. Esta plataforma simulada no cambia el PHP instalado ni certifica su estado de seguridad.

El lock anterior incluía cinco componentes Symfony 8.1 que exigían PHP 8.4. Se ajustaron exclusivamente esos componentes a sus versiones compatibles 7.4, conservando Laravel 12 y Sanctum. Usar `composer install` con el lock del proyecto en el VPS, no copiar vendor/ desde WampServer ni ejecutar composer con `--ignore-platform-reqs`.

Las revisiones de Ubuntu se verifican a nivel de paquetes. No añadir `.13` a la versión de PHP dentro de Composer. No bloquear futuras revisiones oficiales de seguridad de Ubuntu mediante apt hold o un pin que impida actualizaciones.

## Comprobaciones de solo lectura en el VPS

Ejecutar después de revisar y autorizar el acceso al servidor; no se ejecutaron remotamente durante esta preparación:

```bash
cat /etc/os-release
dpkg-query -W -f='${Package}\t${Version}\n' php8.3-cli php8.3-fpm php8.3-common
apt-cache policy php8.3-cli php8.3-fpm
php8.3 -v
php-fpm8.3 -v
```

Para esta elección se espera la versión de paquete `8.3.6-0ubuntu0.24.04.13` en CLI, FPM y common, o una revisión oficial posterior que conserve los arreglos. Si el VPS no es Ubuntu 24.04, no instalar paquetes Noble a la fuerza: revisar la distribución y su proveedor antes de elegir paquetes.

Dentro de server/ y usando el PHP elegido:

```bash
php8.3 /ruta/verificada/composer.phar check-platform-reqs --no-dev
php8.3 artisan --version
```

También debe confirmarse que Nginx usa el socket del pool PHP 8.3 correcto. La versión de CLI no garantiza la versión del proceso web. Reiniciar los workers actualizados requiere revisar primero si comparten servicio con otros proyectos.

## Aviso adicional del 23 de octubre

El [aviso oficial de PHP sobre session upload progress](https://www.php.net/archive/2026.php#2026-10-23-1) recomienda mantenerlo deshabilitado mientras se publica la corrección adicional de su ciclo regular. Configuración prevista **para el pool PHP-FPM de esta aplicación**, pendiente de revisar el VPS:

```ini
php_admin_flag[session.upload_progress.enabled] = off
```

Debe aplicarse antes de analizar peticiones, mediante el pool o php.ini, y renovar los workers. No sirve confiar en `ini_set()` desde Laravel ni en `.user.ini` para este punto de seguridad. No se modificó el PHP global de WampServer ni ningún servidor remoto.

## Pruebas y límites de verificación

La compatibilidad de PHP 8.3 se prueba localmente con el ejecutable CLI 8.3 disponible en Windows, además de la resolución Composer para 8.3.6. Ese binario de Windows no es el paquete Ubuntu `.13`: la comprobación del paquete y del proceso PHP-FPM del VPS queda pendiente hasta disponer de su inventario y acceso autorizado. Para servir HTTP localmente, instalar una compilación Windows mantenida y parcheada de esa rama.
