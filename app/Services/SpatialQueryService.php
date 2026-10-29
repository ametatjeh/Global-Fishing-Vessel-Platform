<?php

namespace App\Services;

use App\Models\FaoFishingArea;
use App\Models\FisheriesManagementArea;
use App\Models\EezZone;
use App\Models\EezBoundary;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Models\VesselEncounter;
use App\Models\SatelliteDetection;
use App\Models\MaritimeZone;
use Illuminate\Support\Facades\DB;

class SpatialQueryService
{
    /**
     * Get FAO Major Fishing Areas as GeoJSON FeatureCollection
     */
    public function getFaoFeatureCollection(?array $bbox = null, ?string $search = null): array
    {
        $query = FaoFishingArea::query();

        if ($search) {
            $query->search($search);
        }

        $normalizedBBox = $this->normalizeBBox($bbox);
        if ($normalizedBBox) {
            $query->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
        }

        $areas = $query->limit(50)->get();

        $features = [];
        foreach ($areas as $area) {
            $geom = json_decode($area->geometry_geojson, true);
            if (!$geom) continue;

            $features[] = [
                'type' => 'Feature',
                'id' => $area->id,
                'geometry' => $geom,
                'properties' => [
                    'id' => $area->id,
                    'area_code' => $area->area_code,
                    'name_en' => $area->name_en,
                    'ocean_basin' => $area->ocean_basin,
                    'area_type' => $area->area_type,
                    'surface_water_sqkm' => $area->surface_water_sqkm,
                    'version' => $area->version,
                    'source' => $area->source_dataset_url,
                    'license' => $area->license_name,
                    'disclaimer' => config('maritime.fao.disclaimer'),
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
                'dataset' => 'FAO Major Fishing Areas for Statistical Purposes',
                'license' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
            ]
        ];
    }

    /**
     * Get Marine Regions EEZ Boundaries as GeoJSON FeatureCollection
     */
    public function getEezFeatureCollection(?array $bbox = null, ?string $search = null): array
    {
        $query = EezZone::query();

        if ($search) {
            $query->search($search);
        }

        $normalizedBBox = $this->normalizeBBox($bbox);
        if ($normalizedBBox) {
            $query->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
        }

        $eezs = $query->limit(350)->get();

        $features = [];
        foreach ($eezs as $eez) {
            $geom = json_decode($eez->geometry_geojson, true);
            if (!$geom) continue;

            $features[] = [
                'type' => 'Feature',
                'id' => $eez->id,
                'geometry' => $geom,
                'properties' => [
                    'id' => $eez->id,
                    'mrgid' => $eez->mrgid,
                    'geoname' => $eez->geoname,
                    'sovereign_state' => $eez->sovereign_state,
                    'sovereign_iso3' => $eez->sovereign_iso3,
                    'territory1' => $eez->territory1,
                    'iso_ter1' => $eez->iso_ter1,
                    'sovereign2' => $eez->sovereign2,
                    'sovereign3' => $eez->sovereign3,
                    'pol_type' => $eez->pol_type,
                    'area_sqkm' => $eez->area_sqkm,
                    'x_1' => $eez->x_1,
                    'y_1' => $eez->y_1,
                    'version' => $eez->version,
                    'source' => $eez->source_name,
                    'license' => $eez->license_name,
                    'attribution' => $eez->attribution_notice,
                    'crosses_antimeridian' => $eez->crosses_antimeridian,
                    'disclaimer' => config('maritime.eez.disclaimer', 'Batas yurisdiksi hak ekonomi maritim berdasarkan UNCLOS / traktat bilateral. Bukan penetapan batas hukum final untuk klaim tumpang tindih.'),
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
                'dataset' => 'Maritime Boundaries Geodatabase: Exclusive Economic Zone (200NM) v12 (October 2023)',
                'doi' => '10.14284/632',
                'publisher' => 'Flanders Marine Institute (VLIZ) Marine Regions',
                'license' => 'CC BY 4.0 International',
            ]
        ];
    }

    /**
     * Get Official Marine Regions EEZ Boundary Lines as GeoJSON FeatureCollection
     */
    public function getEezBoundariesFeatureCollection(?array $bbox = null, ?string $search = null): array
    {
        $query = EezBoundary::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('line_name', 'like', "%{$search}%")
                  ->orWhere('sovereign1', 'like', "%{$search}%")
                  ->orWhere('sovereign2', 'like', "%{$search}%")
                  ->orWhere('line_type', 'like', "%{$search}%");
            });
        }

