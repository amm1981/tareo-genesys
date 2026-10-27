<?php

namespace App\Console\Commands;

use App\Services\SourceComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CompareSources extends Command
{
    protected $signature = 'sync:compare {before_uuid} {after_uuid} {--anio=} {--mes=}';

    protected $description = 'Reporte de lectura entre publicaciones, sin sumar costos ni modificar datos';

    public function handle(SourceComparison $comparison): int
    {
        $year = filter_var($this->option('anio'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 9999]]);
        $month = filter_var($this->option('mes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
        if (! $year || ! $month || ! Str::isUuid($this->argument('before_uuid')) || ! Str::isUuid($this->argument('after_uuid'))) {
            $this->error('Indicar dos UUID y un año/mes válidos.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($comparison->report($this->argument('before_uuid'), $this->argument('after_uuid'), $year, $month), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            $this->error('No se pudo comparar; revisar período, publicaciones y esquemas registrados. No se imprimen filas ni SQL.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
