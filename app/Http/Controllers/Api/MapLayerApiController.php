<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataProvider;
use App\Models\VesselEncounter;
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
                    'name' => 'Fishing Ground FAO',
                    'type' => 'fill',
                    'color' => '#818cf8', // indigo-400
                    'provider_status' => 'connected',
                    'version' => config('maritime.fao.version'),
                    'license' => config('maritime.fao.license'),
                    'source_url' => config('maritime.fao.source_url'),
                    'description' => 'FAO Major Fishing Areas designated for statistical and assessment purposes.',
                    'disclaimer' => config('maritime.fao.disclaimer'),
                ],
                [
                    'id' => 'layer-eez-zones',
                    'name' => 'Fishing Ground EEZ',
                    'type' => 'line',
                    'color' => '#2dd4bf', // teal-400
                    'provider_status' => 'connected',
                    'version' => config('maritime.eez.version'),
                    'license' => config('maritime.eez.license'),
                    'source_url' => config('maritime.eez.source_url'),
                    'attribution' => config('maritime.eez.attribution'),
                    'description' => 'Maritime boundaries and 200NM Exclusive Economic Zones (Marine Regions).',
                    'disclaimer' => config('maritime.eez.disclaimer'),
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
     * Get Live AIS Vessels Positions GeoJSON
     */
    public function vessels(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');

        $data = $this->spatial->getLiveVesselsFeatureCollection($bbox, $search);

        return response()->json($data);
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

        $data = $this->spatial->getSatelliteDetectionsFeatureCollection($bbox, $onlyCandidates);

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