        $normalizedBBox = $this->normalizeBBox($bbox);
        if ($normalizedBBox) {
            $query->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
        }

        $lines = $query->limit(2500)->get();

        $features = [];
        foreach ($lines as $line) {
            $geom = is_array($line->geometry_geojson) 
                ? $line->geometry_geojson 
                : json_decode($line->geometry_geojson, true);
            if (!$geom) continue;

            $features[] = [
                'type' => 'Feature',
                'id' => $line->id,
                'geometry' => $geom,
                'properties' => [
                    'id' => $line->id,
                    'line_id' => $line->line_id,
                    'line_name' => $line->line_name,
                    'line_type' => $line->line_type,
                    'sovereign1' => $line->sovereign1,
                    'sovereign2' => $line->sovereign2,
                    'territory1' => $line->territory1,
                    'territory2' => $line->territory2,
                    'eez1' => $line->eez1,
                    'eez2' => $line->eez2,
                    'source1' => $line->source1,
                    'url1' => $line->url1,
                    'doc_date' => $line->doc_date,
                    'length_km' => $line->length_km,
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
                'dataset' => 'Maritime Boundaries Geodatabase: Maritime Boundaries v12 (eez_boundaries_v12)',
                'doi' => '10.14284/632',
                'publisher' => 'Flanders Marine Institute (VLIZ) Marine Regions',
                'license' => 'CC BY 4.0 International',
            ]
        ];
    }

