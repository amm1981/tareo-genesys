<?php

namespace App\Http\Controllers;

use App\Services\PublishedCosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CostQueryController extends Controller
{
    public function index(Request $request, PublishedCosts $costs)
    {
        $input = Validator::make($request->query(), [
            'anio' => ['required', 'integer', 'between:1900,9999'],
            'mes' => ['required', 'integer', 'between:1,12'],
            'operacion' => ['sometimes', 'string', 'min:1', 'max:14'],
            'tabla_origen' => ['sometimes', 'string', 'min:1', 'max:20'],
            'fecha_desde' => ['sometimes', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'date_format:Y-m-d'],
            'limite' => ['sometimes', 'integer', 'between:1,500'],
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
        ])->validate();
        $period = sprintf('%04d-%02d', $input['anio'], $input['mes']);
        foreach (['fecha_desde', 'fecha_hasta'] as $key) {
            if (isset($input[$key]) && substr($input[$key], 0, 7) !== $period) {
                throw ValidationException::withMessages([$key => 'La fecha debe pertenecer al año y mes solicitados.']);
            }
        }
        if (isset($input['fecha_desde'], $input['fecha_hasta']) && $input['fecha_hasta'] < $input['fecha_desde']) {
            throw ValidationException::withMessages(['fecha_hasta' => 'Intervalo de fechas inválido.']);
        }
        $filters = [
            'anio' => (int) $input['anio'], 'mes' => (int) $input['mes'],
            'operacion' => $input['operacion'] ?? null, 'tabla_origen' => $input['tabla_origen'] ?? null,
            'fecha_desde' => $input['fecha_desde'] ?? null, 'fecha_hasta' => $input['fecha_hasta'] ?? null,
            'limite' => (int) ($input['limite'] ?? 100),
        ];

        return response()->json($costs->page($filters, $input['cursor'] ?? null, $request->user()->id));
    }

    public function periods(Request $request, PublishedCosts $costs)
    {
        $input = Validator::make($request->query(), ['anio' => ['required', 'integer', 'between:1900,9999']])->validate();

        return response()->json(['data' => $costs->periods((int) $input['anio'])]);
    }
}
