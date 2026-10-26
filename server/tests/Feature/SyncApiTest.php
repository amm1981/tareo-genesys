<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync.schema_file' => base_path('tests/fixtures/source-schema.json')]);
        $hasSourceDate = Schema::hasColumn('costos_agricolas', 'fecha_origen');
        Schema::table('costos_agricolas', function (Blueprint $t) use ($hasSourceDate) {
            if (! $hasSourceDate) {
                $t->longText('fecha_origen')->nullable();
            }
            $t->longText('ImporteFixture')->nullable();
            $t->longText('DetalleFixture')->nullable();
        });
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('fixture', ['sync:write'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function row(string $fecha = '2026-09-01T12:30:00.1234567', mixed $amount = '123456789012345678901234567890.12345678'): string
    {
        return json_encode(['Fecha' => $fecha, 'ImporteFixture' => $amount, 'DetalleFixture' => '  á漢字 😀  '], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function plan(array $jsonRows = [], ?int $mes = 9): array
    {
        $batches = [];
        $n = 0;
        $ledger = '';
        foreach ($jsonRows as $json) {
            $number = ++$n;
            $payload = json_encode([['numero_fila' => $number, 'datos_json' => $json]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $checksum = hash('sha256', $payload);
            $batches[] = ['numero_lote' => $number, 'payload' => $payload, 'checksum' => $checksum];
            $ledger .= $number.':'.$checksum."\n";
        }
        $schema = file_get_contents(config('sync.schema_file'));

        return [['uuid' => (string) Str::uuid(), 'anio' => 2026, 'mes' => $mes, 'total_esperado' => count($jsonRows), 'total_lotes' => count($batches), 'checksum_total' => hash('sha256', $ledger), 'schema_json' => $schema, 'schema_checksum' => hash('sha256', $schema)], $batches];
    }

    private function send(array $plan, array $batches): void
    {
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        foreach ($batches as $batch) {
            $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $batch)->assertOk();
        }
    }

    private function finish(array $plan)
    {
        return $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/complete', ['checksum_total' => $plan['checksum_total']]);
    }

    private function activeCount(): int
    {
        return DB::table('costos_agricolas as c')->join('sync_periods as p', function ($j) {
            $j->on('p.active_run_id', '=', 'c.sync_run_id')->on('p.anio', '=', 'c.anio')->on('p.mes', '=', 'c.mes');
        })->count();
    }

    public function test_automation_inspection_verifies_publication_counts_and_keeps_pending_separate(): void
    {
        $url = '/api/v1/sync/publication?anio=2026&mes=9';
        $this->getJson($url)->assertOk()->assertJsonPath('data.active', null)->assertJsonPath('data.pending', null);
        [$plan, $batches] = $this->plan([$this->row(), $this->row()]);
        $this->send($plan, $batches);
        $this->getJson($url)->assertOk()->assertJsonPath('data.active', null)->assertJsonPath('data.pending.uuid', $plan['uuid'])->assertJsonPath('data.pending.verificado', false);
        $this->finish($plan)->assertOk();
        $result = $this->getJson($url)->assertOk()->assertJsonPath('data.pending', null)
            ->assertJsonPath('data.active.verificado', true)->assertJsonPath('data.active.filas_publicadas', 2)
            ->assertJsonPath('data.active.checksum_total', $plan['checksum_total']);
        $this->assertArrayNotHasKey('schema_json', $result->json('data.active'));
        $this->getJson('/api/v1/sync/runs/'.$plan['uuid'].'/verification')->assertOk()->assertJsonPath('data.verificado', true);
        DB::table('costos_agricolas')->limit(1)->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('data.active.verificado', false);
    }

    public function test_automation_inspection_detects_changed_batch_ledger(): void
    {
        [$plan, $batches] = $this->plan([$this->row()]);
        $this->send($plan, $batches);
        $this->finish($plan)->assertOk();
        DB::table('sync_batches')->update(['checksum' => str_repeat('0', 64)]);
        $this->getJson('/api/v1/sync/runs/'.$plan['uuid'].'/verification')->assertOk()->assertJsonPath('data.verificado', false);
    }

    public function test_rotation_revokes_only_the_chosen_sync_token_of_the_technical_account(): void
    {
        $technical = User::factory()->create(['email' => 'sync@get-genesys.invalid']);
        $old = $technical->createToken('old', ['sync:write']);
        $new = $technical->createToken('new', ['sync:write']);
        $read = $technical->createToken('read', ['costs:read']);
        $this->artisan('sync:revoke-token', ['id' => $old->accessToken->id])->assertSuccessful();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $old->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $new->accessToken->id]);
        $this->artisan('sync:revoke-token', ['id' => $read->accessToken->id])->assertFailed();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $read->accessToken->id]);
    }

    public function test_inspection_is_owner_scoped_and_requires_sync_permission(): void
    {
        [$plan] = $this->plan();
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        $other = User::factory()->create();
        $token = $other->createToken('other', ['sync:write'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/sync/runs/'.$plan['uuid'].'/verification')->assertNotFound();
        $this->getJson('/api/v1/sync/publication?anio=2026&mes=9')->assertConflict();
        $read = $other->createToken('query', ['costs:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$read)->getJson('/api/v1/sync/publication?anio=2026&mes=9')->assertForbidden();
    }

    public function test_inspection_of_empty_and_annual_publications_and_invalid_period(): void
    {
        [$empty] = $this->plan();
        $this->postJson('/api/v1/sync/runs', $empty)->assertOk();
        $this->finish($empty)->assertOk();
        $this->getJson('/api/v1/sync/publication?anio=2026&mes=9')->assertOk()->assertJsonPath('data.active.verificado', true)->assertJsonPath('data.active.filas_publicadas', 0);
        [$annual, $batches] = $this->plan([$this->row(), $this->row('2026-10-01T00:00:00')], null);
        $this->send($annual, $batches);
        $this->finish($annual)->assertOk();
        foreach ([1, 9, 10, 12] as $month) {
            $this->getJson('/api/v1/sync/publication?anio=2026&mes='.$month)->assertOk()->assertJsonPath('data.active.uuid', $annual['uuid'])->assertJsonPath('data.active.verificado', true);
        }
        $this->getJson('/api/v1/sync/publication?anio=2026&mes=13')->assertUnprocessable();
    }

    public function test_valid_bearer_token_and_health_with_minimal_information(): void
    {
        $this->getJson('/api/health')->assertExactJson(['data' => ['status' => 'ok']]);
        [$plan] = $this->plan();
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk()->assertJsonPath('data.estado', 'pendiente');
    }

    public function test_invalid_or_absent_token_is_rejected(): void
    {
        [$plan] = $this->plan();
        $this->withHeader('Authorization', 'Bearer invalid')->postJson('/api/v1/sync/runs', $plan)->assertUnauthorized();
        $this->withoutHeader('Authorization')->postJson('/api/v1/sync/runs', $plan)->assertUnauthorized();
    }

    public function test_tokens_without_exclusive_ability_and_expired_tokens_are_rejected(): void
    {
        [$plan] = $this->plan();
        foreach ([['read'], ['*'], ['sync:write', 'admin']] as $abilities) {
            $this->app['auth']->forgetGuards();
            $token = $this->user->createToken('fixture', $abilities)->plainTextToken;
            $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/runs', $plan)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $token = $this->user->createToken('expired', ['sync:write'], now()->subMinute())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/runs', $plan)->assertUnauthorized();
    }

    public function test_year_month_and_schema_are_validated(): void
    {
        [$plan] = $this->plan();
        foreach ([['anio' => 1899], ['mes' => 13], ['mes' => 0], ['uuid' => 'x'], ['schema_checksum' => str_repeat('0', 64)]] as $change) {
            $this->postJson('/api/v1/sync/runs', array_replace($plan, $change))->assertUnprocessable();
        }
        config(['sync.schema_file' => base_path('tests/fixtures/missing.json')]);
        $this->postJson('/api/v1/sync/runs', $plan)->assertUnprocessable();
    }

    public function test_batch_retry_is_idempotent_and_duplicate_content_rows_are_retained(): void
    {
        [$plan,$batches] = $this->plan([$this->row(), $this->row()]);
        $this->send($plan, $batches);
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $batches[0])->assertOk();
        $this->assertDatabaseCount('staging_costos', 2);
        $this->assertDatabaseCount('sync_batches', 2);
        $this->finish($plan)->assertOk()->assertJsonPath('data.total_recibido', 2)->assertJsonPath('data.estado', 'completada');
        $this->assertSame(2, $this->activeCount());
        $this->finish($plan)->assertOk();
        $this->assertDatabaseCount('costos_agricolas', 2);
    }

    public function test_start_is_idempotent_but_uuid_content_conflict_is_rejected(): void
    {
        [$plan] = $this->plan([$this->row()]);
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        $this->assertDatabaseCount('sync_runs', 1);
        $this->postJson('/api/v1/sync/runs', array_replace($plan, ['checksum_total' => str_repeat('0', 64)]))->assertConflict();
    }

    public function test_wrong_checksum_and_changed_retry_are_rejected(): void
    {
        [$plan,$batches] = $this->plan([$this->row()]);
        $this->send($plan, $batches);
        $bad = $batches[0];
        $bad['checksum'] = str_repeat('0', 64);
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $bad)->assertUnprocessable();
        $bad['payload'] = str_replace('2026-09-01', '2026-09-02', $bad['payload']);
        $bad['checksum'] = hash('sha256', $bad['payload']);
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $bad)->assertConflict();
    }

    public function test_incomplete_transfer_preserves_previous_snapshot(): void
    {
        [$old,$batches] = $this->plan([$this->row()]);
        $this->send($old, $batches);
        $this->finish($old)->assertOk();
        $active = DB::table('sync_periods')->value('active_run_id');
        [$next,$batches] = $this->plan([$this->row(), $this->row()]);
        $this->postJson('/api/v1/sync/runs', $next)->assertOk();
        $this->postJson('/api/v1/sync/runs/'.$next['uuid'].'/batches', $batches[0])->assertOk();
        $this->finish($next)->assertConflict();
        $this->assertSame($active, DB::table('sync_periods')->value('active_run_id'));
        $this->assertSame(1, $this->activeCount());
    }

    public function test_repeating_period_replaces_active_snapshot_without_deduplicating_valid_rows(): void
    {
        foreach ([1, 2] as $count) {
            [$plan,$batches] = $this->plan(array_fill(0, $count, $this->row()));
            $this->send($plan, $batches);
            $this->finish($plan)->assertOk();
            $this->assertSame($count, $this->activeCount());
        }
        $this->assertDatabaseCount('costos_agricolas', 3); // Historial conservado, solo dos filas publicadas.
    }

    public function test_staging_tampering_rolls_back_all_publication(): void
    {
        [$plan,$batches] = $this->plan([$this->row(), $this->row()]);
        $this->send($plan, $batches);
        DB::table('staging_costos')->where('numero_fila', 2)->update(['datos_json' => $this->row('2026-09-02T00:00:00')]);
        $this->finish($plan)->assertConflict();
        $this->assertDatabaseCount('costos_agricolas', 0);
        $this->assertNull(DB::table('sync_periods')->value('active_run_id'));
    }

    public function test_manifest_integrity_failure_rolls_back_inserted_rows(): void
    {
        [$plan,$batches] = $this->plan([$this->row()]);
        $plan['checksum_total'] = str_repeat('0', 64);
        $this->send($plan, $batches);
        $this->finish($plan)->assertConflict();
        $this->assertDatabaseCount('costos_agricolas', 0);
    }

    public function test_rows_are_validated_without_float_conversion_or_period_leak(): void
    {
        foreach ([$this->row('2027-09-01T00:00:00'), $this->row('2026-08-01T00:00:00'), $this->row('2026-02-30T00:00:00'), $this->row('2026-09-01T00:00:00', 123.45), $this->row('2026-09-01T00:00:00', '0.123456789')] as $json) {
            [$plan,$batches] = $this->plan([$json]);
            $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
            $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $batches[0])->assertUnprocessable();
            app(SyncService::class)->abort($plan['uuid']);
        }
        $this->assertDatabaseCount('staging_costos', 0);
    }

    public function test_json_original_preserves_decimal_unicode_spaces_and_date_precision(): void
    {
        $json = $this->row();
        [$plan,$batches] = $this->plan([$json]);
        $this->send($plan, $batches);
        $this->finish($plan)->assertOk();
        $this->assertSame($json, DB::table('costos_agricolas')->value('datos_json'));
        $this->assertSame('123456789012345678901234567890.12345678', DB::table('costos_agricolas')->value('ImporteFixture'));
        $this->assertSame('2026-09-01T12:30:00.1234567', DB::table('costos_agricolas')->value('fecha_origen'));
    }

    public function test_same_period_and_overlapping_annual_runs_are_locked_until_abort(): void
    {
        [$plan] = $this->plan([$this->row()]);
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        [$other] = $this->plan([$this->row()]);
        $this->postJson('/api/v1/sync/runs', $other)->assertConflict();
        [$annual] = $this->plan([], null);
        $this->postJson('/api/v1/sync/runs', $annual)->assertConflict();
        app(SyncService::class)->abort($plan['uuid']);
        $this->postJson('/api/v1/sync/runs', $other)->assertOk();
    }

    public function test_annual_periods_publish_together_and_empty_period_is_supported(): void
    {
        [$plan,$batches] = $this->plan([$this->row(), $this->row('2026-10-01T00:00:00')], null);
        $this->send($plan, $batches);
        $this->finish($plan)->assertOk();
        $this->assertSame(2, $this->activeCount());
        $this->assertSame(12, DB::table('sync_periods')->whereNotNull('active_run_id')->count());
        [$empty] = $this->plan();
        $this->send($empty, []);
        $this->finish($empty)->assertOk();
        $this->assertSame(1, $this->activeCount());
    }

    public function test_batch_order_size_and_row_sequence_are_enforced(): void
    {
        [$plan,$batches] = $this->plan([$this->row(), $this->row()]);
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $batches[1])->assertConflict();
        $bad = $batches[0];
        $rows = json_decode($bad['payload'], true);
        $rows[0]['numero_fila'] = 99;
        $bad['payload'] = json_encode($rows);
        $bad['checksum'] = hash('sha256', $bad['payload']);
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $bad)->assertUnprocessable();
        config(['sync.max_batch_bytes' => 10]);
        $this->postJson('/api/v1/sync/runs/'.$plan['uuid'].'/batches', $batches[0])->assertStatus(413);
    }

    public function test_status_is_scoped_to_token_owner_and_contains_no_rows(): void
    {
        [$plan] = $this->plan();
        $this->postJson('/api/v1/sync/runs', $plan)->assertOk();
        $this->getJson('/api/v1/sync/runs/'.$plan['uuid'].'/status')->assertOk()->assertJsonMissingPath('data.schema_json');
        $other = User::factory()->create()->createToken('other', ['sync:write'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$other)->getJson('/api/v1/sync/runs/'.$plan['uuid'].'/status')->assertNotFound();
    }

    public function test_remote_http_is_rejected(): void
    {
        $this->getJson('http://example.com/api/health')->assertForbidden();
    }
}
