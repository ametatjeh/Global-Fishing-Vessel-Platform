<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataProvider;
use App\Models\SatelliteDetection;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselPosition;
use App\Services\Maritime\FlagStateService;
use App\Services\SpatialQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                    'description' => 'FAO global statistical fishery areas for catch reporting (not maritime jurisdiction boundaries).',
                    'disclaimer' => config('maritime.fao.disclaimer', 'FAO statistical areas do not express any opinion concerning the legal status of any country or maritime boundary.'),
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
                    'id' => 'layer-asean-eez',
                    'name' => 'ASEAN Exclusive Economic Zones (EEZ)',
                    'type' => 'mixed',
                    'color' => '#10b981', // emerald-500
                    'provider_status' => 'connected',
                    'version' => 'v12 (October 2023)',
                    'license' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
                    'source_url' => 'https://www.marineregions.org/downloads.php',
                    'attribution' => 'Flanders Marine Institute (VLIZ) Marine Regions (DOI: 10.14284/632)',
                    'description' => 'Zona Ekonomi Eksklusif dan garis batas maritim negara-negara anggota ASEAN serta Timor-Leste dengan klasifikasi status delimitasi 4-tingkat.',
                    'disclaimer' => 'Batas yurisdiksi hak ekonomi maritim regional ASEAN berdasarkan Marine Regions v12. Bukan penetapan batas hukum final.',
                ],
                [
                    'id' => 'layer-australia-eez',
                    'name' => 'Australia Exclusive Economic Zone (EEZ)',
                    'type' => 'mixed',
                    'color' => '#0ea5e9', // sky-500 / azure
                    'provider_status' => 'connected',
                    'version' => 'v12 (aligned with Australian Maritime Boundaries / AMSIS Geoscience Australia)',
                    'license' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
                    'source_url' => 'https://www.marineregions.org/downloads.php',
                    'attribution' => 'Flanders Marine Institute (VLIZ) Marine Regions (DOI: 10.14284/632) & Geoscience Australia (AMB)',
                    'description' => 'Zona Ekonomi Eksklusif dan garis batas maritim resmi Australia (200 NM outer limits dan perjanjian traktat maritim bilateral).',
                    'disclaimer' => 'Batas yurisdiksi hak ekonomi maritim Australia berdasarkan UNCLOS 1982 dan traktat bilateral resmi.',
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

        if ($request->query('region') === 'asean') {
            $data = $this->spatial->getAseanEezFeatureCollection($bbox, $search, 'polygons');
            return response()->json($data);
        }

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

        if ($request->query('region') === 'asean') {
            $data = $this->spatial->getAseanEezFeatureCollection($bbox, $search, 'boundaries');
            return response()->json($data);
        }

        $data = $this->spatial->getEezBoundariesFeatureCollection($bbox, $search);

        return response()->json($data);
    }

    /**
     * Get ASEAN Exclusive Economic Zones (EEZ) & Maritime Boundaries GeoJSON
     */
    public function aseanEez(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');
        $type = (string) $request->query('type', 'all');

        $data = $this->spatial->getAseanEezFeatureCollection($bbox, $search, $type);

        return response()->json($data);
    }

    /**
     * Get Australia Exclusive Economic Zone (EEZ) & Maritime Boundaries GeoJSON
     */
    public function australiaEez(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        $search = $request->query('q');
        $type = (string) $request->query('type', 'all');

        $data = $this->spatial->getAustraliaEezFeatureCollection($bbox, $search, $type);

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
     * Get Live AIS Vessels Positions GeoJSON (Dynamic Viewport & Global Modes)
     */
    public function vessels(Request $request): JsonResponse
    {
        $bbox = $this->parseBBox($request->query('bbox'));
        if (!$bbox && $request->has(['west', 'south', 'east', 'north'])) {
            $bbox = [
                (float) $request->query('west'),
                (float) $request->query('south'),
                (float) $request->query('east'),
                (float) $request->query('north'),
            ];
        }

        $search = $request->query('q');

        $rawTypes = $request->query('vessel_types')
            ?? $request->query('types')
            ?? $request->query('vessel_type');

        $filters = [
            'flag' => $request->query('flag'),
            'vessel_types' => $rawTypes ?: 'fishing',
            'vessel_type' => $rawTypes ?: 'fishing',
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

        $zoom = $request->has('zoom') ? (float) $request->query('zoom') : null;
        $mode = $request->query('mode');
        if (!$mode) {
            if ($zoom !== null) {
                $mode = $zoom <= 3.0 ? 'global' : 'viewport';
            } elseif ($bbox) {
                $mode = 'viewport';
            } else {
                $mode = 'global';
            }
        }
        $mode = strtolower(trim((string) $mode));
        if (!in_array($mode, ['global', 'viewport'], true)) {
            $mode = $bbox ? 'viewport' : 'global';
        }

        $page = max(1, (int) $request->query('page', 1));
        $totalInViewport = $request->has('total_in_viewport') ? (int) $request->query('total_in_viewport') : null;

        if ($mode === 'global') {
            // Global mode: clamped to max 2,000 unique vessels
            $limit = min(max((int) $request->query('limit', 2000), 1), 2000);
        } else {
            // Viewport mode: batch size default 1,000 (up to 5,000 per request)
            $limit = min(max((int) $request->query('limit', 1000), 1), 5000);
        }

        $data = $this->spatial->getLiveVesselsFeatureCollection(
            $bbox,
            $search,
            $limit,
            $filters,
            $mode,
            $page,
            $totalInViewport,
            $zoom
        );

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
     * Get historical AIS track GeoJSON for a vessel (Unlimited Historical Track).
     */
    public function vesselTrack(Request $request, string $id): JsonResponse
    {
        $vessel = \Illuminate\Support\Str::isUuid($id)
            ? Vessel::where('id', $id)->first()
            : Vessel::where('mmsi', $id)->first();

        if (!$vessel) {
            return response()->json([
                'status' => 'error',
                'message' => "Vessel with identifier '{$id}' not found.",
            ], 404);
        }

        $limit = min(max((int) $request->query('limit', 1000), 1), 5000);
        $range = $request->query('range', $request->query('window', '7d'));

        $options = [
            'cursor' => $request->query('cursor'),
            'start_date' => $request->query('start_date'),
            'start_time' => $request->query('start_time'),
            'end_date' => $request->query('end_date'),
            'end_time' => $request->query('end_time'),
            'fresh' => $request->boolean('fresh', false),
        ];

        $geoJson = $this->spatial->getVesselTrackFeatureCollection($vessel, $limit, $range, $options);

        return response()->json($geoJson, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, max-age=30',
        ]);
    }

    /**
     * Export Historical AIS track in CSV or GeoJSON format (streaming).
     */
    public function exportTrack(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $vessel = \Illuminate\Support\Str::isUuid($id)
            ? Vessel::where('id', $id)->first()
            : Vessel::where('mmsi', $id)->first();

        if (!$vessel) {
            return response()->json([
                'status' => 'error',
                'message' => "Vessel with identifier '{$id}' not found.",
            ], 404);
        }

        $format = strtolower($request->query('format', 'csv'));
        $range = $request->query('range', '7d');

        $options = [
            'start_date' => $request->query('start_date'),
            'start_time' => $request->query('start_time'),
            'end_date' => $request->query('end_date'),
            'end_time' => $request->query('end_time'),
        ];

        if ($format === 'geojson') {
            return $this->exportTrackGeoJson($vessel, $range, $options);
        }

        return $this->exportTrackCsv($vessel, $range, $options);
    }

    /**
     * Stream CSV export of raw historical AIS positions.
     */
    protected function exportTrackCsv(Vessel $vessel, string $range, array $options): StreamedResponse
    {
        $query = $this->buildTrackQuery($vessel, $range, $options);
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $vessel->name ?: 'vessel');
        $filename = "historical_track_{$cleanName}_{$vessel->mmsi}_{$range}_" . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->streamDownload(function () use ($query, $vessel) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

            fputcsv($handle, [
                'Position ID',
                'Vessel ID',
                'MMSI',
                'Vessel Name',
                'Vessel Type',
                'Observed At (UTC)',
                'Latitude',
                'Longitude',
                'Speed (Knots)',
                'Course (Degrees)',
                'Heading (Degrees)',
                'Navigation Status',
                'Data Quality Score',
                'Is Gap Indicator',
            ]);

            $query->chunk(1000, function ($positions) use ($handle, $vessel) {
                foreach ($positions as $pos) {
                    fputcsv($handle, [
                        $pos->id,
                        $vessel->id,
                        $vessel->mmsi,
                        $vessel->name,
                        $vessel->vessel_type,
                        $pos->observed_at ? $pos->observed_at->toIso8601String() : '',
                        (float) $pos->latitude,
                        (float) $pos->longitude,
                        $pos->speed_knots !== null ? (float) $pos->speed_knots : '',
                        $pos->course_degrees !== null ? (float) $pos->course_degrees : '',
                        $pos->heading_degrees !== null ? (float) $pos->heading_degrees : '',
                        $pos->nav_status ?? '',
                        $pos->data_quality_score ?? '',
                        $pos->is_gap_indicator ? 'true' : 'false',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, $headers);
    }

    /**
     * Stream GeoJSON export of historical AIS track.
     */
    protected function exportTrackGeoJson(Vessel $vessel, string $range, array $options): StreamedResponse
    {
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $vessel->name ?: 'vessel');
        $filename = "historical_track_{$cleanName}_{$vessel->mmsi}_{$range}_" . now()->format('Ymd_His') . '.geojson';

        $headers = [
            'Content-Type' => 'application/geo+json; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        // Retrieve full track collection (up to 5000 points)
        $geoJson = $this->spatial->getVesselTrackFeatureCollection(
            $vessel,
            5000,
            $range,
            array_merge($options, ['fresh' => true])
        );

        return response()->streamDownload(function () use ($geoJson) {
            echo json_encode($geoJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, $headers);
    }

    /**
     * Build optimized track query using index.
     */
    protected function buildTrackQuery(Vessel $vessel, string $range, array $options)
    {
        $rangeStr = strtolower(trim($range));
        $startDateTime = null;
        $endDateTime = now();

        if ($rangeStr === 'custom') {
            $startDateStr = $options['start_date'] ?? null;
            $startTimeStr = $options['start_time'] ?? '00:00:00';
            $endDateStr = $options['end_date'] ?? null;
            $endTimeStr = $options['end_time'] ?? '23:59:59';

            if ($startDateStr) {
                try {
                    $startDateTime = \Carbon\Carbon::parse("{$startDateStr} {$startTimeStr}");
                } catch (\Throwable $e) {}
            }
            if ($endDateStr) {
                try {
                    $parsedEnd = \Carbon\Carbon::parse("{$endDateStr} {$endTimeStr}");
                    $endDateTime = $parsedEnd->gt(now()) ? now() : $parsedEnd;
                } catch (\Throwable $e) {}
            }
            if ($startDateTime && $endDateTime && $endDateTime->lt($startDateTime)) {
                $temp = $startDateTime;
                $startDateTime = $endDateTime;
                $endDateTime = $temp;
            }
        } elseif ($rangeStr === '24h' || $rangeStr === '24 hours') {
            $startDateTime = now()->subHours(24);
        } elseif ($rangeStr === '48h' || $rangeStr === '48 hours') {
            $startDateTime = now()->subHours(48);
        } elseif ($rangeStr === '72h' || $rangeStr === '72 hours') {
            $startDateTime = now()->subHours(72);
        } elseif ($rangeStr === '7d' || $rangeStr === '7 days') {
            $startDateTime = now()->subDays(7);
        } elseif ($rangeStr === '14d' || $rangeStr === '14 days') {
            $startDateTime = now()->subDays(14);
        } elseif ($rangeStr === '30d' || $rangeStr === '30 days') {
            $startDateTime = now()->subDays(30);
        } elseif ($rangeStr === '90d' || $rangeStr === '90 days') {
            $startDateTime = now()->subDays(90);
        } elseif ($rangeStr === '180d' || $rangeStr === '180 days') {
            $startDateTime = now()->subDays(180);
        } elseif ($rangeStr === '1y' || $rangeStr === '365d' || $rangeStr === '1 year') {
            $startDateTime = now()->subDays(365);
        } elseif ($rangeStr === 'all' || $rangeStr === 'all_available') {
            $startDateTime = null;
            $endDateTime = null;
        } else {
            $startDateTime = now()->subDays(7);
        }

        $query = VesselPosition::select([
            'id', 'vessel_id', 'latitude', 'longitude', 'speed_knots',
            'course_degrees', 'heading_degrees', 'nav_status',
            'observed_at', 'data_quality_score', 'is_gap_indicator',
        ])
            ->where('vessel_id', $vessel->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [-90.0, 90.0])
            ->whereBetween('longitude', [-180.0, 180.0])
            ->whereRaw('NOT (latitude = 0.0 AND longitude = 0.0)');

        if ($startDateTime) {
            $query->where('observed_at', '>=', $startDateTime);
        }
        if ($endDateTime) {
            $query->where('observed_at', '<=', $endDateTime);
        }

        return $query->orderBy('observed_at', 'asc');
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
