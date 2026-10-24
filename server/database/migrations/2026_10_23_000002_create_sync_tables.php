<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes')->nullable();
            $t->string('estado', 20)->default('pendiente');
            $t->unsignedBigInteger('total_esperado');
            $t->unsignedBigInteger('total_recibido')->default(0);
            $t->unsignedInteger('total_lotes');
            $t->unsignedInteger('lotes_recibidos')->default(0);
            $t->char('checksum_total', 64);
            $t->char('schema_checksum', 64);
            $t->longText('schema_json');
            $t->timestamp('fecha_inicio');
            $t->timestamp('fecha_finalizacion')->nullable();
            $t->string('error_resumen')->nullable();
            $t->timestamps();
            $t->index(['anio', 'mes', 'estado']);
        });
        Schema::create('sync_periods', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->foreignId('active_run_id')->nullable()->constrained('sync_runs');
            $t->foreignId('pending_run_id')->nullable()->constrained('sync_runs');
            $t->unique(['anio', 'mes']);
        });
        Schema::create('sync_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sync_run_id')->constrained('sync_runs');
            $t->unsignedInteger('numero_lote');
            $t->unsignedInteger('total_registros');
            $t->char('checksum', 64);
            $t->longText('payload');
            $t->timestamp('created_at');
            $t->unique(['sync_run_id', 'numero_lote']);
        });
        Schema::create('staging_costos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sync_run_id')->constrained('sync_runs');
            $t->unsignedBigInteger('numero_fila');
            $t->unsignedInteger('numero_lote');
            $t->longText('datos_json');
            $t->timestamp('created_at');
            $t->unique(['sync_run_id', 'numero_fila']);
            $t->index(['sync_run_id', 'numero_lote']);
        });
        Schema::create('costos_agricolas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sync_run_id')->constrained('sync_runs');
            $t->unsignedBigInteger('numero_fila');
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->date('fecha');
            // Agregar columnas reales del SP solamente tras inspeccionar el origen.
            $t->longText('datos_json');
            $t->timestamp('created_at');
            $t->unique(['sync_run_id', 'numero_fila']);
            $t->index(['anio', 'mes', 'sync_run_id']);
            $t->index('fecha');
        });
    }

    public function down(): void
    {
        foreach (['costos_agricolas', 'staging_costos', 'sync_batches', 'sync_periods', 'sync_runs'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
