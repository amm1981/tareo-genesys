<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));
Route::prefix('v1/sync')->middleware(['auth:sanctum', 'sync.token', 'throttle:120,1'])->group(function () {
    Route::post('/runs', [SyncController::class, 'store']);
    Route::post('/runs/{uuid}/batches', [SyncController::class, 'batch'])->whereUuid('uuid');
    Route::post('/runs/{uuid}/complete', [SyncController::class, 'complete'])->whereUuid('uuid');
    Route::get('/runs/{uuid}/status', [SyncController::class, 'status'])->whereUuid('uuid');
});
