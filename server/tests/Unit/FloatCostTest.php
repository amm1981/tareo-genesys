<?php

namespace Tests\Unit;

use App\Exceptions\SyncException;
use App\Services\FloatCost;
use PHPUnit\Framework\TestCase;

class FloatCostTest extends TestCase
{
    public function test_expands_original_numeric_tokens_without_php_float_rounding(): void
    {
        foreach (['0.30000000000000004' => '0.30000000000000004', '1.234567890123456789e-3' => '0.001234567890123456789', '-1.23e+4' => '-12300', '1e-30' => '0.'.str_repeat('0', 29).'1', '-0' => '0'] as $input => $expected) {
            $this->assertSame($expected, FloatCost::fromJson('{"CostoMPUS$":'.$input.'}', 'CostoMPUS$'));
        }
    }

    public function test_ignores_lookalike_keys_inside_strings_and_handles_unicode(): void
    {
        $json = json_encode(['Concepto' => 'texto, "CostoMPUS$":999, á 😀', 'CostoMPUS$' => 0.30000000000000004], JSON_UNESCAPED_UNICODE);
        $this->assertSame('0.30000000000000004', FloatCost::fromJson($json, 'CostoMPUS$'));
    }

    public function test_rejects_duplicate_numeric_keys(): void
    {
        $this->expectException(SyncException::class);
        FloatCost::fromJson('{"CostoMPUS$":1,"CostoMPUS$":2}', 'CostoMPUS$');
    }

    public function test_rejects_values_that_need_rounding(): void
    {
        $this->expectException(SyncException::class);
        FloatCost::expand('1e-31');
    }
}
