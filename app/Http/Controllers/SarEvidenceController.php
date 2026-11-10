<?php

namespace App\Http\Controllers;

use App\Models\SatelliteDetection;
use App\Services\Sar\SarEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SarEvidenceController extends Controller
{
    public function __construct(
        protected SarEvidenceService $evidenceService,
        protected ?\App\Services\Sar\SarAisCorrelationService $aisCorrelationService = null
    ) {
        $this->aisCorrelationService = $aisCorrelationService ?? app(\App\Services\Sar\SarAisCorrelationService::class);
    }

    /**
     * Authenticated endpoint to serve individual SAR evidence crop images and metadata.
     */
    public function serveEvidenceImage(Request $request, string $candidate, string $type): BinaryFileResponse|JsonResponse
    {
        // Find detection safely by identifier or UUID without UUID cast errors
        $detection = SatelliteDetection::whereIdentifierOrId($candidate)->first();
        if (!$detection) {
            abort(404, "SAR candidate '{$candidate}' not found.");
        }

        $sceneId = $detection->scene?->scene_id ?? '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7';
        $identifier = $detection->detection_identifier;

        $fileMap = [
            'raw' => 'raw.png',
            'sigma0-linear' => 'sigma0-linear.png',
            'sigma0-db' => 'sigma0-db.png',
            'metadata' => 'metadata.json',
        ];

        if (!isset($fileMap[$type])) {
            abort(404, 'Invalid evidence file type. Available options: raw, sigma0-linear, sigma0-db, metadata.');
        }

        $filename = $fileMap[$type];
        $filePath = storage_path("app/sar_evidence/{$sceneId}/{$identifier}/{$filename}");

        if (!File::exists($filePath)) {
            abort(404, 'SAR raster evidence files have not been extracted for this candidate.');
        }

        $contentType = ($type === 'metadata') ? 'application/json' : 'image/png';

        return response()->file($filePath, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'X-SAR-Provenance' => $identifier,
        ]);
    }

    /**
     * Public / Internal JSON API endpoint for SAR Evidence Metadata (Tahap 8 & 9).
     * Strictly omits secret credentials and API tokens.
     */
    public function apiEvidence(string $id): JsonResponse
    {
        $detection = SatelliteDetection::with(['scene', 'reviewer', 'correlatedVessel'])
            ->whereIdentifierOrId($id)
            ->first();

        if (!$detection) {
            return response()->json([
                'status' => 'error',
                'message' => "SAR candidate with identifier '{$id}' not found.",
            ], 404);
        }

        $evidenceData = $this->evidenceService->getEvidenceForDetection($detection);
        $meta = $evidenceData['metadata'];

        return response()->json([
            'status' => 'success',
            'candidate_id' => $detection->id,
            'candidate_identifier' => $detection->detection_identifier,
            'scene_id' => $detection->scene?->scene_id,
            'source' => [
                'product_id' => $meta['source_product'] ?? $detection->scene?->scene_id,
                'platform' => $detection->scene?->platform_name ?? 'Sentinel-1C',
                'instrument' => $detection->scene?->instrument ?? 'SAR-C',
                'sensor_mode' => $detection->scene?->sensor_mode ?? 'EW',
                'polarization' => $detection->scene?->polarization ?? 'HH',
                'acquisition_time_utc' => $detection->detected_at?->toIso8601String(),
            ],
            'raster' => [
                'source_measurement' => $meta['source_measurement_file'] ?? 's1c-ew-grd-hh-20261019t135212-20261019t135236-009954-013d8a-001.tiff',
                'source_crs' => $meta['source_crs'] ?? 'EPSG:4326',
                'output_crs' => 'EPSG:4326',
                'pixel_spacing_meters' => 40.0,
                'effective_spatial_resolution_meters' => [
                    'range_meters' => 93.0,
                    'azimuth_meters' => 87.0,
                ],
                'crop_window_px' => $meta['crop_window'] ?? ['width_px' => 61, 'height_px' => 61],
                'crop_extent_meters' => ['width_m' => 2440.0, 'height_m' => 2440.0],
            ],
            'target' => [
                'latitude' => $detection->latitude,
                'longitude' => $detection->longitude,
                'tcr_db' => $detection->tcr_db,
                'confidence_score' => $detection->confidence_score,
                'estimated_length_meters' => $detection->estimated_length_meters,
                'estimated_width_meters' => $detection->estimated_width_meters,
                'pixel_count' => $detection->review_metadata['pixel_count'] ?? 1,
            ],
            'evidence_urls' => $evidenceData['urls'],
            'indicators' => $evidenceData['indicators'],
            'matrix' => $evidenceData['matrix'],
            'timeline' => $evidenceData['timeline'],
            'checklist' => $evidenceData['checklist'],
            'gfw_status' => $evidenceData['gfw_status'],
            'sea_ice_status' => $evidenceData['sea_ice_status'],
            'optical_status' => $evidenceData['optical_status'],
            'track_geojson_url' => route('api.sar.candidates.track', $detection->detection_identifier),
            'correlation' => [
                'status' => $detection->correlation_status,
                'method' => $detection->correlation_method,
                'distance_to_nearest_ais_meters' => $detection->distance_to_nearest_ais_meters,
                'correlated_vessel' => $detection->correlatedVessel ? [
                    'id' => $detection->correlatedVessel->id,
                    'name' => $detection->correlatedVessel->name,
                    'mmsi' => $detection->correlatedVessel->mmsi,
                ] : null,
                'evidence' => $evidenceData['correlation_evidence'],
                'integrity_notice' => 'Absence of AIS data does not independently establish that the target is a dark vessel.',
            ],
            'review' => [
                'status' => $detection->review_status,
                'reviewer_name' => $detection->reviewer?->name,
                'reviewed_at_utc' => $detection->reviewed_at?->toIso8601String(),
                'rationale' => $detection->review_rationale,
            ],
            'disclaimer' => $detection->disclaimer,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Historical AIS Track GeoJSON endpoint for MapLibre track inspection (Tahap 9 - Requirement 2 & 8).
     */
    public function apiTrack(Request $request, string $id): JsonResponse
    {
        $detection = SatelliteDetection::whereIdentifierOrId($id)->first();

        if (!$detection) {
            return response()->json([
                'status' => 'error',
                'message' => "SAR candidate with identifier '{$id}' not found.",
            ], 404);
        }

        // Validate window parameter: 30, 60, 180, 360, 1440
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'window' => 'nullable|integer|in:30,60,180,360,1440',
        ], [
            'window.in' => 'Invalid time window parameter. Supported options: 30, 60, 180, 360, 1440 minutes.',
            'window.integer' => 'Window parameter must be an integer.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid window parameter.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $windowMinutes = (int) $request->input('window', 60);

        $geoJson = $this->aisCorrelationService->getHistoricalTrackGeoJson($detection, $windowMinutes);

        return response()->json($geoJson, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
