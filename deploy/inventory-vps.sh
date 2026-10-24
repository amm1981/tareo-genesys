#!/usr/bin/env bash
# Inventario de solo lectura. No instala, reinicia ni modifica servicios o archivos.
set -u
section() { printf '\n%s\n' "$1"; }
section 'Sistema operativo y arquitectura'
cat /etc/os-release
uname -m
id
section 'Herramientas y versiones'
for tool in nginx php php8.3 php-fpm8.3 composer mysql certbot; do
    command -v "$tool" 2>/dev/null || true
done
nginx -v 2>&1 || true
php -v 2>/dev/null || true
php8.3 -v 2>/dev/null || true
php-fpm8.3 -v 2>/dev/null || true
composer --version 2>/dev/null || true
mysql --version 2>/dev/null || true
section 'Paquetes PHP del proveedor Ubuntu'
dpkg-query -W -f='${Package}\t${Status}\t${Version}\n' php8.3-cli php8.3-fpm php8.3-common php8.3-mysql php8.3-bcmath php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip 2>/dev/null || true
section 'Extensiones PHP CLI'
php8.3 -m 2>/dev/null || true
section 'Servicios existentes (estado)'
for service in nginx php8.3-fpm mysql mariadb apache2; do
    printf '%s: ' "$service"
    systemctl is-active "$service" 2>/dev/null || true
done
section 'Nombres de sitios, pools y sockets (sin leer configuraciones ni secretos)'
ls -ld /var/www /srv/www /etc/letsencrypt/live/api.labour.agrocalera.app 2>/dev/null || true
ls -l /etc/nginx/sites-enabled /etc/php/8.3/fpm/pool.d /run/php 2>/dev/null || true
section 'Directorios de paneles conocidos, si existen (solo metadatos)'
ls -ld /usr/local/hestia /www/server/panel /usr/local/psa /usr/local/cpanel 2>/dev/null || true
section 'Usuarios de procesos web, sin argumentos'
ps -eo user,group,comm | awk 'NR == 1 || $3 ~ /^(nginx|php-fpm|apache2|mysqld|mariadbd)/'
section 'Puertos TCP escuchando'
ss -ltn
section 'DNS del dominio'
getent ahostsv4 api.labour.agrocalera.app || true
getent ahostsv6 api.labour.agrocalera.app || true
section 'Espacio disponible'
df -h / /var/www 2>/dev/null || true
section 'Memoria disponible'
free -h
section 'Configuracion PHP CLI no confidencial (no acredita el pool FPM)'
php8.3 -r 'echo json_encode(["php"=>PHP_VERSION,"session_upload_progress"=>ini_get("session.upload_progress.enabled"),"post_max_size"=>ini_get("post_max_size"),"memory_limit"=>ini_get("memory_limit")],JSON_PRETTY_PRINT),PHP_EOL;' 2>/dev/null || true
