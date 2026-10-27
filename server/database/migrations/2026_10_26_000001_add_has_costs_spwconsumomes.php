<?php

use App\Services\SourceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $source = app(SourceSchema::class);
        $metadata = file_get_contents(resource_path('schemas/spwconsumomes.v1.json'));
        if (hash('sha256', $metadata) !== 'fccdf733fd8125cbbd3ed54179713ec18f0305c775f2bf7646692218f4eb48f9') {
            throw new RuntimeException('Los metadatos de la migración no coinciden con la inspección revisada.');
        }
        $schema = $source->parse($metadata);
        // En --pretend solo la inspección de metadatos se ejecuta; el DDL sigue simulado.
        $existing = DB::connection()->withoutPretending(fn () => array_column(Schema::getColumns('costos_agricolas'), null, 'name'));
        $missing = [];
        foreach ($schema['columns'] as $column) {
            $name = $source->storageName($column['name']);
            if (isset($existing[$name])) {
                if (! $source->compatibleExisting($column, $existing[$name])) {
                    throw new RuntimeException('Columna existente incompatible: detener y revisar sin modificar datos.');
                }
            } elseif (in_array($column['name'], ['CostoHASSoles', 'CostoHASUS$', 'CostoHAS1Soles', 'CostoHAS1US$'], true)) {
                $missing[] = $column['name'];
            } else {
                throw new RuntimeException('Falta una columna anterior: revisar las migraciones instaladas.');
            }
        }
        // Metadatos observados el 26/10/2026: los cuatro campos son FLOAT(8), nullable.
        // El sobre DECIMAL conserva el token enviado; no implica exactitud del FLOAT original.
        Schema::table('costos_agricolas', function (Blueprint $table) use ($missing) {
            foreach ($missing as $name) {
                $table->decimal($name, 65, 30)->nullable();
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión de código únicamente: conservar columnas, versiones y valores publicados.');
    }
};
