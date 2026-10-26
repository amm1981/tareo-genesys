<?php

namespace App\Services;

use App\Exceptions\SyncException;
use Illuminate\Support\Facades\DB;

class SyncService
{
    public function __construct(private SourceSchema $schema) {}

    private function transactionalStorage(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $tables = ['sync_runs', 'sync_periods', 'sync_batches', 'staging_costos', 'costos_agricolas'];
        $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereIn('TABLE_NAME', $tables)->pluck('ENGINE');
        if ($engines->count() !== count($tables) || $engines->contains(fn ($engine) => $engine !== 'InnoDB')) {
            throw new SyncException('innodb_required', 'Todas las tablas de sincronización requieren InnoDB.', 503);
        }
    }

    private function months(object|array $run): array
    {
        $mes = is_array($run) ? ($run['mes'] ?? null) : $run->mes;

        return $mes === null ? range(1, 12) : [(int) $mes];
    }

    public function find(string $uuid, int $user, bool $lock = false): object
    {
        $q = DB::table('sync_runs')->where('uuid', $uuid)->where('user_id', $user);
        $run = ($lock ? $q->lockForUpdate() : $q)->first();
        if (! $run) {
            throw new SyncException('run_not_found', 'Ejecución no encontrada.', 404);
        }

        return $run;
    }

    public function summary(object $run): array
    {
        return array_intersect_key((array) $run, array_flip(['uuid', 'anio', 'mes', 'estado', 'total_esperado', 'total_recibido', 'total_lotes', 'lotes_recibidos', 'fecha_inicio', 'fecha_finalizacion', 'error_resumen']));
    }

    public function verification(string $uuid, int $user): array
    {
        $run = $this->find($uuid, $user);
        $rows = DB::table('costos_agricolas')->where('sync_run_id', $run->id)->count();
        $digest = hash_init('sha256');
        $batches = 0;
        $received = 0;
        $ordered = true;
        foreach (DB::table('sync_batches')->where('sync_run_id', $run->id)->orderBy('numero_lote')->select(['numero_lote', 'total_registros', 'checksum'])->cursor() as $batch) {
            $batches++;
            $ordered = $ordered && (int) $batch->numero_lote === $batches;
            $received += (int) $batch->total_registros;
            hash_update($digest, $batch->numero_lote.':'.$batch->checksum."\n");
        }
        $schemaFile = config('sync.schema_file');
        $verified = $run->estado === 'completada' && $run->fecha_finalizacion !== null
            && $rows === (int) $run->total_esperado && (int) $run->total_recibido === $rows
            && $batches === (int) $run->total_lotes && (int) $run->lotes_recibidos === $batches
            && $received === $rows && $ordered && hash_equals($run->checksum_total, hash_final($digest))
            && hash_equals($run->schema_checksum, hash('sha256', $run->schema_json))
            && is_file($schemaFile) && hash_equals($run->schema_checksum, hash_file('sha256', $schemaFile));
        if ($verified) {
            $this->schema->approved($run->schema_json);
        }

        return $this->summary($run) + ['checksum_total' => $run->checksum_total, 'schema_checksum' => $run->schema_checksum, 'filas_publicadas' => $rows, 'verificado' => $verified];
    }

    public function publication(int $year, int $month, int $user): array
    {
        return DB::transaction(function () use ($year, $month, $user) {
            $period = DB::table('sync_periods')->where('anio', $year)->where('mes', $month)->first();
            $result = ['anio' => $year, 'mes' => $month, 'active' => null, 'pending' => null];
            foreach (['active' => 'active_run_id', 'pending' => 'pending_run_id'] as $key => $column) {
                if ($period && $period->$column !== null) {
                    $run = DB::table('sync_runs')->find($period->$column);
                    if (! $run || (int) $run->user_id !== $user) {
                        throw new SyncException('period_owner_conflict', 'El período requiere revisión del administrador de sincronización.', 409);
                    }
                    $result[$key] = $this->verification($run->uuid, $user);
                }
            }

            return $result;
        });
    }