    /**
     * Get FAO Regional Fishery Bodies (RFBs / RFMOs) Competence Areas as GeoJSON FeatureCollection
     */
    public function getFisheriesManagementAreasFeatureCollection(?array $bbox = null, ?string $search = null): array
    {
        $query = FisheriesManagementArea::query();

        if ($search) {
            $query->search($search);
        }

        $normalizedBBox = $this->normalizeBBox($bbox);
        if ($normalizedBBox) {
            $query->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
        }

        $fmas = $query->limit(150)->get();

        $features = [];
        foreach ($fmas as $fma) {
            $geom = json_decode($fma->geometry_geojson, true);
            if (!$geom) continue;

            $features[] = [
                'type' => 'Feature',
                'id' => $fma->id,
                'geometry' => $geom,
                'properties' => [
                    'id' => $fma->id,
                    'rfb_code' => $fma->rfb_code,
                    'name' => $fma->name,
                    'mandate_type' => $fma->mandate_type,
                    'ocean_basin' => $fma->ocean_basin,
                    'competence_summary' => $fma->competence_summary,
                    'established_year' => $fma->established_year,
                    'legal_instrument' => $fma->legal_instrument,
                    'version' => $fma->version,
                    'source' => $fma->source_dataset_url,
                    'license' => $fma->license_name,
                    'crosses_antimeridian' => $fma->crosses_antimeridian,
                    'disclaimer' => $fma->disclaimer,
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
                'dataset' => 'FAO Regional Fishery Bodies (RFBs / RFMOs) Competence Areas',
                'publisher' => 'Food and Agriculture Organization of the United Nations (FAO)',
                'license' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
            ]
        ];
    }

    /**
     * Get Live AIS Vessel Positions as GeoJSON FeatureCollection
     * Optimized query using direct position indices and driver-specific distinct selection
     */
    public function getLiveVesselsFeatureCollection(?array $bbox = null, ?string $search = null, int $limit = 200, array $filters = []): array
    {
        $driver = DB::connection()->getDriverName();
        $limit = $limit > 0 ? $limit : 200;

        // Base query on vessel_positions to leverage indexes on lat/lng/observed_at
        $posQuery = VesselPosition::query();

        if ($bbox && count($bbox) === 4) {
            $minLng = (float) $bbox[0];
            $minLat = min((float) $bbox[1], (float) $bbox[3]);
            $maxLng = (float) $bbox[2];
            $maxLat = max((float) $bbox[1], (float) $bbox[3]);

            $posQuery->whereBetween('latitude', [$minLat, $maxLat]);
            if ($minLng > $maxLng) {
                $posQuery->where(function ($w) use ($minLng, $maxLng) {
                    $w->where('longitude', '>=', $minLng)
                      ->orWhere('longitude', '<=', $maxLng);
                });
            } else {
                $posQuery->whereBetween('longitude', [$minLng, $maxLng]);
            }
        }

        // Apply vessel-level filters (name, identifier, flag, type)
        $vesselFiltersActive = false;
        $vesselQuery = Vessel::query();

        if ($search) {
            $vesselFiltersActive = true;
            $vesselQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mmsi', 'like', "%{$search}%")
                  ->orWhere('imo', 'like', "%{$search}%")
                  ->orWhere('callsign', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['flag'])) {
            $vesselFiltersActive = true;
            $vesselQuery->where('flag_state', strtoupper(trim($filters['flag'])));
        }

        if (!empty($filters['vessel_type'])) {
            $vesselFiltersActive = true;
            $vesselQuery->where('vessel_type', strtolower(trim($filters['vessel_type'])));
        }

        if ($vesselFiltersActive) {
            $matchedVesselIds = $vesselQuery->pluck('id');
            $posQuery->whereIn('vessel_id', $matchedVesselIds);
        }

        // Apply position-level filters: SOG, COG, Heading status, Freshness, Date
        if (isset($filters['min_sog']) && is_numeric($filters['min_sog'])) {
            $posQuery->where('speed_knots', '>=', (float) $filters['min_sog']);
        }
        if (isset($filters['max_sog']) && is_numeric($filters['max_sog'])) {
            $posQuery->where('speed_knots', '<=', (float) $filters['max_sog']);
        }

        if (isset($filters['min_cog']) && is_numeric($filters['min_cog'])) {
            $posQuery->where('course_degrees', '>=', (float) $filters['min_cog']);
        }
        if (isset($filters['max_cog']) && is_numeric($filters['max_cog'])) {
            $posQuery->where('course_degrees', '<=', (float) $filters['max_cog']);
        }

        if (!empty($filters['heading_status'])) {
            if ($filters['heading_status'] === 'has_heading') {
                $posQuery->whereNotNull('heading_degrees')
                         ->where('heading_degrees', '>=', 0.0)
                         ->where('heading_degrees', '<', 360.0)
                         ->where('heading_degrees', '!=', 511);
            } elseif ($filters['heading_status'] === 'cog_fallback') {
                $posQuery->where(function ($q) {
                    $q->whereNull('heading_degrees')
                      ->orWhere('heading_degrees', '>=', 360.0)
                      ->orWhere('heading_degrees', 511);
                })->whereNotNull('course_degrees')
                  ->where('course_degrees', '>=', 0.0)
                  ->where('course_degrees', '<', 360.0)
                  ->where('course_degrees', '!=', 3600);
            } elseif ($filters['heading_status'] === 'unavailable') {
                $posQuery->where(function ($q) {
                    $q->whereNull('heading_degrees')
                      ->orWhere('heading_degrees', '>=', 360.0)
                      ->orWhere('heading_degrees', 511);
                })->where(function ($q) {
                    $q->whereNull('course_degrees')
                      ->orWhere('course_degrees', '>=', 360.0)
                      ->orWhere('course_degrees', 3600);
                });
            }
        }

        if (!empty($filters['freshness'])) {
            if ($filters['freshness'] === 'live') {
                $posQuery->where('observed_at', '>=', now()->subHours(2));
            } elseif ($filters['freshness'] === 'stale') {
                $posQuery->where('observed_at', '<', now()->subHours(2));
            }
        }

        if (!empty($filters['date_range'])) {
            if ($filters['date_range'] === '24h') {
                $posQuery->where('observed_at', '>=', now()->subHours(24));
            } elseif ($filters['date_range'] === '7d') {
                $posQuery->where('observed_at', '>=', now()->subDays(7));
            } elseif ($filters['date_range'] === '30d') {
                $posQuery->where('observed_at', '>=', now()->subDays(30));
            } elseif ($filters['date_range'] === 'custom') {
                if (!empty($filters['start_date'])) {
                    $posQuery->where('observed_at', '>=', $filters['start_date']);
                }
                if (!empty($filters['end_date'])) {
                    $posQuery->where('observed_at', '<=', $filters['end_date']);
                }
            }
        }

        if ($driver === 'pgsql') {
            // PostgreSQL high-speed DISTINCT ON to retrieve the latest position per vessel without correlated subqueries
            $positionIds = (clone $posQuery)
                ->select('id')
                ->distinct('vessel_id')
                ->orderByRaw('vessel_id, observed_at DESC')
                ->limit($limit)
                ->pluck('id');

            $positions = VesselPosition::with(['vessel', 'sourceProvider'])
                ->whereIn('id', $positionIds)
                ->get();
        } else {
            // SQLite / generic fallback
            $positionIds = (clone $posQuery)
                ->selectRaw('MAX(id) as id')
                ->groupBy('vessel_id')
                ->limit($limit)
                ->pluck('id');

            $positions = VesselPosition::with(['vessel', 'sourceProvider'])
                ->whereIn('id', $positionIds)
                ->get();
        }

        $features = [];
        foreach ($positions as $pos) {
            $vessel = $pos->vessel;
            if (!$vessel) continue;

            $lat = (float) $pos->latitude;
            $lng = (float) $pos->longitude;
            if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                continue;
            }

            $flagInfo = \App\Services\Maritime\FlagStateService::resolveFromCodeAndMmsi($vessel->flag_state, $vessel->mmsi);

            // Priority: 1. True Heading (0-359.9, finite, not null, excluding 511 NMEA unavailable)
            //           2. COG Fallback (0-359.9, finite, not null, excluding 3600 NMEA unavailable)
            //           3. Direction Unavailable (Neutral beacon, no claim of direction)
            $heading = null;
            if ($pos->heading_degrees !== null) {
                $hVal = (float) $pos->heading_degrees;
                if (is_finite($hVal) && $hVal >= 0.0 && $hVal < 360.0 && (int) $hVal !== 511) {
                    $heading = round(fmod($hVal, 360.0), 1);
                }
            }

            $cog = null;
            if ($pos->course_degrees !== null) {
                $cVal = (float) $pos->course_degrees;
                if (is_finite($cVal) && $cVal >= 0.0 && $cVal < 360.0 && (int) $cVal !== 3600) {
                    $cog = round(fmod($cVal, 360.0), 1);
                }
            }

            if ($heading !== null) {
                $orientationSource = 'heading';
                $orientationDegrees = $heading;
                $hasOrientation = true;
            } elseif ($cog !== null) {
                $orientationSource = 'cog';
                $orientationDegrees = $cog;
                $hasOrientation = true;
            } else {
                $orientationSource = 'unavailable';
                $orientationDegrees = 0.0;
                $hasOrientation = false;
            }

            $orientationType = $orientationSource === 'unavailable' ? 'neutral' : $orientationSource;

            $features[] = [
                'type' => 'Feature',
                'id' => $pos->id,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$lng, $lat],
                ],
                'properties' => [
                    'vessel_id' => $vessel->id,
                    'name' => $vessel->name,
                    'mmsi' => $vessel->mmsi,
                    'imo' => $vessel->imo,
                    'callsign' => $vessel->callsign,
                    'hull_code' => $vessel->hull_code,
                    'flag_state' => $flagInfo['iso2'],
                    'flag_country' => $flagInfo['country_name'],
                    'flag_emoji' => $flagInfo['flag_emoji'],
                    'flag_label' => $flagInfo['label'],
                    'flag_status' => $flagInfo['status'],
                    'flag_verified' => $flagInfo['is_verified'],
                    'vessel_type' => $vessel->vessel_type,
                    'speed_knots' => $pos->speed_knots !== null && is_finite((float) $pos->speed_knots) ? round((float) $pos->speed_knots, 1) : null,
                    'course_degrees' => $cog,
                    'heading_degrees' => $heading,
                    'orientation_degrees' => $orientationDegrees,
                    'orientation_source' => $orientationSource,
                    'orientation_type' => $orientationType,
                    'has_orientation' => $hasOrientation,
                    'nav_status' => $pos->nav_status,
                    'observed_at' => $pos->observed_at?->toIso8601String(),
                    'is_fresh' => $pos->isFresh(),
                    'data_quality_score' => (float) $pos->data_quality_score,
                    'provider' => $pos->sourceProvider?->name ?? 'Native AIS',
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
                'generated_at' => now()->toIso8601String(),
            ]
        ];
    }

    /**
     * Get Vessel Encounters as GeoJSON FeatureCollection
     */
    public function getEncountersFeatureCollection(?array $bbox = null, ?string $startDate = null, ?string $endDate = null, ?string $status = null): array
    {
        $query = VesselEncounter::with(['vessel1', 'vessel2', 'sourceProvider']);

        if ($bbox && count($bbox) === 4) {
            $query->inBoundingBox($bbox[0], $bbox[1], $bbox[2], $bbox[3]);
        }

        if ($startDate || $endDate) {
            $query->dateRange($startDate, $endDate);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $encounters = $query->limit(150)->get();

        $features = [];
        foreach ($encounters as $enc) {
            $features[] = [
                'type' => 'Feature',
                'id' => $enc->id,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$enc->longitude, $enc->latitude],
                ],
                'properties' => [
                    'id' => $enc->id,
                    'code' => $enc->encounter_code,
                    'category' => $enc->category,
                    'status' => $enc->status,
                    'start_time' => $enc->start_time->toIso8601String(),
                    'end_time' => $enc->end_time->toIso8601String(),
                    'duration_minutes' => $enc->duration_minutes,
                    'min_distance_meters' => $enc->min_distance_meters,
                    'median_distance_meters' => $enc->median_distance_meters,
                    'detection_method' => $enc->detection_method,
                    'vessel_1' => [
                        'id' => $enc->vessel1?->id,
                        'name' => $enc->vessel1?->name,
                        'flag' => $enc->vessel1?->flag_state,
                        'type' => $enc->vessel1?->vessel_type,
                        'mmsi' => $enc->vessel1?->mmsi,
                    ],
                    'vessel_2' => [
                        'id' => $enc->vessel2?->id,
                        'name' => $enc->vessel2?->name,
                        'flag' => $enc->vessel2?->flag_state,
                        'type' => $enc->vessel2?->vessel_type,
                        'mmsi' => $enc->vessel2?->mmsi,
                    ],
                    'disclaimer' => $enc->disclaimer,
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
            ]
        ];
    }

    /**
     * Get Satellite Detections & Dark Vessel Candidates as GeoJSON FeatureCollection
     */
    public function getSatelliteDetectionsFeatureCollection(?array $bbox = null, bool $onlyDarkCandidates = false, array $filters = []): array
    {
        $query = SatelliteDetection::with(['scene', 'correlatedVessel']);

        if ($onlyDarkCandidates) {
            $query->darkVesselCandidates();
        }

        if ($bbox && count($bbox) === 4) {
            $query->inBoundingBox($bbox[0], $bbox[1], $bbox[2], $bbox[3]);
        }

        if (!empty($filters['search'])) {
            $s = trim($filters['search']);
            $query->where(function ($q) use ($s) {
                $q->where('detection_identifier', 'like', "%{$s}%")
                  ->orWhere('analyst_notes', 'like', "%{$s}%");
            });
        }

        if (!empty($filters['review_status'])) {
            $query->where('review_status', trim($filters['review_status']));
        }

        if (!empty($filters['correlation_status'])) {
            $query->where('correlation_status', trim($filters['correlation_status']));
        }

        if (isset($filters['min_tcr']) && is_numeric($filters['min_tcr'])) {
            $query->where('tcr_db', '>=', (float) $filters['min_tcr']);
        }

        if (isset($filters['max_tcr']) && is_numeric($filters['max_tcr'])) {
            $query->where('tcr_db', '<=', (float) $filters['max_tcr']);
        }

        if (!empty($filters['platform'])) {
            $p = trim($filters['platform']);
            $query->whereHas('scene', function ($q) use ($p) {
                $q->where('platform_name', $p);
            });
        }

        if (!empty($filters['date_range'])) {
            if ($filters['date_range'] === '24h') {
                $query->where('detected_at', '>=', now()->subHours(24));
            } elseif ($filters['date_range'] === '7d') {
                $query->where('detected_at', '>=', now()->subDays(7));
            } elseif ($filters['date_range'] === '30d') {
                $query->where('detected_at', '>=', now()->subDays(30));
            } elseif ($filters['date_range'] === 'custom') {
                if (!empty($filters['start_date'])) {
                    $query->where('detected_at', '>=', $filters['start_date']);
                }
                if (!empty($filters['end_date'])) {
                    $query->where('detected_at', '<=', $filters['end_date']);
                }
            }
        }

        $detections = $query->limit(100)->get();

        $features = [];
        foreach ($detections as $det) {
            $features[] = [
                'type' => 'Feature',
                'id' => $det->id,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$det->longitude, $det->latitude],
                ],
                'properties' => [
                    'id' => $det->id,
                    'identifier' => $det->detection_identifier,
                    'detected_at' => $det->detected_at->toIso8601String(),
                    'estimated_length_meters' => $det->estimated_length_meters,
                    'estimated_width_meters' => $det->estimated_width_meters,
                    'estimated_heading' => $det->estimated_heading,
                    'confidence_score' => $det->confidence_score,
                    'correlation_status' => $det->correlation_status,
                    'correlation_method' => $det->correlation_method,
                    'correlated_vessel_name' => $det->correlatedVessel?->name,
                    'ais_gap_duration_minutes' => $det->ais_gap_duration_minutes,
                    'analyst_notes' => $det->analyst_notes,
                    'latitude' => $det->latitude,
                    'longitude' => $det->longitude,
                    'tcr_db' => $det->tcr_db,
                    'review_status' => $det->review_status,
                    'review_status_label' => SatelliteDetection::getReviewStatuses()[$det->review_status] ?? $det->review_status,
                    'scene_id' => $det->scene?->scene_id,
                    'platform' => $det->scene?->platform_name ?? 'Sentinel-1 SAR',
                    'disclaimer' => $det->disclaimer,
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
            ]
        ];
    }

    /**
     * Get Sentinel-1 SAR Scenes footprints as GeoJSON FeatureCollection
     */
    public function getSatelliteScenesFeatureCollection(?array $bbox = null): array
    {
        $scenes = \App\Models\SatelliteScene::all();
        $features = [];

        foreach ($scenes as $scene) {
            $geom = json_decode($scene->footprint_geojson, true);
            if (!$geom) continue;

            $features[] = [
                'type' => 'Feature',
                'id' => $scene->id,
                'geometry' => $geom,
                'properties' => [
                    'id' => $scene->id,
                    'scene_id' => $scene->scene_id,
                    'platform_name' => $scene->platform_name,
                    'instrument' => $scene->instrument,
                    'sensor_mode' => $scene->sensor_mode,
                    'polarization' => $scene->polarization,
                    'orbit_direction' => $scene->orbit_direction,
                    'acquisition_start' => $scene->acquisition_start?->toIso8601String(),
                    'acquisition_end' => $scene->acquisition_end?->toIso8601String(),
                    'processing_status' => $scene->processing_status,
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
                'dataset' => 'Sentinel-1 C-SAR Scenes',
            ],
        ];
    }

    /**
     * Normalize and sanitize bounding box coordinates
     * Clamps latitudes to [-90, 90] and ignores BBox if zoomed out to full world
     *
     * @param array|null $bbox [minLng, minLat, maxLng, maxLat]
     * @return array|null
     */
    protected function normalizeBBox(?array $bbox): ?array
    {
        if (!$bbox || count($bbox) !== 4) {
            return null;
        }

        $minLng = (float) $bbox[0];
        $minLat = (float) $bbox[1];
        $maxLng = (float) $bbox[2];
        $maxLat = (float) $bbox[3];

        // If viewport spans full world (>= 360 degrees) or covers global bounds, skip bbox filter
        if (abs($maxLng - $minLng) >= 360 || ($minLng <= -179 && $maxLng >= 179)) {
            return null;
        }

        // Clamp latitude to valid [-90, 90]
        $minLat = max(-90.0, min(90.0, $minLat));
        $maxLat = max(-90.0, min(90.0, $maxLat));

        // Clamp longitude boundaries if within single globe view
        if ($minLng < -180 && $maxLng > 180) {
            return null;
        }

        return [$minLng, $minLat, $maxLng, $maxLat];
    }
}
