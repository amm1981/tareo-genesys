<?php

namespace Tests\Feature;

use App\Services\SourceSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrepareSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepares_reviewable_migration_with_verified_names_precision_and_scale(): void
    {
        // Simular una instalación aún sin columnas de origen solo en SQLite efímero.
        $this->assertSame('sqlite', DB::getDriverName());
        Schema::drop('costos_agricolas');
        Schema::create('costos_agricolas', fn (Blueprint $t) => $t->id());
        $target = storage_path('app/private/schema-test-'.Str::uuid().'.json');
        config(['sync.schema_file' => $target]);
        config(['sync.expected_columns' => 3]);
        $before = glob(database_path('migrations/*_add_source_columns_*.php'));
        $created = [];
        try {
            $this->artisan('sync:schema:prepare', ['path' => base_path('tests/fixtures/source-schema.json')])->assertSuccessful();
            $created = array_values(array_diff(glob(database_path('migrations/*_add_source_columns_*.php')), $before));
            $this->assertCount(1, $created);
            $code = file_get_contents($created[0]);
            $this->assertStringContainsString("decimal('ImporteFixture', 38, 8)", $code);
            $this->assertStringContainsString("longText('fecha_origen')", $code);
            $this->assertFalse(Schema::hasColumn('costos_agricolas', 'ImporteFixture'));
            $migration = require $created[0];
            $migration->up();
            $this->assertSame('CASABLANCA', app(SourceSchema::class)->approved(file_get_contents($target))['database']);
        } finally {
            foreach ($created as $file) {
                unlink($file);
            }
            if (is_file($target)) {
                unlink($target);
            }
        }
    }
}
