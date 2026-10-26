<?php

namespace App\Http\Controllers;

use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private SyncService $sync) {}

    public function store(Request $r)
    {
        $data = $r->validate([
            'uuid' => 'required|uuid', 'anio' => 'required|integer|min:1900|max:9999', 'mes' => 'present|nullable|integer|min:1|max:12',
            'total_esperado' => 'required|integer|min:0|max:'.config('sync.max_rows'), 'total_lotes' => 'required|integer|min:0|max:'.config('sync.max_rows'),
            'checksum_total' => 'required|regex:/^[a-f0-9]{64}$/D', 'schema_checksum' => 'required|regex:/^[a-f0-9]{64}$/D', 'schema_json' => 'required|string|max:131072',
        ]);
        foreach (['anio', 'mes', 'total_esperado', 'total_lotes'] as $key) {
            if ($data[$key] !== null) {
                $data[$key] = (int) $data[$key];
            }
        }

        return response()->json(['data' => $this->sync->start($data, $r->user()->id)]);
    }

    public function batch(Request $r, string $uuid)
    {
        $data = $r->validate(['numero_lote' => 'required|integer|min:1', 'payload' => 'required|string', 'checksum' => 'required|regex:/^[a-f0-9]{64}$/D']);
        $data['numero_lote'] = (int) $data['numero_lote'];

        return response()->json(['data' => $this->sync->batch($uuid, $data, $r->user()->id)]);
    }

    public function complete(Request $r, string $uuid)
    {
        $data = $r->validate(['checksum_total' => 'required|regex:/^[a-f0-9]{64}$/D']);

        return response()->json(['data' => $this->sync->complete($uuid, $data['checksum_total'], $r->user()->id)]);
    }

    public function status(Request $r, string $uuid)
    {
        return response()->json(['data' => $this->sync->summary($this->sync->find($uuid, $r->user()->id))]);
    }

    public function verification(Request $r, string $uuid)
    {
        return response()->json(['data' => $this->sync->verification($uuid, $r->user()->id)]);
    }

    public function publication(Request $r)
    {
        $data = $r->validate(['anio' => 'required|integer|between:1900,9999', 'mes' => 'required|integer|between:1,12']);

        return response()->json(['data' => $this->sync->publication((int) $data['anio'], (int) $data['mes'], $r->user()->id)]);
    }
}
