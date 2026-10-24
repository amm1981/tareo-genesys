<?php

use App\Models\User;
use App\Services\FloatCost;
use App\Services\SourceSchema;
use Illuminate\Contracts\Console\Kernel;
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
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
try {
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
    echo "OK: protocolo Node → HTTP Laravel → MySQL 8, DECIMAL(38,8), idempotencia, integridad, reemplazo y rollback. Solo tablas temporales.\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (array_reverse($created) as $table) {
        DB::statement("DROP TEMPORARY TABLE IF EXISTS `$table`");
    }
}
