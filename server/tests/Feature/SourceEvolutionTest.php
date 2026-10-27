<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FloatCost;
use App\Services\SourceComparison;
use App\Services\SourceSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SourceEvolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync.schema_file' => base_path('../docs/source-schema.json')]);
        $this->user = User::factory()->create();
        $this->user->forceFill(['api_username' => 'audit', 'api_enabled' => true])->save();
        $this->withHeader('Authorization', 'Bearer '.$this->user->createToken('sync', ['sync:write'])->plainTextToken);
    }

    private function plan(bool $legacy = false): array
    {
        $json = file_get_contents(base_path('../docs/'.($legacy ? 'schemas/spwconsumomesvf.v1.json' : 'source-schema.json')));
        $schema = json_decode($json, true);
        $row = [];
        foreach ($schema['columns'] as $c) {
            $row[$c['name']] = $c['nullable'] ? null : ($c['type'] === 'Numeric' ? '999999999999999999' : 'Fixture');
        }
        $row['Fecha'] = '2026-10-01T00:00:00.1234567';
        $row['Operacion'] = 'CONSUMOS';
        $row['Cantidad'] = '-123456789012345.6789';
        $row['CostoMPSoles'] = -0.30000000000000004;
        if (! $legacy) {
            $row['CostoHASSoles'] = -0.30000000000000004;
            $row['CostoHASUS$'] = 1e-25;
            $row['CostoHAS1Soles'] = null;
            $row['CostoHAS1US$'] = -10.5;
        }
        $wire = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $payload = json_encode([['numero_fila' => 1, 'datos_json' => $wire]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $batch = ['numero_lote' => 1, 'payload' => $payload, 'checksum' => hash('sha256', $payload)];
        $plan = ['uuid' => (string) Str::uuid(), 'anio' => 2026, 'mes' => 10, 'total_esperado' => 1, 'total_lotes' => 1,
            'schema_json' => $json, 'schema_checksum' => hash('sha256', $json), 'checksum_total' => hash('sha256', '1:'.$batch['checksum']."\n")];

        return [$plan, $batch, $wire];
    }

    private function legacy(int $rows = 1): array
    {
        [$plan, $batch, $wire] = $this->plan(true);
        $payload = [];
        for ($i = 1; $i <= $rows; $i++) {
            $payload[] = ['numero_fila' => $i, 'datos_json' => $wire];
        }
        $batch['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $batch['checksum'] = hash('sha256', $batch['payload']);
        $plan['total_esperado'] = $rows;
        $plan['checksum_total'] = hash('sha256', '1:'.$batch['checksum']."\n");
        $id = DB::table('sync_runs')->insertGetId($plan + ['user_id' => $this->user->id, 'estado' => 'completada', 'total_recibido' => $rows,
            'lotes_recibidos' => 1, 'fecha_inicio' => now(), 'fecha_finalizacion' => now()]);
        DB::table('sync_batches')->insert($batch + ['sync_run_id' => $id, 'total_registros' => $rows, 'created_at' => now()]);
        for ($i = 1; $i <= $rows; $i++) {
            DB::table('costos_agricolas')->insert(['sync_run_id' => $id, 'numero_fila' => $i, 'anio' => 2026, 'mes' => 10, 'fecha' => '2026-10-01', 'datos_json' => $wire, 'created_at' => now()]);
        }
        DB::table('sync_periods')->insert(['anio' => 2026, 'mes' => 10, 'active_run_id' => $id]);

        return $plan;
    }

    public function test_legacy_cursor_can_finish_after_the_new_source_becomes_active(): void
    {
        $old = $this->legacy(2);
        $sync = $this->user->createToken('sync2', ['sync:write'])->plainTextToken;
        $read = $this->user->createToken('read', ['costs:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $first = $this->withHeader('Authorization', 'Bearer '.$read)->getJson('/api/v1/costos?anio=2026&mes=10&limite=1')->assertOk();
        $cursor = $first->json('meta.paginacion.siguiente_cursor');
        [$plan, $batch] = $this->plan();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$sync)->postJson('/api/v1/sync/runs', $plan)->assertOk();
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $batch)->assertOk();
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$read)->getJson('/api/v1/costos?'.http_build_query(['anio' => 2026, 'mes' => 10, 'limite' => 1, 'cursor' => $cursor]))
            ->assertOk()->assertJsonPath('meta.publicacion_uuid', $old['uuid'])->assertJsonPath('meta.source.classification', 'legacy')->assertJsonPath('data.0.numero_fila', 2);
    }

    public function test_new_source_publishes_38_fields_and_preserves_legacy_hashes_and_rows(): void
    {
        $old = $this->legacy();
        $this->getJson('/api/v1/sync/publication?anio=2026&mes=10')->assertOk()->assertJsonPath('data.active.verificado', true)->assertJsonPath('data.active.source.classification', 'legacy');
        [$plan, $batch] = $this->plan();
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']])->assertConflict();
        $this->assertSame($old['uuid'], DB::table('sync_runs')->find(DB::table('sync_periods')->value('active_run_id'))->uuid);
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $batch)->assertOk();
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']])->assertOk();
        $this->getJson('/api/v1/sync/runs/'.$plan['uuid'].'/verification')->assertOk()->assertJsonPath('data.verificado', true)->assertJsonPath('data.source.column_count', 38);
        $this->getJson('/api/v1/sync/runs/'.$old['uuid'].'/verification')->assertOk()->assertJsonPath('data.verificado', true);
        $this->assertDatabaseCount('costos_agricolas', 2);
        $this->assertDatabaseHas('sync_runs', ['uuid' => $old['uuid'], 'schema_json' => $old['schema_json'], 'checksum_total' => $old['checksum_total']]);
        $token = $this->user->createToken('read', ['costs:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);
        $result = $this->getJson('/api/v1/costos?anio=2026&mes=10')->assertOk()->assertJsonPath('meta.source.classification', 'current')
            ->assertJsonPath('data.0.datos.CostoHASSoles', '-0.30000000000000004')->assertJsonPath('data.0.datos.CostoHASUS$', '0.0000000000000000000000001')
            ->assertJsonPath('data.0.datos.CostoHAS1Soles', null)->assertJsonPath('data.0.datos.CostoHAS1US$', '-10.5')->assertJsonPath('data.0.datos.Cantidad', '-123456789012345.6789');
        $this->assertCount(38, $result->json('data.0.datos'));
        $history = $this->getJson('/api/v1/costos?anio=2026&mes=10&publicacion_uuid='.$old['uuid'])->assertOk()->assertJsonPath('meta.source.classification', 'legacy');
        $this->assertCount(34, $history->json('data.0.datos'));
        $this->getJson('/api/v1/costos/publicaciones?anio=2026&mes=10')->assertOk()->assertJsonCount(2, 'data');
        $report = app(SourceComparison::class)->report($old['uuid'], $plan['uuid'], 2026, 10);
        $this->assertCount(4, $report['before']['available_cost_columns']);
        $this->assertCount(8, $report['after']['available_cost_columns']);
        $this->assertSame('-0.30000000000000004', $report['after']['operations']['CONSUMOS']['CostoHASSoles']['min']);
        $this->assertDatabaseCount('costos_agricolas', 2);
    }

    public function test_old_source_cannot_start_a_new_publication_and_old_empty_uuid_is_reserved(): void
    {
        [$legacy] = $this->plan(true);
        $this->postJson('/api/v1/sync/runs', $legacy)->assertUnprocessable()->assertJsonPath('error.code', 'legacy_source_read_only');
        [$current] = $this->plan();
        $current['uuid'] = '237818a9-4870-4cfa-92e5-f5a2a448dbb3';
        $this->postJson('/api/v1/sync/runs', $current)->assertUnprocessable()->assertJsonPath('error.code', 'legacy_uuid_reserved');
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public function test_observed_has_columns_are_nullable_float_8_and_use_exact_lexical_storage(): void
    {
        $schema = json_decode(file_get_contents(base_path('../docs/source-schema.json')), true);
        $this->assertCount(38, $schema['columns']);
        foreach (array_slice($schema['columns'], -4) as $c) {
            $this->assertSame('Float', $c['type']);
            $this->assertSame(8, $c['length']);
            $this->assertTrue($c['nullable']);
            $this->assertNull($c['precision']);
            $this->assertNull($c['scale']);
            $this->assertTrue(FloatCost::matches($c));
            $this->assertSame('-0.30000000000000004', app(SourceSchema::class)->storageValue($c, -0.3, '{"'.$c['name'].'":-0.30000000000000004}'));
        }
        $migration = file_get_contents(database_path('migrations/2026_10_26_000001_add_has_costs_spwconsumomes.php'));
        $this->assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|DELETE)\b/i', $migration);
        $this->assertSame('f3b5ba98326f724a41277cd9410b0658c71be750f23d9af17cd538ca57fefc0c', hash_file('sha256', base_path('../docs/schemas/spwconsumomesvf.v1.json')));
        $this->assertSame('f3b5ba98326f724a41277cd9410b0658c71be750f23d9af17cd538ca57fefc0c', hash_file('sha256', resource_path('schemas/spwconsumomesvf.v1.json')));
        $this->assertSame(hash_file('sha256', base_path('../docs/source-schema.json')), hash_file('sha256', resource_path('schemas/spwconsumomes.v1.json')));
    }

    public function test_mysql_compatibility_rejects_narrow_types_and_changed_decimal_scale(): void
    {
        $original = DB::getFacadeRoot();
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        try {
            $source = app(SourceSchema::class);
            $column = ['name' => 'Año', 'type' => 'Int', 'nullable' => true];
            $this->assertFalse($source->compatibleExisting($column, ['type' => 'tinyint', 'nullable' => true]));
            $this->assertFalse($source->compatibleExisting($column, ['type' => 'int unsigned', 'nullable' => true]));
            $this->assertTrue($source->compatibleExisting($column, ['type' => 'int', 'nullable' => true]));
            $money = ['name' => 'Cantidad', 'type' => 'Money', 'nullable' => true, 'precision' => 19, 'scale' => 4];
            $this->assertFalse($source->compatibleExisting($money, ['type' => 'decimal(19,2)', 'nullable' => true]));
            $this->assertTrue($source->compatibleExisting($money, ['type' => 'decimal(19,4)', 'nullable' => true]));
        } finally {
            DB::swap($original);
        }
    }

    public function test_migration_preview_reads_metadata_without_replaying_ddl_or_deleting_rows(): void
    {
        $this->legacy();
        $migration = require database_path('migrations/2026_10_26_000001_add_has_costs_spwconsumomes.php');
        $queries = DB::connection()->pretend(fn () => $migration->up());
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
        $this->assertDatabaseCount('costos_agricolas', 1);
    }
}
