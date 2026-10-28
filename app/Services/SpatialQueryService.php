<?php

namespace App\Services;

use App\Models\FaoFishingArea;
use App\Models\EezZone;
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

        if ($bbox && count($bbox) === 4) {
            $query->inBoundingBox($bbox[0], $bbox[1], $bbox[2], $bbox[3]);
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

        if ($bbox && count($bbox) === 4) {
            $query->inBoundingBox($bbox[0], $bbox[1], $bbox[2], $bbox[3]);
        }

        $eezs = $query->limit(60)->get();

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
                    'pol_type' => $eez->pol_type,
                    'area_sqkm' => $eez->area_sqkm,
                    'version' => $eez->version,
                    'source' => $eez->source_name,
                    'license' => $eez->license_name,
                    'attribution' => $eez->attribution_notice,
                    'crosses_antimeridian' => $eez->crosses_antimeridian,
                    'disclaimer' => config('maritime.eez.disclaimer'),
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'count' => count($features),
                'dataset' => 'Maritime Boundaries Geodatabase: Exclusive Economic Zone (200NM) v12',
                'license' => 'CC BY 4.0 International',
            ]
        ];
    }

    /**
     * Get Live AIS Vessel Positions as GeoJSON FeatureCollection
     */
    public function getLiveVesselsFeatureCollection(?array $bbox = null, ?string $search = null): array
    {
        // Query vessels that have an active latest position
        $query = Vessel::with(['latestPosition.sourceProvider'])
            ->has('latestPosition');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mmsi', 'like', "%{$search}%")
                  ->orWhere('imo', 'like', "%{$search}%")
                  ->orWhere('callsign', 'like', "%{$search}%");
            });
        }

        if ($bbox && count($bbox) === 4) {
            $minLng = (float) $bbox[0];
            $minLat = min((float) $bbox[1], (float) $bbox[3]);
            $maxLng = (float) $bbox[2];
            $maxLat = max((float) $bbox[1], (float) $bbox[3]);

            $query->whereHas('latestPosition', function ($q) use ($minLng, $minLat, $maxLng, $maxLat) {
                $q->whereBetween('latitude', [$minLat, $maxLat]);
                if ($minLng > $maxLng) {
                    $q->where(function ($w) use ($minLng, $maxLng) {
                        $w->where('longitude', '>=', $minLng)
                          ->orWhere('longitude', '<=', $maxLng);
                    });
                } else {
                    $q->whereBetween('longitude', [$minLng, $maxLng]);
                }
            });
        }

        $vessels = $query->limit(200)->get();

        $features = [];
        foreach ($vessels as $vessel) {
            $pos = $vessel->latestPosition;
            if (!$pos) continue;

            $flagInfo = \App\Services\Maritime\FlagStateService::resolveFromCodeAndMmsi($vessel->flag_state, $vessel->mmsi);

            $features[] = [
                'type' => 'Feature',
                'id' => $pos->id,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$pos->longitude, $pos->latitude],
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
                    'speed_knots' => $pos->speed_knots,
                    'course_degrees' => $pos->course_degrees,
                    'heading_degrees' => $pos->heading_degrees,
                    'nav_status' => $pos->nav_status,
                    'observed_at' => $pos->observed_at?->toIso8601String(),
                    'is_fresh' => $pos->isFresh(),
                    'data_quality_score' => $pos->data_quality_score,
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
    public function getSatelliteDetectionsFeatureCollection(?array $bbox = null, bool $onlyDarkCandidates = false): array
    {
        $query = SatelliteDetection::with(['scene', 'correlatedVessel']);

        if ($onlyDarkCandidates) {
            $query->darkVesselCandidates();
        }

        if ($bbox && count($bbox) === 4) {
            $query->inBoundingBox($bbox[0], $bbox[1], $bbox[2], $bbox[3]);
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
}
