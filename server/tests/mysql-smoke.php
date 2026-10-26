<?php

use App\Models\User;
use App\Services\FloatCost;
use App\Services\SourceSchema;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Prueba opt-in, exclusivamente MySQL local get_genesys. Nunca borra tablas ni filas persistentes.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== 'get_genesys' || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true) || ! app()->environment('local')) {
    throw new RuntimeException('Esta prueba requiere exclusivamente get_genesys en MySQL local y APP_ENV=local.');
}
$fixture = json_decode(file_get_contents(__DIR__.'/../../client/.runs/protocol-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$tables = ['users', 'personal_access_tokens', 'sync_runs', 'sync_periods', 'sync_batches', 'staging_costos', 'costos_agricolas'];
$created = [];
$failed = false;
$fixtureSchema = storage_path('app/private/schema-test-'.Str::uuid().'.json');
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
try {
    $schemaHandle = fopen($fixtureSchema, 'x');
    if (! $schemaHandle) {
        throw new RuntimeException('No se pudo preparar el esquema sintético privado.');
    }
    fwrite($schemaHandle, $fixture['plan']['schema_json']);
    fclose($schemaHandle);
    config(['sync.schema_file' => $fixtureSchema]);
    foreach ($tables as $table) {
        $definition = (array) DB::selectOne("SHOW CREATE TABLE `$table`");
        $sql = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $definition['Create Table']);
        $sql = implode("\n", array_filter(explode("\n", $sql), fn ($line) => ! preg_match('/^\s+CONSTRAINT /', $line)));
        $sql = preg_replace('/,\n\)/', "\n)", $sql);
        DB::unprepared($sql);
        $created[] = $table;
    }
    $originalColumns = array_column(array_map(fn ($c) => (array) $c, DB::select('SHOW COLUMNS FROM costos_agricolas')), 'Field');
    if (! in_array('fecha_origen', $originalColumns, true)) {
        DB::statement('ALTER TABLE costos_agricolas ADD fecha_origen LONGTEXT NULL');
    }
    DB::statement('ALTER TABLE costos_agricolas ADD ImporteFixture DECIMAL(38,8) NULL, ADD DetalleFixture LONGTEXT NULL');
    foreach (FloatCost::COLUMNS as $name) {
        if (! in_array($name, $originalColumns, true)) {
            DB::statement('ALTER TABLE costos_agricolas ADD `'.$name.'` DECIMAL(65,30) NULL');
        }
    }
    // Esquema sintético para tablas TEMPORARY de esta sesión, nunca se instala como esquema real.
    $app->bind(SourceSchema::class, fn () => new class extends SourceSchema
    {
        public function approved(string $json): array
        {
            $schema = $this->parse($json);
            $columns = DB::select('SHOW COLUMNS FROM costos_agricolas');
            $types = array_column(array_map(fn ($c) => (array) $c, $columns), 'Type', 'Field');
            check(($types['ImporteFixture'] ?? '') === 'decimal(38,8)', 'DECIMAL real incorrecto.');

            return $schema;
        }
    });
    DB::beginTransaction();
    $user = User::create(['name' => 'Fixture', 'email' => 'fixture@invalid.test', 'password' => Str::random(64)]);
    $token = $user->createToken('fixture', ['sync:write'])->plainTextToken;
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $call = function (string $path, array $body, int $expected = 200) use ($kernel, $token, $app) {
        $app['auth']->forgetGuards();
        $request = Request::create('http://127.0.0.1'.$path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        check($response->getStatusCode() === $expected, 'Estado HTTP inesperado: '.$response->getStatusCode());

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    };
    $plan = $fixture['plan'];
    $batch = $fixture['batch'];
    $base = '/api/v1/sync/runs';
    $call($base, $plan);
    $call($base.'/'.$plan['uuid'].'/batches', $batch);
    $call($base.'/'.$plan['uuid'].'/batches', $batch);
    $call($base.'/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']]);
    $call($base.'/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']]);
    check(DB::table('costos_agricolas')->count() === 2, 'Se duplicaron filas.');
    check(DB::table('costos_agricolas')->value('ImporteFixture') === '123456789012345678901234567890.12345678', 'Se perdió precisión monetaria en MySQL.');
    check(DB::table('costos_agricolas')->value('datos_json') === json_decode($batch['payload'], true)[0]['datos_json'], 'Se alteró el JSON original Node → Laravel → MySQL.');
    check(DB::table('costos_agricolas')->value('CostoMPSoles') === '0.300000000000000040000000000000', 'Se redondeó la representación del costo FLOAT.');
    check(DB::table('costos_agricolas')->value('CostoMPUS$') === '0.000000000000000000000000100000', 'Se alteró el costo con notación exponencial.');
    $old = DB::table('sync_periods')->value('active_run_id');
    $plan['uuid'] = (string) Str::uuid();
    $call($base, $plan);
    $call($base.'/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']], 409);
    check(DB::table('sync_periods')->value('active_run_id') === $old, 'La transferencia incompleta alteró lo publicado.');
    $call($base.'/'.$plan['uuid'].'/batches', $batch);
    $call($base.'/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']]);
    $active = DB::table('sync_periods')->value('active_run_id');
    check($active !== $old && DB::table('costos_agricolas')->where('sync_run_id', $active)->count() === 2, 'Reemplazo incorrecto.');
    $consumerPassword = Str::random(32);
    $consumer = User::create(['name' => 'Consumer fixture', 'email' => 'consumer@invalid.test', 'password' => $consumerPassword]);
    $consumer->forceFill(['api_username' => 'consumer-fixture', 'api_enabled' => true])->save();
    $app['auth']->forgetGuards();
    $login = Request::create('http://127.0.0.1/api/v1/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['usuario' => 'consumer-fixture', 'password' => $consumerPassword], JSON_THROW_ON_ERROR));
    $loginResponse = $kernel->handle($login);
    check($loginResponse->getStatusCode() === 200, 'Fallo de login de consulta en MySQL.');
    $readToken = json_decode($loginResponse->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['access_token'];
    $read = function (array $query) use ($kernel, $readToken, $app) {
        $app['auth']->forgetGuards();
        $request = Request::create('http://127.0.0.1/api/v1/costos?'.http_build_query($query), 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$readToken]);
        $response = $kernel->handle($request);
        check($response->getStatusCode() === 200, 'Fallo de consulta HTTP de publicación.');

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    };
    $page = $read(['anio' => 2026, 'mes' => 9, 'limite' => 1]);
    check(count($page['data']) === 1 && $page['meta']['publicacion_uuid'] === $plan['uuid'], 'La consulta no eligió la versión publicada.');
    check($page['data'][0]['datos']['ImporteFixture'] === '123456789012345678901234567890.12345678', 'La API de consulta alteró DECIMAL(38,8).');
    check($page['data'][0]['datos']['CostoMPSoles'] === '0.30000000000000004', 'La API de consulta redondeó el costo FLOAT.');
    check($page['data'][0]['datos']['CostoMPUS$'] === '0.0000000000000000000000001', 'La API de consulta alteró el exponente.');
    $next = $read(['anio' => 2026, 'mes' => 9, 'limite' => 1, 'cursor' => $page['meta']['paginacion']['siguiente_cursor']]);
    check($next['data'][0]['numero_fila'] === 2 && $next['meta']['paginacion']['siguiente_cursor'] === null, 'Paginación de consulta incorrecta.');
    $app['auth']->forgetGuards();
    $inspection = Request::create('http://127.0.0.1/api/v1/sync/publication?anio=2026&mes=9', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $inspectionResponse = $kernel->handle($inspection);
    check($inspectionResponse->getStatusCode() === 200, 'Fallo de inspección de sincronización.');
    $metadata = json_decode($inspectionResponse->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
    check($metadata['active']['verificado'] === true && $metadata['active']['uuid'] === $plan['uuid'] && $metadata['active']['filas_publicadas'] === 2 && $metadata['pending'] === null, 'La inspección no verificó la publicación activa.');
    echo "OK: protocolo Node → HTTP Laravel → MySQL 8, decimales, idempotencia, integridad, reemplazo, consulta e inspección automática. Solo tablas temporales.\n";
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, $e instanceof QueryException ? "MYSQL_CONNECTION_OR_QUERY_FAILED: revisar MySQL local y permisos; no se imprime SQL ni valores.\n" : "MYSQL_SMOKE_ASSERTION_FAILED: revisar el fixture y la integración local.\n");
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (array_reverse($created) as $table) {
        DB::statement("DROP TEMPORARY TABLE IF EXISTS `$table`");
    }
    if (is_file($fixtureSchema)) {
        unlink($fixtureSchema);
    }
}
exit($failed ? 1 : 0);