    public function start(array $input, int $user): array
    {
        $this->transactionalStorage();
        if (! hash_equals($input['schema_checksum'], hash('sha256', $input['schema_json']))) {
            throw new SyncException('schema_checksum', 'Checksum de esquema incorrecto.', 422);
        }
        $this->schema->approved($input['schema_json']);
        if (($input['total_esperado'] === 0) !== ($input['total_lotes'] === 0) || $input['total_lotes'] > $input['total_esperado'] || $input['total_esperado'] > $input['total_lotes'] * config('sync.max_batch_rows')) {
            throw new SyncException('invalid_totals', 'Totales incompatibles.', 422);
        }

        return DB::transaction(function () use ($input, $user) {
            // Crear y bloquear períodos en orden; también evita solapamiento anual/mensual.
            foreach ($this->months($input) as $month) {
                DB::table('sync_periods')->insertOrIgnore(['anio' => $input['anio'], 'mes' => $month]);
            }
            $periods = DB::table('sync_periods')->where('anio', $input['anio'])->whereIn('mes', $this->months($input))->orderBy('mes')->lockForUpdate()->get();
            $existing = DB::table('sync_runs')->where('uuid', $input['uuid'])->lockForUpdate()->first();
            if ($existing) {
                foreach (['anio', 'mes', 'total_esperado', 'total_lotes', 'checksum_total', 'schema_checksum', 'schema_json'] as $key) {
                    if ($existing->$key !== ($input[$key] ?? null)) {
                        throw new SyncException('run_conflict', 'UUID utilizado con otro contenido.');
                    }
                }
                if ($existing->user_id !== $user) {
                    throw new SyncException('run_not_found', 'Ejecución no encontrada.', 404);
                }

                return $this->summary($existing);
            }
            foreach ($periods as $p) {
                if ($p->pending_run_id !== null) {
                    throw new SyncException('period_busy', 'Existe una transferencia pendiente para este período.');
                }
            }
            $data = array_intersect_key($input, array_flip(['uuid', 'anio', 'mes', 'total_esperado', 'total_lotes', 'checksum_total', 'schema_checksum', 'schema_json']));
            $id = DB::table('sync_runs')->insertGetId($data + ['user_id' => $user, 'estado' => 'pendiente', 'fecha_inicio' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sync_periods')->whereIn('id', $periods->pluck('id'))->update(['pending_run_id' => $id]);

            return $this->summary($this->find($input['uuid'], $user));
        }, 3);
    }

    public function batch(string $uuid, array $input, int $user): array
    {
        $this->transactionalStorage();
        if (strlen($input['payload']) > config('sync.max_batch_bytes')) {
            throw new SyncException('batch_too_large', 'Lote demasiado grande.', 413);
        }
        if (! hash_equals($input['checksum'], hash('sha256', $input['payload']))) {
            throw new SyncException('checksum_mismatch', 'Checksum de lote incorrecto.', 422);
        }
        try {
            $rows = json_decode($input['payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SyncException('invalid_payload', 'Payload inválido.', 422);
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > config('sync.max_batch_rows')) {
            throw new SyncException('invalid_payload', 'Cantidad de filas inválida.', 422);
        }

        return DB::transaction(function () use ($uuid, $input, $user, $rows) {
            $run = $this->find($uuid, $user, true);
            $previous = DB::table('sync_batches')->where('sync_run_id', $run->id)->where('numero_lote', $input['numero_lote'])->first();
            if ($previous) {
                if ($previous->checksum !== $input['checksum'] || $previous->payload !== $input['payload']) {
                    throw new SyncException('batch_conflict', 'Número de lote utilizado con otro contenido.');
                }

                return $this->summary($run);
            }
            if ($run->estado !== 'pendiente' || $input['numero_lote'] !== $run->lotes_recibidos + 1 || $input['numero_lote'] > $run->total_lotes || $run->total_recibido + count($rows) > $run->total_esperado) {
                throw new SyncException('batch_order', 'Estado, secuencia o total de lote incorrectos.');
            }
            $schema = $this->schema->parse($run->schema_json);
            $inserts = [];
            foreach ($rows as $i => $r) {
                if (! is_array($r) || count($r) !== 2 || ! isset($r['numero_fila'],$r['datos_json']) || ! is_int($r['numero_fila']) || $r['numero_fila'] !== $run->total_recibido + $i + 1 || ! is_string($r['datos_json'])) {
                    throw new SyncException('row_order', 'Número o contenido de fila inválido.', 422);
                }
                $this->schema->row($r['datos_json'], $schema, $run);
                $inserts[] = ['sync_run_id' => $run->id, 'numero_lote' => $input['numero_lote'], 'numero_fila' => $r['numero_fila'], 'datos_json' => $r['datos_json'], 'created_at' => now()];
            }
            foreach (array_chunk($inserts, 100) as $chunk) {
                DB::table('staging_costos')->insert($chunk);
            }
            DB::table('sync_batches')->insert(['sync_run_id' => $run->id, 'numero_lote' => $input['numero_lote'], 'total_registros' => count($rows), 'checksum' => $input['checksum'], 'payload' => $input['payload'], 'created_at' => now()]);
            DB::table('sync_runs')->where('id', $run->id)->update(['total_recibido' => $run->total_recibido + count($rows), 'lotes_recibidos' => $run->lotes_recibidos + 1, 'updated_at' => now()]);

            return $this->summary($this->find($uuid, $user));
        }, 3);
    }

    public function complete(string $uuid, string $checksum, int $user): array
    {
        $this->transactionalStorage();

        return DB::transaction(function () use ($uuid, $checksum, $user) {
            $run = $this->find($uuid, $user, true);
            if (! hash_equals($run->checksum_total, $checksum)) {
                throw new SyncException('manifest_checksum', 'Checksum final incorrecto.', 422);
            }
            if ($run->estado === 'completada') {
                return $this->summary($run);
            }
            if ($run->estado !== 'pendiente' || $run->total_recibido !== $run->total_esperado || $run->lotes_recibidos !== $run->total_lotes) {
                throw new SyncException('incomplete', 'Transferencia incompleta.');
            }
            $schema = $this->schema->approved($run->schema_json);
            $digest = hash_init('sha256');
            $batchNumber = 0;
            $rowNumber = 0;
            // Lotes acotados: recalcular hash y cotejar staging antes de publicar.
            foreach (DB::table('sync_batches')->where('sync_run_id', $run->id)->orderBy('numero_lote')->lazyById(1) as $batch) {
                if ($batch->numero_lote !== ++$batchNumber || ! hash_equals($batch->checksum, hash('sha256', $batch->payload))) {
                    throw new SyncException('corrupt_batch', 'Integridad de lote incorrecta.');
                }
                hash_update($digest, $batch->numero_lote.':'.$batch->checksum."\n");
                $rows = json_decode($batch->payload, true, 512, JSON_THROW_ON_ERROR);
                $staged = DB::table('staging_costos')->where('sync_run_id', $run->id)->where('numero_lote', $batch->numero_lote)->orderBy('numero_fila')->get();
                if (count($rows) !== $batch->total_registros || count($rows) !== $staged->count()) {
                    throw new SyncException('corrupt_staging', 'Cantidad de staging incorrecta.');
                }
                $inserts = [];
                foreach ($rows as $i => $wire) {
                    $stage = $staged[$i];
                    if ($wire['numero_fila'] !== ++$rowNumber || $stage->numero_fila !== $rowNumber || $wire['datos_json'] !== $stage->datos_json) {
                        throw new SyncException('corrupt_staging', 'Integridad de staging incorrecta.');
                    }
                    $row = $this->schema->row($stage->datos_json, $schema, $run);
                    $record = ['sync_run_id' => $run->id, 'numero_fila' => $rowNumber, 'anio' => $run->anio, 'mes' => (int) substr($row['Fecha'], 5, 2), 'fecha' => substr($row['Fecha'], 0, 10), 'datos_json' => $stage->datos_json, 'created_at' => now()];
                    foreach ($schema['columns'] as $c) {
                        $v = $row[$c['name']];
                        $record[$this->schema->storageName($c['name'])] = $this->schema->storageValue($c, $v, $stage->datos_json);
                    }
                    $inserts[] = $record;
                }
                foreach (array_chunk($inserts, 50) as $chunk) {
                    DB::table('costos_agricolas')->insert($chunk);
                }
            }
            if ($rowNumber !== $run->total_esperado || DB::table('staging_costos')->where('sync_run_id', $run->id)->count() !== $rowNumber || ! hash_equals($run->checksum_total, hash_final($digest))) {
                throw new SyncException('integrity_failed', 'No coincide la integridad final.');
            }
            $periods = DB::table('sync_periods')->where('anio', $run->anio)->whereIn('mes', $this->months($run))->orderBy('mes')->lockForUpdate()->get();
            foreach ($periods as $p) {
                if ($p->pending_run_id !== $run->id) {
                    throw new SyncException('period_conflict', 'Reserva del período no coincide.');
                }
            }
            DB::table('sync_periods')->whereIn('id', $periods->pluck('id'))->update(['active_run_id' => $run->id, 'pending_run_id' => null]);
            DB::table('sync_runs')->where('id', $run->id)->update(['estado' => 'completada', 'fecha_finalizacion' => now(), 'updated_at' => now()]);

            return $this->summary($this->find($uuid, $user));
        }, 3);
    }

    public function abort(string $uuid): void
    {
        DB::transaction(function () use ($uuid) {
            $run = DB::table('sync_runs')->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $run || $run->estado !== 'pendiente') {
                throw new SyncException('not_pending', 'La ejecución no está pendiente.');
            }
            DB::table('sync_periods')->where('pending_run_id', $run->id)->update(['pending_run_id' => null]);
            DB::table('sync_runs')->where('id', $run->id)->update(['estado' => 'fallida', 'error_resumen' => 'Cancelada manualmente por administrador.', 'fecha_finalizacion' => now(), 'updated_at' => now()]);
        }, 3);
    }
}
