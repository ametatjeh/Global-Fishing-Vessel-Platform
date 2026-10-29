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
