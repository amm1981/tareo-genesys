<?php

namespace Tests\Feature;

use App\Services\FloatCost;
use App\Services\SourceSchema;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InstantHasMigrationTest extends TestCase
{
    private const PATH = 'database/migrations/2026_10_26_000001_add_has_costs_spwconsumomes.php';

    private const HAS = ['CostoHASSoles', 'CostoHASUS$', 'CostoHAS1Soles', 'CostoHAS1US$'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'mysql_preview', 'database.connections.mysql_preview' => [
            'driver' => 'mysql', 'database' => 'synthetic', 'prefix' => '',
        ]]);
        // A real MySQL grammar and pretend logger, with no possible database access.
        DB::connection()->setPdo(fn () => throw new RuntimeException('Database access forbidden in this test.'));
    }

    private function existing(array $missing): array
    {
        $source = app(SourceSchema::class);
        $schema = json_decode(file_get_contents(resource_path('schemas/spwconsumomes.v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $columns = [];
        foreach ($schema['columns'] as $column) {
            if (in_array($column['name'], $missing, true)) {
                continue;
            }
            $type = match (true) {
                FloatCost::matches($column) => 'decimal(65,30)',
                in_array($column['type'], ['Numeric', 'Decimal', 'Money', 'SmallMoney'], true) => "decimal({$column['precision']},{$column['scale']})",
                $column['type'] === 'Int' => 'int',
                default => 'longtext',
            };
            $columns[] = ['name' => $source->storageName($column['name']), 'type' => $type, 'nullable' => true];
        }

        return $columns;
    }

    public static function missingColumns(): array
    {
        return ['all four' => [self::HAS], 'partially installed' => [['CostoHASUS$', 'CostoHAS1US$']], 'already installed' => [[]]];
    }

    #[DataProvider('missingColumns')]
    public function test_mysql_generates_one_instant_statement_for_only_missing_columns(array $missing): void
    {
        Schema::shouldReceive('getColumns')->once()->with('costos_agricolas')->andReturn($this->existing($missing));
        $migration = require base_path(self::PATH);
        $queries = DB::connection()->pretend(fn () => $migration->up());
        if ($missing === []) {
            $this->assertSame([], $queries);

            return;
        }
        $this->assertCount(1, $queries);
        $expected = 'ALTER TABLE `costos_agricolas` '.implode(', ', array_map(fn ($name) => 'ADD COLUMN `'.$name.'` DECIMAL(65,30) NULL', $missing)).', ALGORITHM=INSTANT';
        $this->assertSame($expected, $queries[0]['query']);
        $this->assertSame([], $queries[0]['bindings']);
        $this->assertDoesNotMatchRegularExpression('/\b(INPLACE|COPY)\b/i', $queries[0]['query']);
    }

    public function test_artisan_migrate_pretend_displays_a_single_instant_alter_without_executing_it(): void
    {
        Schema::shouldReceive('getColumns')->once()->with('costos_agricolas')->andReturn($this->existing(self::HAS));
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('setSource')->withAnyArgs();
        $repository->shouldReceive('repositoryExists')->andReturn(true);
        $repository->shouldReceive('getRan')->andReturn([]);
        $repository->shouldReceive('getNextBatchNumber')->once()->andReturn(1);
        $repository->shouldNotReceive('log');
        $this->app->instance('migration.repository', $repository);

        $this->assertSame(0, Artisan::call('migrate', [
            '--pretend' => true, '--database' => 'mysql_preview', '--path' => self::PATH,
        ]));
        $output = Artisan::output();
        $this->assertSame(1, substr_count($output, 'ALTER TABLE'));
        $this->assertSame(1, substr_count($output, 'ALGORITHM=INSTANT'));
        foreach (self::HAS as $name) {
            $this->assertStringContainsString('ADD COLUMN `'.$name.'` DECIMAL(65,30) NULL', $output);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(INPLACE|COPY)\b/i', $output);
    }

    public function test_mysql_rejection_is_propagated_without_retrying_another_algorithm(): void
    {
        Schema::shouldReceive('getColumns')->once()->andReturn($this->existing(self::HAS));
        $attempts = [];
        DB::connection()->beforeExecuting(function ($sql) use (&$attempts) {
            $attempts[] = $sql;
            throw new RuntimeException('ALGORITHM=INSTANT rejected by server');
        });
        $migration = require base_path(self::PATH);
        try {
            $migration->up();
            $this->fail('The database error must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ALGORITHM=INSTANT rejected by server', $exception->getMessage());
        }
        $this->assertCount(1, $attempts);
        $this->assertStringEndsWith(', ALGORITHM=INSTANT', $attempts[0]);
    }

    public static function invalidHistoricalColumns(): array
    {
        return ['missing historical column' => [true], 'incompatible historical column' => [false]];
    }

    #[DataProvider('invalidHistoricalColumns')]
    public function test_historical_validation_aborts_before_any_ddl(bool $remove): void
    {
        $columns = $this->existing(self::HAS);
        if ($remove) {
            array_shift($columns);
        } else {
            $columns[0]['type'] = 'varchar(1)';
        }
        Schema::shouldReceive('getColumns')->once()->andReturn($columns);
        $attempts = [];
        DB::connection()->beforeExecuting(function ($sql) use (&$attempts) {
            $attempts[] = $sql;
        });
        $migration = require base_path(self::PATH);
        try {
            $migration->up();
            $this->fail('Invalid historical storage must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($remove ? 'Falta una columna anterior' : 'Columna existente incompatible', $exception->getMessage());
        }
        $this->assertSame([], $attempts);
    }

    public function test_destructive_rollback_remains_blocked(): void
    {
        $migration = require base_path(self::PATH);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conservar columnas, versiones y valores publicados');
        $migration->down();
    }
}
