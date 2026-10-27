<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CostQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $consumer;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync.schema_file' => base_path('../docs/source-schema.json')]);
        $this->owner = User::factory()->create();
        $this->consumer = User::factory()->create();
        $this->consumer->forceFill(['api_username' => 'erp', 'api_enabled' => true])->save();
        $this->token = $this->consumer->createToken('query', ['costs:read'], now()->addHour())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function createRun(array $rows, ?int $month = 9, string $state = 'completada'): object
    {
        $schema = file_get_contents(base_path('../docs/source-schema.json'));
        $id = DB::table('sync_runs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'anio' => 2026, 'mes' => $month,
            'estado' => $state, 'total_esperado' => count($rows), 'total_recibido' => count($rows), 'total_lotes' => count($rows),
            'lotes_recibidos' => count($rows), 'checksum_total' => hash('sha256', ''), 'schema_checksum' => hash('sha256', $schema),
            'schema_json' => $schema, 'fecha_inicio' => now(), 'fecha_finalizacion' => now(),
        ]);
        foreach ($rows as $i => $change) {
            $values = array_fill_keys(array_column(json_decode($schema, true)['columns'], 'name'), null);
            $values = array_replace($values, [
                'Fecha' => '2026-09-01T12:30:00.1234567', 'Año' => 2026, 'Mes' => 9,
                'Operacion' => 'CONSUMOS', 'TablaOrigen' => 'Fixture', 'Concepto' => '  á漢字 😀  ',
                'Kardex' => '999999999999999999', 'Cantidad' => '123456789012345.6789', 'HAS' => '0.0001',
                'CostoMPSoles' => 0.30000000000000004, 'CostoMPUS$' => 1e-25, 'CostoMP1Soles' => -123.45,
            ], $change);
            DB::table('costos_agricolas')->insert([
                'sync_run_id' => $id, 'numero_fila' => $i + 1, 'anio' => (int) substr($values['Fecha'], 0, 4),
                'mes' => (int) substr($values['Fecha'], 5, 2), 'fecha' => substr($values['Fecha'], 0, 10),
                'Operacion' => $values['Operacion'], 'TablaOrigen' => $values['TablaOrigen'],
                'datos_json' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }

        return DB::table('sync_runs')->find($id);
    }

    private function publish(object $run, int $month = 9): void
    {
        DB::table('sync_periods')->updateOrInsert(['anio' => 2026, 'mes' => $month], ['active_run_id' => $run->id]);
    }

    private function page(array $query = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/costos?'.http_build_query(array_replace(['anio' => 2026, 'mes' => 9], $query)));
    }

    public function test_only_published_rows_are_exposed_with_original_fields_and_decimal_strings(): void
    {
        $this->createRun([['Concepto' => 'histórico']]);
        $current = $this->createRun([[], []]);
        $this->publish($current);
        $pending = $this->createRun([['Concepto' => 'pendiente']], state: 'pendiente');
        DB::table('sync_periods')->where('mes', 9)->update(['pending_run_id' => $pending->id]);
        $response = $this->page()->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.publicacion_uuid', $current->uuid)
            ->assertJsonPath('data.0.datos.Kardex', '999999999999999999')
            ->assertJsonPath('data.0.datos.Cantidad', '123456789012345.6789')
            ->assertJsonPath('data.0.datos.HAS', '0.0001')
            ->assertJsonPath('data.0.datos.CostoMPSoles', '0.30000000000000004')
            ->assertJsonPath('data.0.datos.CostoMPUS$', '0.0000000000000000000000001')
            ->assertJsonPath('data.0.datos.CostoMP1Soles', '-123.45')
            ->assertJsonPath('data.0.datos.CostoMP1US$', null)
            ->assertJsonPath('data.0.datos.Fecha', '2026-09-01T12:30:00.1234567')
            ->assertJsonPath('data.0.datos.Concepto', '  á漢字 😀  ');
        $this->assertCount(38, $response->json('data.0.datos'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_pagination_remains_on_its_original_publication_during_a_resync(): void
    {
        $old = $this->createRun([[], [], []]);
        $this->publish($old);
        $first = $this->page(['limite' => 1])->assertOk()->assertJsonPath('data.0.numero_fila', 1);
        $cursor = $first->json('meta.paginacion.siguiente_cursor');
        $new = $this->createRun([['Concepto' => 'nuevo']]);
        $this->publish($new);
        $second = $this->page(['limite' => 1, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('meta.publicacion_uuid', $old->uuid)->assertJsonPath('data.0.numero_fila', 2);
        $this->page(['limite' => 1, 'cursor' => $second->json('meta.paginacion.siguiente_cursor')])->assertOk()
            ->assertJsonPath('data.0.numero_fila', 3)->assertJsonPath('meta.paginacion.siguiente_cursor', null);
        $this->page()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.publicacion_uuid', $new->uuid);
    }

    public function test_cursor_cannot_be_forged_reused_by_another_user_or_used_with_other_filters(): void
    {
        $this->publish($this->createRun([[], []]));
        $cursor = $this->page(['limite' => 1])->assertOk()->json('meta.paginacion.siguiente_cursor');
        $this->page(['limite' => 1, 'cursor' => 'forged'])->assertUnprocessable();
        $this->page(['limite' => 2, 'cursor' => $cursor])->assertUnprocessable();
        $this->page(['limite' => 1, 'cursor' => $cursor, 'operacion' => 'OTRO'])->assertUnprocessable();
        $other = User::factory()->create();
        $other->forceFill(['api_username' => 'otro', 'api_enabled' => true])->save();
        $token = $other->createToken('query', ['costs:read'], now()->addHour())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->page(['limite' => 1, 'cursor' => $cursor])->assertUnprocessable();
    }

    public function test_expired_cursor_requires_a_new_consistent_query(): void
    {
        $this->publish($this->createRun([[], []]));
        $cursor = $this->page(['limite' => 1])->json('meta.paginacion.siguiente_cursor');
        $this->travel(61)->minutes();
        $renewed = $this->consumer->createToken('renewed', ['costs:read'], now()->addHour())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$renewed);
        $this->page(['limite' => 1, 'cursor' => $cursor])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_cursor');
    }

    public function test_filters_and_monthly_counts_also_work_for_an_annual_publication(): void
    {
        $annual = $this->createRun([
            [], ['Fecha' => '2026-09-02T00:00:00.0000000', 'Operacion' => 'OTRO'],
            ['Fecha' => '2026-10-01T00:00:00.0000000'],
        ], month: null);
        $this->publish($annual);
        $this->publish($annual, 10);
        $this->page()->assertJsonCount(2, 'data');
        $this->page(['mes' => 10])->assertJsonCount(1, 'data');
        $this->page(['operacion' => 'OTRO', 'tabla_origen' => 'Fixture', 'fecha_desde' => '2026-09-02', 'fecha_hasta' => '2026-09-30'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero_fila', 2);
        $this->page(['operacion' => 'inexistente'])->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/costos/periodos?anio=2026')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.filas', 2)->assertJsonPath('data.1.filas', 1);
    }

    public function test_empty_publication_differs_from_a_never_published_period(): void
    {
        $this->page()->assertNotFound()->assertJsonPath('error.code', 'period_not_published');
        $this->publish($this->createRun([]));
        $this->page()->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.paginacion.hay_mas', false);
        $this->getJson('/api/v1/costos/periodos?anio=2026')->assertOk()->assertJsonPath('data.0.filas', 0);
    }

    public function test_period_dates_page_size_and_year_are_validated(): void
    {
        foreach ([['anio' => 1899], ['mes' => 13], ['limite' => 0], ['limite' => 501], ['fecha_desde' => '2026-09-31'], ['fecha_desde' => '2026-08-31'], ['fecha_desde' => '2026-09-20', 'fecha_hasta' => '2026-09-01']] as $change) {
            $this->page($change)->assertUnprocessable();
        }
        $this->getJson('/api/v1/costos')->assertUnprocessable();
        $this->getJson('/api/v1/costos/periodos')->assertUnprocessable();
    }

    public function test_unauthenticated_cost_requests_return_401_without_json_accept_header(): void
    {
        $this->withoutHeader('Authorization');
        $this->withHeader('Accept', '*/*');

        foreach ([
            '/api/v1/costos?anio=2026&mes=9',
            '/api/v1/costos/periodos?anio=2026',
        ] as $url) {
            $this->app['auth']->forgetGuards();
            $this->get($url)
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'http_401');
        }
    }

    public function test_query_and_sync_permissions_are_exclusive_and_bearer_is_required(): void
    {
        $this->postJson('/api/v1/sync/runs', [])->assertForbidden();
        foreach ([['sync:write'], ['*'], ['costs:read', 'sync:write']] as $abilities) {
            $this->app['auth']->forgetGuards();
            $token = $this->consumer->createToken('wrong-scope', $abilities)->plainTextToken;
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/costos/periodos?anio=2026')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')->getJson('/api/v1/costos/periodos?anio=2026&token='.$this->token)->assertUnauthorized();
    }
}
