<?php

namespace App\Services;

use App\Exceptions\SyncException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SourceSchema
{
    public const CURRENT = 'dbo.SPWConsumoMes';

    public const LEGACY = 'dbo.SPWConsumoMesVF';

    public const EXACT = ['Decimal', 'Numeric', 'Money', 'SmallMoney'];

    public const TYPES = ['TinyInt', 'SmallInt', 'Int', 'BigInt', 'Bit', 'Decimal', 'Numeric', 'Money', 'SmallMoney', 'Float', 'Real', 'Char', 'NChar', 'VarChar', 'NVarChar', 'Text', 'NText', 'Date', 'Time', 'DateTime', 'SmallDateTime', 'DateTime2', 'DateTimeOffset', 'UniqueIdentifier', 'Binary', 'VarBinary', 'Image', 'Xml'];

    public function parse(string $json): array
    {
        try {
            $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SyncException('invalid_schema', 'Esquema JSON inválido.', 422);
        }
        Validator::make(is_array($schema) ? $schema : [], [
            'version' => 'required|integer|in:1', 'database' => 'required|in:CASABLANCA', 'procedure' => 'required|in:'.self::CURRENT.','.self::LEGACY,
            'columns' => 'required|array|min:1|max:500', 'columns.*' => 'required|array:name,type,nullable,precision,scale,length',
            'columns.*.name' => 'required|string|max:128', 'columns.*.type' => 'required|in:'.implode(',', self::TYPES),
            'columns.*.nullable' => 'required|boolean', 'columns.*.precision' => 'present|nullable|integer|min:1|max:38',
            'columns.*.scale' => 'present|nullable|integer|min:0|max:38', 'columns.*.length' => 'present|nullable|integer',
        ])->validate();
        $seen = [];
        foreach ($schema['columns'] as $c) {
            $key = mb_strtolower($c['name']);
            if (isset($seen[$key]) || in_array($key, ['__proto__', 'constructor', 'prototype'])) {
                throw new SyncException('invalid_columns', 'Columnas duplicadas o no soportadas.', 422);
            }
            $seen[$key] = true;
            if (in_array($c['type'], self::EXACT) && (! is_int($c['precision']) || ! is_int($c['scale']) || $c['scale'] > $c['precision'])) {
                throw new SyncException('invalid_decimal', 'Precisión decimal no verificada.', 422);
            }
        }
        if (! in_array('Fecha', array_column($schema['columns'], 'name'), true)) {
            throw new SyncException('missing_fecha', 'Falta la columna real Fecha.', 422);
        }

        return $schema;
    }

    public function approved(string $json): array
    {
        $schema = $this->parse($json);
        if ($schema['procedure'] !== self::CURRENT) {
            throw new SyncException('legacy_source_read_only', 'La fuente anterior solo permite auditoría y consulta histórica.', 422);
        }
        if (count($schema['columns']) !== config('sync.expected_columns')) {
            throw new SyncException('current_schema_columns', 'La fuente actual requiere el esquema completo inspeccionado.', 422);
        }
        $file = config('sync.schema_file');
        if (! is_file($file) || ! hash_equals(hash('sha256', file_get_contents($file)), hash('sha256', $json))) {
            throw new SyncException('schema_not_approved', 'Inspeccione y prepare el esquema real antes de transferir.', 422);
        }
        $columns = array_column(Schema::getColumns('costos_agricolas'), null, 'name');
        foreach ($schema['columns'] as $c) {
            $name = $this->storageName($c['name']);
            if (! isset($columns[$name])) {
                throw new SyncException('schema_not_migrated', 'Faltan migraciones de las columnas de origen.', 422);
            }
            if (! $this->compatibleExisting($c, $columns[$name])) {
                throw new SyncException('schema_storage_mismatch', 'Almacenamiento existente incompatible; revisar sin alterar datos.', 422);
            }
            if (DB::getDriverName() === 'mysql' && in_array($c['type'], self::EXACT) && strtolower($columns[$name]['type']) !== "decimal({$c['precision']},{$c['scale']})") {
                throw new SyncException('decimal_storage_mismatch', 'Precisión o escala MySQL incompatible con el origen.', 422);
            }
            if (DB::getDriverName() === 'mysql' && FloatCost::matches($c) && strtolower($columns[$name]['type']) !== 'decimal(65,30)') {
                throw new SyncException('decimal_storage_mismatch', 'Un costo FLOAT requiere DECIMAL(65,30) validado sin redondeo.', 422);
            }
        }

        return $schema;
    }

    public function registered(string $json): bool
    {
        $schema = $this->parse($json);
        if ($schema['procedure'] === self::CURRENT && count($schema['columns']) !== config('sync.expected_columns')) {
            return false;
        }
        $files = $schema['procedure'] === self::CURRENT ? [config('sync.schema_file')] : config('sync.legacy_schema_files', []);
        foreach ($files as $file) {
            if (is_file($file) && hash_equals(hash('sha256', $json), hash_file('sha256', $file))) {
                return true;
            }
        }

        return false;
    }

    public function identity(string $json): array
    {
        $schema = $this->parse($json);

        return ['procedure' => $schema['procedure'], 'schema_version' => $schema['version'],
            'source_id' => $schema['procedure'] === self::CURRENT ? 'spwconsumomes-v1' : 'spwconsumomesvf-v1',
            'classification' => $schema['procedure'] === self::CURRENT ? 'current' : 'legacy',
            'column_count' => count($schema['columns']), 'schema_checksum' => hash('sha256', $json)];
    }

    public function compatibleExisting(array $column, array $stored): bool
    {
        if ($column['nullable'] && ! ($stored['nullable'] ?? false)) {
            return false;
        }
        if (DB::getDriverName() !== 'mysql') {
            return true; // SQLite solo se utiliza en las pruebas; no acredita tipos de MySQL.
        }
        $type = strtolower($stored['type']);
        if (in_array($column['type'], self::EXACT)) {
            return $type === "decimal({$column['precision']},{$column['scale']})";
        }
        if (FloatCost::matches($column)) {
            return $type === 'decimal(65,30)';
        }
        if (in_array($column['type'], ['Int', 'SmallInt', 'TinyInt', 'BigInt'])) {
            if (! preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)(?:\(\d+\))?( unsigned)?$/', $type, $match)) {
                return false;
            }
            $limits = ['tinyint' => ['-128', '127', '255'], 'smallint' => ['-32768', '32767', '65535'],
                'mediumint' => ['-8388608', '8388607', '16777215'], 'int' => ['-2147483648', '2147483647', '4294967295'],
                'integer' => ['-2147483648', '2147483647', '4294967295'], 'bigint' => ['-9223372036854775808', '9223372036854775807', '18446744073709551615']];
            $source = match ($column['type']) {
                'TinyInt' => ['0', '255'], 'SmallInt' => ['-32768', '32767'], 'Int' => ['-2147483648', '2147483647'],
                default => ['-9223372036854775808', '9223372036854775807'],
            };
            $target = $limits[$match[1]];

            return bccomp(isset($match[2]) ? '0' : $target[0], $source[0], 0) <= 0
                && bccomp(isset($match[2]) ? $target[2] : $target[1], $source[1], 0) >= 0;
        }
        if (in_array($column['type'], ['Float', 'Real'])) {
            return in_array($type, ['double', 'float'], true);
        }
        if (in_array($type, ['text', 'mediumtext', 'longtext'], true)) {
            return true;
        }
        if (! in_array($column['type'], ['Char', 'VarChar', 'NChar', 'NVarChar'], true)) {
            return false;
        }

        return preg_match('/^varchar\((\d+)\)$/', $type, $match) && $column['length'] > 0 && (int) $match[1] >= $column['length'];
    }

    public function storageName(string $name): string
    {
        // MySQL no distingue Fecha/fecha. Conservar Fecha completa como fecha_origen y el JSON original.
        return match ($name) {
            'Fecha' => 'fecha_origen', 'Mes' => 'mes_origen', default => $name
        };
    }

    public function row(string $json, array $schema, object $run): array
    {
        try {
            $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new SyncException('invalid_row', 'JSON de fila inválido.', 422);
        }
        if (! is_array($row) || array_is_list($row) || count($row) !== count($schema['columns'])) {
            throw new SyncException('invalid_row', 'Columnas de fila incorrectas.', 422);
        }
        foreach ($schema['columns'] as $c) {
            if (! array_key_exists($c['name'], $row)) {
                throw new SyncException('invalid_row', 'Columna de fila ausente.', 422);
            }
            $v = $row[$c['name']];
            if ($v === null && $c['nullable']) {
                continue;
            }
            $valid = match ($c['type']) {
                'Decimal','Numeric','Money','SmallMoney' => $this->decimal($v, $c['precision'], $c['scale']),
                'TinyInt' => is_int($v) && $v >= 0 && $v <= 255,
                'SmallInt' => is_int($v) && $v >= -32768 && $v <= 32767,
                'Int' => is_int($v) && $v >= -2147483648 && $v <= 2147483647,
                'BigInt' => is_string($v) && preg_match('/^-?\d{1,19}$/D', $v) && $this->bigint($v),
                'Bit' => is_bool($v),
                'Float','Real' => (is_float($v) || is_int($v)) && is_finite((float) $v),
                'Date' => is_string($v) && $this->date($v),
                'Time' => is_string($v) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,7})?$/D', $v),
                'DateTime','SmallDateTime','DateTime2','DateTimeOffset' => is_string($v) && $this->timestamp($v),
                'Binary','VarBinary','Image' => is_string($v) && str_starts_with($v, 'base64:') && base64_decode(substr($v, 7), true) !== false,
                'UniqueIdentifier' => is_string($v) && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $v),
                default => is_string($v),
            };
            if (! $valid) {
                throw new SyncException('invalid_type', 'Valor incompatible con el esquema de origen.', 422);
            }
            if (FloatCost::matches($c)) {
                FloatCost::fromJson($json, $c['name']);
            }
        }
        $fecha = $row['Fecha'];
        if (! is_string($fecha) || ! ($this->date($fecha) || $this->timestamp($fecha))) {
            throw new SyncException('invalid_fecha', 'Fecha inválida o ambigua.', 422);
        }
        if ((int) substr($fecha, 0, 4) !== $run->anio || ($run->mes !== null && (int) substr($fecha, 5, 2) !== $run->mes)) {
            throw new SyncException('wrong_period', 'Fila fuera del período solicitado.', 422);
        }

        return $row;
    }

    public function storageValue(array $column, mixed $value, string $json): mixed
    {
        if ($value !== null && FloatCost::matches($column)) {
            return FloatCost::fromJson($json, $column['name']);
        }

        return is_bool($value) ? (int) $value : $value;
    }

    private function bigint(string $v): bool
    {
        $n = ltrim(ltrim($v, '-'), '0');
        $limit = str_starts_with($v, '-') ? '9223372036854775808' : '9223372036854775807';

        return strlen($n) < 19 || (strlen($n) === 19 && strcmp($n, $limit) <= 0);
    }

    private function decimal(mixed $v, int $p, int $s): bool
    {
        if (! is_string($v) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', $v)) {
            return false;
        }
        $parts = explode('.', ltrim($v, '-'));

        return strlen(ltrim($parts[0], '0')) <= $p - $s && strlen($parts[1] ?? '') <= $s;
    }

    private function date(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    }

    private function timestamp(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,7})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)?$/D', $v) && $this->date(substr($v, 0, 10));
    }
}
