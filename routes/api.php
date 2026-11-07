<?php

use App\Http\Controllers\Api\HealthApiController;
use App\Http\Controllers\Api\MapLayerApiController;
use App\Services\Providers\AisStreamAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// System Health & Observability Endpoint (Tahap 14)
Route::get('/health', [HealthApiController::class, 'index'])->name('api.health');

// Unified Map Search (Vessels & SAR Candidates)
Route::get('/map/search', [MapLayerApiController::class, 'search'])->name('api.map.search');

Route::prefix('layers')->group(function () {
    Route::get('/', [MapLayerApiController::class, 'layers'])->name('api.layers');
    Route::get('/fao', [MapLayerApiController::class, 'fao'])->name('api.layers.fao');
    Route::get('/eez', [MapLayerApiController::class, 'eez'])->name('api.layers.eez');
    Route::get('/eez-boundaries', [MapLayerApiController::class, 'eezBoundaries'])->name('api.layers.eez-boundaries');
    Route::get('/fma', [MapLayerApiController::class, 'fma'])->name('api.layers.fma');
    Route::get('/vessels', [MapLayerApiController::class, 'vessels'])->name('api.layers.vessels');
    Route::get('/encounters', [MapLayerApiController::class, 'encounters'])->name('api.layers.encounters');
    Route::get('/dark-vessels', [MapLayerApiController::class, 'darkVessels'])->name('api.layers.dark-vessels');
    Route::get('/sar-scenes', [MapLayerApiController::class, 'sarScenes'])->name('api.layers.sar-scenes');
});

Route::get('/encounters/{id}', [MapLayerApiController::class, 'encounterDetail'])->name('api.encounters.detail');

// Historical AIS Vessel Track API (Tahap 11)
Route::get('/vessels/{id}/track', [MapLayerApiController::class, 'vesselTrack'])->name('api.vessels.track');

// SAR Evidence & Track API (Tahap 8 & 9)
Route::get('/sar/candidates/{id}/evidence', [\App\Http\Controllers\SarEvidenceController::class, 'apiEvidence'])->name('api.sar.candidates.evidence');
Route::get('/sar/candidates/{id}/track', [\App\Http\Controllers\SarEvidenceController::class, 'apiTrack'])->name('api.sar.candidates.track');

// Genuine AIS Telemetry Ingestion Endpoint
Route::post('/telemetry/ais', function (Request $request, AisStreamAdapter $adapter) {
    $data = $request->validate([
        'mmsi' => ['required', 'string'],
        'latitude' => ['required', 'numeric', 'between:-90,90'],
        'longitude' => ['required', 'numeric', 'between:-180,180'],
        'speed_over_ground' => ['nullable', 'numeric'],
        'course_over_ground' => ['nullable', 'numeric'],
        'vessel_name' => ['nullable', 'string'],
        'ship_type' => ['nullable', 'string'],
        'flag' => ['nullable', 'string', 'max:3'],
        'timestamp' => ['nullable', 'date'],
    ]);

    $position = $adapter->ingestPositionMessage($data);

    if (!$position) {
        return response()->json(['status' => 'error', 'message' => 'Validasi posisi atau koordinat tidak valid.'], 422);
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Posisi AIS berhasil di-ingest.',
        'position_id' => $position->id,
        'vessel_id' => $position->vessel_id,
        'observed_at' => $position->observed_at->toIso8601String(),
    ]);
})->name('api.telemetry.ais');

// Production Batch Ingestion Endpoint (used by Node.js ais/worker.mjs & aaPanel worker)
Route::post('/telemetry/ais/batch', function (Request $request, AisStreamAdapter $adapter) {
    $expectedToken = config('maritime.providers.ais_stream.ingest_token')
        ?: config('maritime.providers.ais_stream.api_key');
    $providedToken = $request->header('X-AIS-Token')
        ?: $request->bearerToken();

    if (!$expectedToken || !hash_equals((string) $expectedToken, (string) $providedToken)) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized: Invalid or missing X-AIS-Token header.',
        ], 401);
    }

    $messages = $request->input('messages');
    if (!is_array($messages)) {
        $raw = $request->all();
        if (is_array($raw) && !empty($raw) && array_is_list($raw)) {
            $messages = $raw;
        }
    }

    if (!is_array($messages) || empty($messages)) {
        return response()->json([
            'status' => 'error',
            'message' => 'Format payload batch tidak valid atau kosong. Array pesan diperlukan.',
        ], 422);
    }

    $result = $adapter->ingestBatch($messages);

    return response()->json([
        'status' => 'success',
        'message' => 'Batch telemetri AIS berhasil diproses.',
        'received' => $result['received'],
        'inserted' => $result['inserted'],
        'skipped' => $result['skipped'],
        'errors_count' => count($result['errors'] ?? []),
    ]);
})->name('api.telemetry.ais.batch');
