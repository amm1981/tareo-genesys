<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('costos_agricolas', function (Blueprint $t) {
            $t->longText('Campaña')->nullable();
            $t->longText('EstadoLote')->nullable();
            $t->longText('fecha_origen')->nullable();
            $t->longText('AñoMes')->nullable();
            $t->integer('Año')->nullable();
            $t->integer('mes_origen')->nullable();
            $t->longText('NomMes')->nullable();
            $t->string('Operacion', 14)->nullable();
            $t->index(['sync_run_id', 'Operacion', 'fecha'], 'idx_source_operacion');
            $t->string('TablaOrigen', 20)->nullable();
            $t->index(['sync_run_id', 'TablaOrigen', 'fecha'], 'idx_source_tablaorigen');
            $t->longText('UG')->nullable();
            $t->longText('UG1')->nullable();
            $t->longText('UG2')->nullable();
            $t->longText('UGDet')->nullable();
            $t->longText('CCosto')->nullable();
            $t->longText('CCosto1')->nullable();
            $t->longText('CCDet')->nullable();
            $t->longText('UP')->nullable();
            $t->longText('UP1')->nullable();
            $t->longText('UP2')->nullable();
            $t->longText('UPDet')->nullable();
            $t->longText('Existencia')->nullable();
            $t->longText('C1')->nullable();
            $t->longText('C2')->nullable();
            $t->longText('C3')->nullable();
            $t->decimal('Kardex', 18, 0)->nullable();
            $t->longText('KardexNombre')->nullable();
            $t->longText('Concepto')->nullable();
            $t->longText('Medida')->nullable();
            $t->decimal('Cantidad', 19, 4)->nullable();
            $t->decimal('HAS', 19, 4)->nullable();
            $t->decimal('CostoMPSoles', 65, 30)->nullable();
            $t->decimal('CostoMPUS$', 65, 30)->nullable();
            $t->decimal('CostoMP1Soles', 65, 30)->nullable();
            $t->decimal('CostoMP1US$', 65, 30)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión manual: preservar columnas y datos de origen.');
    }
};
