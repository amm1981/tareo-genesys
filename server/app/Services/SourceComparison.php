<?php

namespace App\Services;

use App\Exceptions\SyncException;
use Illuminate\Support\Facades\DB;

class SourceComparison
{
    public function __construct(private SourceSchema $schema) {}

    public function report(string $before, string $after, int $year, int $month): array
    {
        $runs = [];
        foreach ([$before, $after] as $uuid) {
            $run = DB::table('sync_runs')->where('uuid', $uuid)->where('estado', 'completada')->where('anio', $year)
                ->where(fn ($q) => $q->whereNull('mes')->orWhere('mes', $month))->first();
            if (! $run || ! hash_equals($run->schema_checksum, hash('sha256', $run->schema_json)) || ! $this->schema->registered($run->schema_json)) {
                throw new SyncException('comparison_unavailable', 'Se requieren dos publicaciones registradas y completadas del mismo período.', 422);
            }
            $runs[] = $run;
        }
        if ($this->schema->identity($runs[1]->schema_json)['classification'] !== 'current') {
            throw new SyncException('comparison_target', 'El destino de comparación debe ser la fuente definitiva.', 422);
        }
        $summaries = array_map(fn ($run) => $this->summarize($run, $year, $month), $runs);

        return ['anio' => $year, 'mes' => $month, 'before' => $summaries[0], 'after' => $summaries[1],
            'row_count_difference' => $summaries[1]['rows'] - $summaries[0]['rows'],
            'cost_summary_equal' => $summaries[0]['operations'] === $summaries[1]['operations'],
            'method' => 'NULL, negativos, min/max decimal y hash de secuencia por operación/columna; sin sumas ni asociación de ordinales entre fuentes.'];
    }

    private function summarize(object $run, int $year, int $month): array
    {
        $schema = $this->schema->parse($run->schema_json);
        $columns = array_filter($schema['columns'], fn ($c) => in_array($c['name'], FloatCost::COLUMNS, true));
        $groups = [];
        $rows = 0;
        foreach (DB::table('costos_agricolas')->where('sync_run_id', $run->id)->where('anio', $year)->where('mes', $month)->orderBy('id')->lazyById(250) as $row) {
            $rows++;
            $data = json_decode($row->datos_json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $operation = (string) $data['Operacion'];
            foreach ($columns as $column) {
                $name = $column['name'];
                $groups[$operation][$name] ??= ['nulls' => 0, 'negative' => 0, 'min' => null, 'max' => null, 'digest' => hash_init('sha256')];
                $item = &$groups[$operation][$name];
                if ($data[$name] === null) {
                    $item['nulls']++;
                    hash_update($item['digest'], "NULL\n");
                    unset($item);

                    continue;
                }
                $value = FloatCost::matches($column) ? FloatCost::fromJson($row->datos_json, $name) : $data[$name];
                $item['negative'] += bccomp($value, '0', 38) < 0 ? 1 : 0;
                if ($item['min'] === null || bccomp($value, $item['min'], 38) < 0) {
                    $item['min'] = $value;
                }
                if ($item['max'] === null || bccomp($value, $item['max'], 38) > 0) {
                    $item['max'] = $value;
                }
                hash_update($item['digest'], $value."\n");
                unset($item);
            }
        }
        ksort($groups);
        foreach ($groups as &$costs) {
            ksort($costs);
            foreach ($costs as &$item) {
                $item['sequence_sha256'] = hash_final($item['digest']);
                unset($item['digest']);
            }
            unset($item);
        }
        unset($costs);

        return ['uuid' => $run->uuid, 'source' => $this->schema->identity($run->schema_json), 'rows' => $rows,
            'available_cost_columns' => array_values(array_column($columns, 'name')), 'operations' => $groups];
    }
}
