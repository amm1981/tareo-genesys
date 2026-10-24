<?php

use App\Services\SourceSchema;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

// Comprobaciones CLI y consultas de lectura. No instala paquetes ni cambia datos.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = realpath(__DIR__.'/..');
$server = $root.'/server';
if (! is_file($server.'/vendor/autoload.php') || ! is_file($server.'/.env')) {
    fwrite(STDERR, "Instale dependencias y configure server/.env antes de comprobar produccion.\n");
    exit(1);
}
require $server.'/vendor/autoload.php';
$app = require $server.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$failed = 0;
$check = function (string $label, bool $ok) use (&$failed): void {
    echo ($ok ? 'OK ' : 'FALTA ').$label."\n";
    if (! $ok) {
        $failed++;
    }
};
$check('PHP CLI de la rama 8.3 y compatible con 8.3.6', PHP_VERSION_ID >= 80306 && PHP_VERSION_ID < 80400);
$os = is_file('/etc/os-release') ? file_get_contents('/etc/os-release') : '';
$noble = preg_match('/^ID=ubuntu$/m', $os) && preg_match('/^VERSION_ID="?24\.04"?$/m', $os);
$check('Ubuntu 24.04 LTS', (bool) $noble);
foreach (['php8.3-cli', 'php8.3-fpm', 'php8.3-common'] as $package) {
    $ok = false;
    if ($noble) {
        try {
            $process = new Process(['dpkg-query', '-W', '-f=${Status}\t${Version}', $package]);
            $process->setTimeout(10);
            $process->run();
            $parts = explode("\t", trim($process->getOutput()));
            $version = $parts[1] ?? '';
            if ($process->isSuccessful() && ($parts[0] ?? '') === 'install ok installed' && preg_match('/^8\.3\.6-0ubuntu0\.24\.04\./', $version)) {
                $compare = new Process(['dpkg', '--compare-versions', $version, 'ge', '8.3.6-0ubuntu0.24.04.13']);
                $compare->setTimeout(10);
                $compare->run();
                $ok = $compare->isSuccessful();
            }
        } catch (Throwable) {
            $ok = false;
        }
    }
    $check($package.' parcheado (.13 o revision oficial posterior)', $ok);
}
$check('APP_ENV=production', app()->environment('production'));
$check('APP_DEBUG=false', config('app.debug') === false);
$check('APP_URL del dominio previsto', rtrim((string) config('app.url'), '/') === 'https://api.labour.agrocalera.app');
$key = (string) config('app.key');
$check('APP_KEY propia configurada', str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32);
$connection = config('database.connections.mysql');
$dbSafe = config('database.default') === 'mysql' && ($connection['database'] ?? '') === 'get_genesys' && ! in_array($connection['username'] ?? '', ['', 'root'], true) && ($connection['password'] ?? '') !== '' && ! str_contains((string) ($connection['password'] ?? ''), '__CONFIGURAR_');
$check('MySQL del proyecto con usuario exclusivo y secreto', $dbSafe);
foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $path) {
    $check('Escritura '.$path, is_dir($server.'/'.$path) && is_writable($server.'/'.$path));
}
$check('Esquema privado disponible', is_file((string) config('sync.schema_file')));
if ($dbSafe && app()->environment('production')) {
    try {
        $db = DB::connection();
        $engine = $db->selectOne('SELECT VERSION() AS version, @@max_allowed_packet AS packet');
        $check('MySQL 8 (sin sustituirlo por MariaDB)', str_starts_with($engine->version, '8.') && ! str_contains(strtolower($engine->version), 'mariadb'));
        $check('max_allowed_packet suficiente para lotes', (int) $engine->packet >= 16777216);
        $tables = ['sync_runs', 'sync_periods', 'sync_batches', 'staging_costos', 'costos_agricolas'];
        $engines = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $db->getDatabaseName())->whereIn('TABLE_NAME', $tables)->pluck('ENGINE');
        $check('Cinco tablas de sincronizacion InnoDB', $engines->count() === 5 && ! $engines->contains(fn ($v) => $v !== 'InnoDB'));
        $check('Migracion de cuentas de consulta disponible', Schema::hasColumns('users', ['api_username', 'api_enabled']));
        $schemaFile = (string) config('sync.schema_file');
        if (is_file($schemaFile)) {
            app(SourceSchema::class)->approved(file_get_contents($schemaFile));
            $check('Columnas y precision del esquema migrado', true);
        }
    } catch (Throwable) {
        $check('Conexion, migraciones y esquema MySQL (verificar privadamente)', false);
    }
} else {
    $check('Comprobacion de base de produccion pendiente', false);
}
echo "El pool FPM, session.upload_progress.enabled=0, TLS y Nginx deben comprobarse aparte.\n";
echo $failed ? "Comprobaciones incompletas. No cambiar el cliente a produccion.\n" : "Comprobaciones CLI/DB correctas. Continuar con validacion web y token.\n";
exit($failed ? 1 : 0);
