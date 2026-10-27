<?php

namespace App\Console\Commands;

use App\Services\FloatCost;
use App\Services\SourceSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PrepareSourceSchema extends Command
{
    protected $signature = 'sync:schema:prepare {path : JSON obtenido por inspect:schema, ya revisado}';

    protected $description = 'Genera una migración con nombres y precisión reales; no cambia tablas';

    public function handle(SourceSchema $source): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error('No existe el archivo de esquema.');

            return self::FAILURE;
        }
        $json = file_get_contents($path);
        $schema = $source->parse($json);
        if ($schema['procedure'] !== SourceSchema::CURRENT) {
            $this->error('La fuente histórica es de solo lectura.');

            return self::FAILURE;
        }
        if (count($schema['columns']) !== config('sync.expected_columns')) {
            $this->error('Inspeccionar el esquema completo de 38 columnas.');

            return self::FAILURE;
        }
        $names = ['id', 'sync_run_id', 'numero_fila', 'anio', 'mes', 'fecha', 'datos_json', 'created_at'];
        $lines = [];
        foreach ($schema['columns'] as $c) {
            $name = $source->storageName($c['name']);
            if (mb_strlen($name) > 64 || ! preg_match('/^[\pL_][\pL\pN_ $]*$/uD', $name) || in_array(mb_strtolower($name), $names, true)) {
                $this->error('Un nombre de origen necesita un mapeo explícito antes de preparar la migración.');

                return self::FAILURE;
            }
            $names[] = mb_strtolower($name);
            if (Schema::hasColumn('costos_agricolas', $name)) {
                $stored = array_column(Schema::getColumns('costos_agricolas'), null, 'name')[$name];
                if (! $source->compatibleExisting($c, $stored)) {
                    $this->error('Columna existente incompatible; requiere revisión sin alterar sus datos.');

                    return self::FAILURE;
                }

                continue;
            }
            $n = var_export($name, true);
            $dimension = in_array($c['name'], ['Operacion', 'TablaOrigen'], true) && in_array($c['type'], ['VarChar', 'NVarChar'], true) && $c['length'] > 0 && $c['length'] <= 191;
            $definition = FloatCost::matches($c) ? "decimal($n, 65, 30)" : ($dimension ? "string($n, {$c['length']})" : match ($c['type']) {
                'Decimal','Numeric','Money','SmallMoney' => "decimal($n, {$c['precision']}, {$c['scale']})",
                'TinyInt' => "unsignedTinyInteger($n)", 'SmallInt' => "smallInteger($n)", 'Int' => "integer($n)", 'BigInt' => "bigInteger($n)",
                'Bit' => "boolean($n)", 'Float','Real' => "double($n)",
                // Fechas como texto ISO: se conservan 7 decimales y el offset original; fecha indexa el día.
                default => "longText($n)",
            });
            $lines[] = '            $t->'.$definition.'->nullable();';
            if ($dimension) {
                $lines[] = '            $t->index([\'sync_run_id\', '.$n.', \'fecha\'], '.var_export('idx_source_'.strtolower($c['name']), true).');';
            }
        }
        if ($lines === []) {
            $this->info('No faltan columnas; revise aprobación del esquema por separado.');

            return self::SUCCESS;
        }
        if (is_file(resource_path('schemas/spwconsumomes.v1.json')) && hash_equals(hash_file('sha256', resource_path('schemas/spwconsumomes.v1.json')), hash('sha256', $json))) {
            $this->error('La migración incremental ya está incluida en el repositorio. Revisar migrate --pretend; no generar otra copia.');

            return self::FAILURE;
        }
        if (file_exists(config('sync.schema_file')) && file_get_contents(config('sync.schema_file')) !== $json) {
            $this->error('Existe otro esquema: conservarlo y configurar una ruta nueva para el esquema actual.');

            return self::FAILURE;
        }
        $filename = database_path('migrations/'.date('Y_m_d_His').'_add_source_columns_'.substr(hash('sha256', $json), 0, 8).'.php');
        $code = "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration {\n    public function up(): void {\n        Schema::table('costos_agricolas', function (Blueprint \$t) {\n".implode("\n", $lines)."\n        });\n    }\n    public function down(): void { throw new \\RuntimeException('Reversión manual: preservar columnas y datos de origen.'); }\n};\n";
        file_put_contents($filename, $code, LOCK_EX);
        if (! is_dir(dirname(config('sync.schema_file')))) {
            mkdir(dirname(config('sync.schema_file')), 0700, true);
        }
        file_put_contents(config('sync.schema_file'), $json, LOCK_EX);
        $this->info('Migración generada. Revísela y ejecute php artisan migrate. El esquema será rechazado hasta que existan las columnas.');
        $this->line($filename);

        return self::SUCCESS;
    }
}
