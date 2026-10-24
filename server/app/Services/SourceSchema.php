<?php

namespace App\Services;

use App\Exceptions\SyncException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SourceSchema
{
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
            'version' => 'required|integer|in:1', 'database' => 'required|in:CASABLANCA', 'procedure' => 'required|in:dbo.SPWConsumoMesVF',
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
            if (DB::getDriverName() === 'mysql' && in_array($c['type'], self::EXACT) && strtolower($columns[$name]['type']) !== "decimal({$c['precision']},{$c['scale']})") {
                throw new SyncException('decimal_storage_mismatch', 'Precisión o escala MySQL incompatible con el origen.', 422);
            }
            if (DB::getDriverName() === 'mysql' && FloatCost::matches($c) && strtolower($columns[$name]['type']) !== 'decimal(65,30)') {
                throw new SyncException('decimal_storage_mismatch', 'Un costo FLOAT requiere DECIMAL(65,30) validado sin redondeo.', 422);
            }
        }

        return $schema;
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
