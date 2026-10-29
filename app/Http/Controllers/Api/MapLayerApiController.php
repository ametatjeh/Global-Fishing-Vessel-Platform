<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataProvider;
use App\Models\SatelliteDetection;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Services\Maritime\FlagStateService;
use App\Services\SpatialQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapLayerApiController extends Controller
{
    public function __construct(protected SpatialQueryService $spatial)
    {
    }

    /**
     * Get Layer metadata and status
     */
    public function layers(): JsonResponse
    {
        $providers = DataProvider::pluck('health_status', 'slug');

        return response()->json([
            'layers' => [
                [
                    'id' => 'layer-live-vessels',
                    'name' => 'Live AIS Vessels',
                    'type' => 'symbol',
                    'color' => '#38bdf8', // sky-400
                    'provider_status' => $providers['ais-stream'] ?? 'not_configured',
                    'license' => 'Proprietary Live Feed',
                    'description' => 'Real-time telemetry and positional data of broadcasting vessels.',
                ],
                [
                    'id' => 'layer-fao-areas',
                    'name' => 'FAO Major Fishing Areas',
                    'type' => 'fill',
                    'color' => '#3b82f6', // blue-500
                    'provider_status' => 'connected',
                    'version' => config('maritime.fao.version', '2026.1-CWP'),
                    'license' => config('maritime.fao.license', 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO'),
                    'source_url' => config('maritime.fao.source_url', 'https://www.fao.org/fishery/geoserver/fifao/ows'),
                    'description' => 'Wilayah statistik perikanan global FAO untuk pelaporan hasil tangkapan (bukan batas yurisdiksi maritim).',
                    'disclaimer' => config('maritime.fao.disclaimer', 'Wilayah statistik FAO tidak menyatakan pendapat hukum apapun mengenai status hukum negara atau perbatasan laut.'),
                ],
                [
                    'id' => 'layer-eez-boundaries',
                    'name' => 'EEZ Boundaries',
                    'type' => 'line',
                    'color' => '#64748b', // slate-500
                    'provider_status' => 'connected',
                    'version' => 'v12 (October 2023)',
                    'license' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
                    'source_url' => 'https://www.marineregions.org/downloads.php',
                    'attribution' => 'Flanders Marine Institute (VLIZ) Marine Regions (DOI: 10.14284/632)',
                    'description' => 'Garis batas resmi delimitasi maritim global Marine Regions v12 (Treaty, 200 NM outer limit, median lines).',
                    'disclaimer' => 'Batas yurisdiksi hak ekonomi maritim UNCLOS / traktat bilateral. Bukan penetapan batas hukum final untuk klaim tumpang tindih.',
                ],
                [
                    'id' => 'layer-eez-zones',
                    'name' => 'World EEZ (Marine Regions v12)',
                    'type' => 'fill',
                    'color' => '#14b8a6', // teal-500
                    'provider_status' => 'connected',
                    'version' => 'v12 (October 2023)',
                    'license' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
                    'source_url' => 'https://www.marineregions.org/downloads.php',
                    'attribution' => 'Flanders Marine Institute (VLIZ) Marine Regions (DOI: 10.14284/632)',
                    'description' => 'Batas referensi Zona Ekonomi Eksklusif 200NM dan delimitasi maritim global Marine Regions v12.',
                    'disclaimer' => 'Batas yurisdiksi hak ekonomi maritim UNCLOS / traktat bilateral. Bukan penetapan batas hukum final untuk klaim tumpang tindih.',
                ],
                [
                    'id' => 'layer-fma-rfmo',
                    'name' => 'Fisheries Management Areas (RFMO)',
                    'type' => 'fill',
                    'color' => '#a855f7', // purple-500
                    'provider_status' => 'connected',
                    'version' => '2024.1',
                    'license' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
                    'source_url' => 'https://www.fao.org/fishery/geoserver/fifao/ows',
                    'description' => 'Wilayah kompetensi organisasi pengelolaan perikanan regional multilateral (RFMO/RFB FAO: WCPFC, IOTC, CCSBT, ICCAT, dll).',
                    'disclaimer' => 'Area kompetensi mandat pengelolaan perikanan internasional. Bukan batas kedaulatan maritim negara.',
                ],
                [
                    'id' => 'layer-vessel-encounters',
                    'name' => 'Vessel Encounters',
                    'type' => 'circle',
                    'color' => '#f59e0b', // amber-500
                    'provider_status' => 'connected',
                    'algorithm' => config('maritime.encounters.algorithm_version'),
                    'description' => 'Spatio-temporal proximity events detected between vessel pairs.',
                    'disclaimer' => config('maritime.encounters.disclaimer'),
                ],
                [
                    'id' => 'layer-dark-vessels',
                    'name' => 'Dark Vessel Candidates (SAR)',
                    'type' => 'circle',
                    'color' => '#f43f5e', // rose-500
                    'provider_status' => $providers['copernicus-sentinel-1'] ?? 'not_configured',
                    'description' => 'Radar detections without active AIS transponder match.',
                ],
            ]
        ]);
    }

    /**
     * Get FAO Major Fishing Areas GeoJSON
     */
    public function fao(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');

        $data = $this->spatial->getFaoFeatureCollection($bbox, $search);

        return response()->json($data);
    }

    /**
     * Get Marine Regions EEZ Boundaries GeoJSON
     */
    public function eez(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');

        $data = $this->spatial->getEezFeatureCollection($bbox, $search);

        return response()->json($data);
    }

    /**
     * Get Marine Regions EEZ Boundaries Line Geometries GeoJSON
     */
    public function eezBoundaries(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');

        $data = $this->spatial->getEezBoundariesFeatureCollection($bbox, $search);

        return response()->json($data);
    }

    /**
     * Get FAO Regional Fisheries Management Areas (RFMO / RFB) GeoJSON
     */
    public function fma(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');

        $data = $this->spatial->getFisheriesManagementAreasFeatureCollection($bbox, $search);

        return response()->json($data);
    }

    /**
     * Get Live AIS Vessels Positions GeoJSON
     */
    public function vessels(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');

        $filters = [
            'flag' => $request->query('flag'),
            'vessel_type' => $request->query('vessel_type'),
            'min_sog' => $request->query('min_sog'),
            'max_sog' => $request->query('max_sog'),
            'min_cog' => $request->query('min_cog'),
            'max_cog' => $request->query('max_cog'),
            'heading_status' => $request->query('heading_status'),
            'freshness' => $request->query('freshness'),
            'date_range' => $request->query('date_range'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
        ];

        $data = $this->spatial->getLiveVesselsFeatureCollection($bbox, $search, 200, $filters);

        return response()->json($data);
    }

    /**
     * Unified Global Search across Vessels and SAR Candidates
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if (strlen($query) < 1) {
            return response()->json(['results' => [], 'count' => 0]);
        }

        $results = [];

        // 1. Search Vessels (name, mmsi, imo, callsign)
        $vessels = Vessel::with('latestPosition')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('mmsi', 'like', "%{$query}%")
                  ->orWhere('imo', 'like', "%{$query}%")
                  ->orWhere('callsign', 'like', "%{$query}%");
            })
            ->limit(8)
            ->get();

        foreach ($vessels as $v) {
            $pos = $v->latestPosition;
            $lat = $pos ? (float) $pos->latitude : null;
            $lng = $pos ? (float) $pos->longitude : null;
            $hasCoords = $lat !== null && $lng !== null && is_finite($lat) && is_finite($lng) && $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;

            $flagInfo = FlagStateService::resolveFromCodeAndMmsi($v->flag_state, $v->mmsi);

            $results[] = [
                'type' => 'vessel',
                'id' => $v->id,
                'vessel_id' => $v->id,
                'title' => $v->name ?? 'Unknown Vessel',
                'subtitle' => "MMSI: {$v->mmsi} • {$flagInfo['country_name']} ({$flagInfo['iso2']})",
                'badge' => strtoupper($v->vessel_type ?? 'Vessel'),
                'badge_color' => 'sky',
                'flag_emoji' => $flagInfo['flag_emoji'],
                'has_coords' => $hasCoords,
                'lat' => $lat,
                'lng' => $lng,
                'sog' => $pos && $pos->speed_knots !== null ? "{$pos->speed_knots} kn" : null,
                'cog' => $pos && $pos->course_degrees !== null ? "{$pos->course_degrees}°" : null,
                'observed_at' => $pos?->observed_at?->toIso8601String(),
            ];
        }

        // 2. Search SAR Candidates (detection_identifier, analyst_notes)
        $candidates = SatelliteDetection::with('scene')
            ->where(function ($q) use ($query) {
                $q->where('detection_identifier', 'like', "%{$query}%")
                  ->orWhere('analyst_notes', 'like', "%{$query}%");
            })
            ->limit(8)
            ->get();

        foreach ($candidates as $c) {
            $lat = (float) $c->latitude;
            $lng = (float) $c->longitude;
            $hasCoords = is_finite($lat) && is_finite($lng) && $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;

            $results[] = [
                'type' => 'sar_candidate',
                'id' => $c->id,
                'title' => $c->detection_identifier,
                'subtitle' => "SAR Candidate • Conf: " . round($c->confidence_score * 100) . "% • TCR: " . ($c->tcr_db !== null ? "{$c->tcr_db} dB" : '-'),
                'badge' => strtoupper(str_replace('_', ' ', $c->review_status)),
                'badge_color' => 'amber',
                'flag_emoji' => '🎯',
                'has_coords' => $hasCoords,
                'lat' => $lat,
                'lng' => $lng,
                'detected_at' => $c->detected_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Get Vessel Encounters GeoJSON
     */
    public function encounters(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status');

        $data = $this->spatial->getEncountersFeatureCollection($bbox, $startDate, $endDate, $status);

        return response()->json($data);
    }

    /**
     * Get detail of a specific encounter
     */
    public function encounterDetail(string $id): JsonResponse
    {
        $encounter = VesselEncounter::with(['vessel1', 'vessel2', 'sourceProvider', 'verifiedByUser'])
            ->findOrFail($id);

        return response()->json([
            'encounter' => $encounter,
            'disclaimer' => $encounter->disclaimer,
        ]);
    }

    /**
     * Get Satellite Detections & Dark Vessel Candidates GeoJSON
     */
    public function darkVessels(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $onlyCandidates = $request->boolean('candidates_only', false);

        $filters = [
            'search' => $request->query('q'),
            'review_status' => $request->query('review_status'),
            'correlation_status' => $request->query('correlation_status'),
            'min_tcr' => $request->query('min_tcr'),
            'max_tcr' => $request->query('max_tcr'),
            'platform' => $request->query('platform'),
            'date_range' => $request->query('date_range'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
        ];

        $data = $this->spatial->getSatelliteDetectionsFeatureCollection($bbox, $onlyCandidates, $filters);

        return response()->json($data);
    }

    /**
     * Get Sentinel-1 SAR Scenes GeoJSON
     */
    public function sarScenes(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $data = $this->spatial->getSatelliteScenesFeatureCollection($bbox);

        return response()->json($data);
    }

    /**
     * Parse comma separated bbox string (minLng,minLat,maxLng,maxLat)
     */
    protected function parseBBox(?string $raw): ?array
    {
        if (empty($raw)) {
            return null;
        }

        $parts = explode(',', $raw);
        if (count($parts) !== 4) {
            return null;
        }

        return [
            (float) trim($parts[0]),
            (float) trim($parts[1]),
            (float) trim($parts[2]),
            (float) trim($parts[3]),
        ];
    }
}
