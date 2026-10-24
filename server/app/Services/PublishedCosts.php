<?php

namespace App\Services;

use App\Exceptions\SyncException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublishedCosts
{
    public function __construct(private SourceSchema $schema) {}

    public function periods(int $year): array
    {
        return DB::table('sync_periods as p')
            ->join('sync_runs as r', 'r.id', '=', 'p.active_run_id')
            ->leftJoin('costos_agricolas as c', function ($join) {
                $join->on('c.sync_run_id', '=', 'p.active_run_id')->on('c.anio', '=', 'p.anio')->on('c.mes', '=', 'p.mes');
            })
            ->where('p.anio', $year)->where('r.estado', 'completada')
            ->groupBy('p.anio', 'p.mes', 'r.uuid', 'r.fecha_finalizacion')
            ->orderBy('p.mes')
            ->select(['p.anio', 'p.mes', 'r.uuid', 'r.fecha_finalizacion'])
            ->selectRaw('COUNT(c.id) AS filas')->get()
            ->map(fn ($row) => [
                'anio' => (int) $row->anio, 'mes' => (int) $row->mes,
                'publicacion_uuid' => $row->uuid, 'publicado_en' => $row->fecha_finalizacion,
                'filas' => (int) $row->filas,
            ])->all();
    }

    public function page(array $filters, ?string $cursor, int $user): array
    {
        $fingerprint = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        $after = 0;
        $expires = now()->addMinutes(config('api_access.cursor_ttl_minutes'))->timestamp;
        if ($cursor !== null) {
            try {
                $state = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($state) || ($state['v'] ?? null) !== 1 || ($state['user'] ?? null) !== $user || ($state['filters'] ?? null) !== $fingerprint || ! is_string($state['uuid'] ?? null) || ! Str::isUuid($state['uuid']) || ! is_int($state['after'] ?? null) || $state['after'] < 1 || ! is_int($state['expires'] ?? null) || $state['expires'] <= now()->timestamp) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                throw new SyncException('invalid_cursor', 'Cursor inválido, vencido o incompatible con la consulta.', 422);
            }
            $after = $state['after'];
            $expires = $state['expires'];
            // El cursor autenticado mantiene la publicación elegida por la primera página.
            $run = DB::table('sync_runs')->where('uuid', $state['uuid'])->where('anio', $filters['anio'])
                ->where(fn ($q) => $q->whereNull('mes')->orWhere('mes', $filters['mes']))
                ->where('estado', 'completada')->first();
        } else {
            $run = DB::table('sync_periods as p')->join('sync_runs as r', 'r.id', '=', 'p.active_run_id')
                ->where('p.anio', $filters['anio'])->where('p.mes', $filters['mes'])
                ->where('r.estado', 'completada')->select('r.*')->first();
        }
        if (! $run) {
            throw new SyncException('period_not_published', 'No hay una publicación disponible para el período solicitado.', 404);
        }
        $query = DB::table('costos_agricolas')->where('sync_run_id', $run->id)
            ->where('anio', $filters['anio'])->where('mes', $filters['mes'])->where('numero_fila', '>', $after);
        foreach (['operacion' => 'Operacion', 'tabla_origen' => 'TablaOrigen'] as $filter => $column) {
            if ($filters[$filter] !== null) {
                $query->where($column, $filters[$filter]);
            }
        }
        if ($filters['fecha_desde'] !== null) {
            $query->where('fecha', '>=', $filters['fecha_desde']);
        }
        if ($filters['fecha_hasta'] !== null) {
            $query->where('fecha', '<=', $filters['fecha_hasta']);
        }
        $rows = $query->orderBy('numero_fila')->limit($filters['limite'] + 1)->get(['numero_fila', 'fecha', 'datos_json']);
        $hasMore = $rows->count() > $filters['limite'];
        $rows = $rows->take($filters['limite']);
        $schema = $this->schema->parse($run->schema_json);
        $data = $rows->map(function ($row) use ($schema) {
            $values = json_decode($row->datos_json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            foreach ($schema['columns'] as $column) {
                if (FloatCost::matches($column) && ($values[$column['name']] ?? null) !== null) {
                    // Recuperar el token numérico del JSON original, nunca del float decodificado.
                    $values[$column['name']] = FloatCost::fromJson($row->datos_json, $column['name']);
                }
            }

            return ['numero_fila' => (int) $row->numero_fila, 'fecha' => $row->fecha, 'datos' => $values];
        })->values()->all();
        $next = $hasMore ? Crypt::encryptString(json_encode([
            'v' => 1, 'user' => $user, 'uuid' => $run->uuid, 'after' => (int) $rows->last()->numero_fila,
            'filters' => $fingerprint, 'expires' => $expires,
        ], JSON_THROW_ON_ERROR)) : null;

        return ['data' => $data, 'meta' => [
            'anio' => $filters['anio'], 'mes' => $filters['mes'],
            'publicacion_uuid' => $run->uuid, 'publicado_en' => $run->fecha_finalizacion,
            'paginacion' => ['limite' => $filters['limite'], 'cantidad' => count($data), 'hay_mas' => $hasMore, 'siguiente_cursor' => $next],
        ]];
    }
}
