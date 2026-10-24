<?php

namespace App\Services;

use App\Exceptions\SyncException;

class FloatCost
{
    public const COLUMNS = ['CostoMPSoles', 'CostoMPUS$', 'CostoMP1Soles', 'CostoMP1US$'];

    public static function matches(array $column): bool
    {
        return in_array($column['name'], self::COLUMNS, true) && in_array($column['type'], ['Float', 'Real'], true);
    }

    public static function fromJson(string $json, string $name): string
    {
        // Leer el token numérico original, saltando strings JSON completos. No convertir a float.
        $pattern = <<<'REGEX'
~(?<key>"(?:[^"\\]|\\.)*")\s*:\s*(?<value>-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}])|"(?:[^"\\]|\\.)*"~s
REGEX;
        preg_match_all($pattern, $json, $tokens, PREG_SET_ORDER);
        $values = [];
        foreach ($tokens as $token) {
            if (($token['key'] ?? '') !== '' && json_decode($token['key'], true, 512, JSON_THROW_ON_ERROR) === $name) {
                $values[] = $token['value'];
            }
        }
        if (count($values) !== 1) {
            throw new SyncException('invalid_float_cost', 'Costo FLOAT ausente o ambiguo.', 422);
        }

        return self::expand($values[0]);
    }

    public static function expand(string $number): string
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D', $number, $m)) {
            throw new SyncException('invalid_float_cost', 'Representación de costo inválida.', 422);
        }
        $exponent = (int) ($m[4] ?? 0);
        if ($exponent < -400 || $exponent > 400) {
            throw new SyncException('float_cost_range', 'Costo fuera del rango decimal sin redondeo.', 422);
        }
        $digits = $m[2].($m[3] ?? '');
        $point = strlen($m[2]) + $exponent;
        if ($point <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $integer = $digits.str_repeat('0', $point - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $point);
            $fraction = substr($digits, $point);
        }
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        if (strlen(ltrim($integer, '0')) > 35 || strlen($fraction) > 30) {
            throw new SyncException('float_cost_range', 'Costo FLOAT no representable en DECIMAL(65,30) sin redondeo.', 422);
        }

        return (($integer !== '0' || $fraction !== '') ? $m[1] : '').$integer.($fraction === '' ? '' : '.'.$fraction);
    }
}
