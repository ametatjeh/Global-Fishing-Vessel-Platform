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
use App\Services\Maritime\FlagStateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * ASEAN Member States + Candidate ISO-3 codes
     */
    public const ASEAN_ISO3 = ['BRN', 'KHM', 'IDN', 'LAO', 'MYS', 'MMR', 'PHL', 'SGP', 'THA', 'VNM', 'TLS'];

    /**
     * ASEAN Member States + Candidate Sovereignty Names
     */
    public const ASEAN_NAMES = [
        'Brunei', 'Cambodia', 'Indonesia', 'Malaysia', 'Myanmar',
        'Philippines', 'Singapore', 'Thailand', 'Vietnam', 'Timor-Leste', 'East Timor'
    ];

    /**
     * Official color mapping per country:
     * - Indonesia: Cyan (#06b6d4)
     * - Malaysia: Orange (#f97316)
     * - Thailand: Yellow (#eab308)
     * - Vietnam: Magenta (#d946ef)
     * - Filipina: Green (#22c55e)
     * - Brunei Darussalam: Violet (#8b5cf6)
     * - Singapura: Coral (#fb7185)
     * - Kamboja: Blue (#3b82f6)
     * - Myanmar: Pink (#ec4899)
     * - Laos: Teal (#14b8a6)
     * - Timor-Leste: Violet/Indigo (#a855f7)
     * - Australia: Red-Orange (#ef4444)
     */
    public const COUNTRY_COLORS = [
        'IDN' => '#06b6d4',
        'MYS' => '#f97316',
        'THA' => '#eab308',
        'VNM' => '#d946ef',
        'PHL' => '#22c55e',
        'BRN' => '#8b5cf6',
        'SGP' => '#fb7185',
        'KHM' => '#3b82f6',
        'MMR' => '#ec4899',
        'LAO' => '#14b8a6',
        'TLS' => '#a855f7',
        'AUS' => '#ef4444',
    ];

    /**
     * Resolve primary ASEAN sovereign country, ISO3 code, and visual palette
     */
    public static function resolveAseanCountry(?string $sovereign1, ?string $sovereign2 = null, ?string $territory = null, ?string $iso3 = null): array
    {
        $aseanIsoMap = [
            'IDN' => ['code' => 'IDN', 'name' => 'Indonesia', 'color' => '#06b6d4'],
            'MYS' => ['code' => 'MYS', 'name' => 'Malaysia', 'color' => '#f97316'],
            'THA' => ['code' => 'THA', 'name' => 'Thailand', 'color' => '#eab308'],
            'VNM' => ['code' => 'VNM', 'name' => 'Vietnam', 'color' => '#d946ef'],
            'PHL' => ['code' => 'PHL', 'name' => 'Philippines', 'color' => '#22c55e'],
            'BRN' => ['code' => 'BRN', 'name' => 'Brunei Darussalam', 'color' => '#8b5cf6'],
            'SGP' => ['code' => 'SGP', 'name' => 'Singapore', 'color' => '#fb7185'],
            'KHM' => ['code' => 'KHM', 'name' => 'Cambodia', 'color' => '#3b82f6'],
            'MMR' => ['code' => 'MMR', 'name' => 'Myanmar', 'color' => '#ec4899'],
            'LAO' => ['code' => 'LAO', 'name' => 'Laos', 'color' => '#14b8a6'],
            'TLS' => ['code' => 'TLS', 'name' => 'Timor-Leste', 'color' => '#a855f7'],
        ];

        if ($iso3 && isset($aseanIsoMap[$iso3])) {
            return $aseanIsoMap[$iso3];
        }

        $names = [
            'indonesia' => 'IDN',
            'malaysia' => 'MYS',
            'thailand' => 'THA',
            'vietnam' => 'VNM',
            'philippines' => 'PHL',
            'brunei' => 'BRN',
            'singapore' => 'SGP',
            'cambodia' => 'KHM',
            'myanmar' => 'MMR',
            'burma' => 'MMR',
            'laos' => 'LAO',
            'timor' => 'TLS',
        ];

        // 1. Check sovereign1
        $s1 = strtolower((string)$sovereign1);
        foreach ($names as $key => $code) {
            if (str_contains($s1, $key)) {
                return $aseanIsoMap[$code];
            }
        }

        // 2. Check sovereign2
        $s2 = strtolower((string)$sovereign2);
        foreach ($names as $key => $code) {
            if (str_contains($s2, $key)) {
                return $aseanIsoMap[$code];
            }
        }

        // 3. Check territory
        $t = strtolower((string)$territory);
        foreach ($names as $key => $code) {
            if (str_contains($t, $key)) {
                return $aseanIsoMap[$code];
            }
        }

        return ['code' => 'ASEAN', 'name' => 'ASEAN Region', 'color' => '#06b6d4'];
    }

    /**
     * Classify maritime boundary line delimitation status (4-level classification)
     * 1. AGREED / DELIMITATED (Treaty, Court ruling)
     * 2. UNDELIMITED / UNRESOLVED (Median line, Connection line, Unsettled, Baselines)
     * 3. DISPUTED / OVERLAPPING CLAIM (Disputed, Overlapping claims)
     * 4. JOINT REGIME (Joint development area / regime)
     * 5. UNKNOWN / NOT VERIFIED (Fallback)
     */
    public static function classifyBoundaryStatus(?string $lineType, ?string $lineName): array
    {
        $lt = strtolower((string) $lineType);
        $ln = strtolower((string) $lineName);

        // 1. Joint Regime
        if (str_contains($lt, 'joint regime') || str_contains($ln, 'joint regime') || str_contains($ln, 'joint development')) {
            return [
                'status' => 'JOINT_REGIME',
                'status_label' => 'Joint Regime Area',
                'color' => '#a855f7',
                'dasharray' => [4, 2],
            ];
        }

        // 2. Disputed / Overlapping Claim
        if (str_contains($lt, 'disputed') || str_contains($ln, 'disputed') || str_contains($lt, 'overlapping') || str_contains($ln, 'overlapping')) {
            return [
                'status' => 'DISPUTED',
                'status_label' => 'Disputed / Overlapping Claim',
                'color' => '#f43f5e',
                'dasharray' => [2, 2],
            ];
        }

        // 3. Agreed / Delimitated
        if ($lt === 'treaty' || $lt === 'court ruling' || str_contains($ln, 'treaty') || str_contains($ln, 'court ruling')) {
            return [
                'status' => 'AGREED',
                'status_label' => 'Agreed / Delimitated',
                'color' => '#10b981',
                'dasharray' => null, // Solid line
            ];
        }

        // 4. Undelimited / Unresolved
        if (
            str_contains($lt, 'median line') ||
            str_contains($lt, 'connection line') ||
            str_contains($lt, 'unsettled') ||
            str_contains($lt, '200 nm') ||
            str_contains($lt, 'baseline') ||
            str_contains($lt, 'unilateral') ||
            str_contains($lt, '12 nm')
        ) {
            return [
                'status' => 'UNDELIMITED',
                'status_label' => 'Undelimited / Unresolved (Median/Interim)',
                'color' => '#f59e0b',
                'dasharray' => [3, 2],
            ];
        }

        // 5. Unknown / Not Verified
        return [
            'status' => 'UNKNOWN',
            'status_label' => 'Unknown / Not Verified',
            'color' => '#94a3b8',
            'dasharray' => [1, 2],
        ];
    }

    /**
     * Get ASEAN Exclusive Economic Zones (EEZ) and Maritime Boundaries as GeoJSON FeatureCollection
     *
     * @param array|null $bbox
     * @param string|null $search
     * @param string $type 'all'|'polygons'|'boundaries'
     * @return array
     */
    public function getAseanEezFeatureCollection(?array $bbox = null, ?string $search = null, string $type = 'all'): array
    {
        $features = [];
        $polygonCount = 0;
        $boundaryCount = 0;
        $normalizedBBox = $this->normalizeBBox($bbox);

        // 1. Fetch ASEAN EEZ Zones (Polygons)
        if ($type === 'all' || $type === 'polygons') {
            $zoneQuery = EezZone::query()->where(function ($q) {
                $q->whereIn('sovereign_iso3', self::ASEAN_ISO3)
                  ->orWhereIn('iso_ter1', self::ASEAN_ISO3);
                foreach (self::ASEAN_NAMES as $name) {
                    $q->orWhere('sovereign_state', 'like', "%{$name}%")
                      ->orWhere('sovereign2', 'like', "%{$name}%")
                      ->orWhere('sovereign3', 'like', "%{$name}%")
                      ->orWhere('territory1', 'like', "%{$name}%")
                      ->orWhere('geoname', 'like', "%{$name}%");
                }
            });

            if ($search) {
                $zoneQuery->where(function ($q) use ($search) {
                    $q->where('geoname', 'like', "%{$search}%")
                      ->orWhere('sovereign_state', 'like', "%{$search}%")
                      ->orWhere('sovereign_iso3', 'like', "%{$search}%");
                });
            }

            if ($normalizedBBox) {
                $zoneQuery->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
            }

            $zones = $zoneQuery->get();

            foreach ($zones as $zone) {
                $geom = is_array($zone->geometry_geojson) 
                    ? $zone->geometry_geojson 
                    : json_decode($zone->geometry_geojson, true);
                if (!$geom) continue;

                $polType = (string) $zone->pol_type;
                $geoname = (string) $zone->geoname;

                // Determine zone delimitation status
                if (stripos($polType, 'overlapping') !== false || stripos($geoname, 'overlapping') !== false || stripos($polType, 'disputed') !== false) {
                    $status = 'DISPUTED';
                    $statusLabel = 'Disputed / Overlapping Claim';
                } elseif (stripos($polType, 'joint') !== false || stripos($geoname, 'joint') !== false) {
                    $status = 'JOINT_REGIME';
                    $statusLabel = 'Joint Regime Area';
                } else {
                    $status = 'AGREED';
                    $statusLabel = 'National Exclusive Economic Zone (200 NM)';
                }

                $countryInfo = self::resolveAseanCountry($zone->sovereign_state, $zone->sovereign2, $zone->territory1, $zone->sovereign_iso3);
                $bordering = array_filter([$zone->sovereign_state, $zone->sovereign2, $zone->sovereign3]);

                $features[] = [
                    'type' => 'Feature',
                    'id' => 'zone-' . $zone->id,
                    'geometry' => $geom,
                    'properties' => [
                        'id' => $zone->id,
                        'feature_type' => 'zone',
                        'geom_type' => $geom['type'] ?? 'Polygon',
                        'name' => $zone->geoname,
                        'country' => $countryInfo['name'],
                        'country_code' => $countryInfo['code'],
                        'sovereign_iso3' => $countryInfo['code'],
                        'territory' => $zone->territory1,
                        'zone_type' => $zone->pol_type,
                        'delimitation_status' => $status,
                        'status_label' => $statusLabel,
                        'color' => $countryInfo['color'],
                        'area_sqkm' => $zone->area_sqkm,
                        'sovereign1' => $zone->sovereign_state,
                        'sovereign2' => $zone->sovereign2,
                        'bordering_parties' => !empty($bordering) ? implode(', ', $bordering) : $zone->sovereign_state,
                        'treaty_name' => $zone->pol_type === '200NM' ? 'UNCLOS 1982 Exclusive Economic Zone (200NM)' : $zone->geoname,
                        'source_dataset' => 'Marine Regions Maritime Boundaries Geodatabase v12',
                        'version' => 'v12',
                        'doi' => '10.14284/632',
                        'license' => 'CC BY 4.0 International',
                        'doc_date' => $zone->import_date ? (string) $zone->import_date : '2023-10-01',
                        'disclaimer' => 'Batas yurisdiksi hak ekonomi maritim regional ASEAN berdasarkan Marine Regions v12. Bukan penetapan batas hukum final.',
                    ]
                ];
                $polygonCount++;
            }
        }

        // 2. Fetch ASEAN EEZ Maritime Boundaries (Lines)
        if ($type === 'all' || $type === 'boundaries') {
            $boundaryQuery = EezBoundary::query()->where(function ($q) {
                foreach (self::ASEAN_NAMES as $name) {
                    $q->orWhere('sovereign1', 'like', "%{$name}%")
                      ->orWhere('sovereign2', 'like', "%{$name}%")
                      ->orWhere('territory1', 'like', "%{$name}%")
                      ->orWhere('territory2', 'like', "%{$name}%");
                }
            });

            if ($search) {
                $boundaryQuery->where(function ($q) use ($search) {
                    $q->where('line_name', 'like', "%{$search}%")
                      ->orWhere('sovereign1', 'like', "%{$search}%")
                      ->orWhere('sovereign2', 'like', "%{$search}%")
                      ->orWhere('line_type', 'like', "%{$search}%");
                });
            }

            if ($normalizedBBox) {
                $boundaryQuery->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
            }

            $boundaries = $boundaryQuery->get();

            foreach ($boundaries as $b) {
                $geom = is_array($b->geometry_geojson) 
                    ? $b->geometry_geojson 
                    : json_decode($b->geometry_geojson, true);
                if (!$geom) continue;

                $classification = self::classifyBoundaryStatus($b->line_type, $b->line_name);
                $countryInfo = self::resolveAseanCountry($b->sovereign1, $b->sovereign2, $b->territory1);

                $parties = array_filter([$b->sovereign1, $b->sovereign2]);
                $borderingStr = count($parties) > 1 ? implode(' & ', $parties) : ($parties[0] ?? $b->line_name);

                $features[] = [
                    'type' => 'Feature',
                    'id' => 'line-' . $b->id,
                    'geometry' => $geom,
                    'properties' => [
                        'id' => $b->id,
                        'feature_type' => 'boundary_line',
                        'geom_type' => $geom['type'] ?? 'LineString',
                        'line_id' => $b->line_id,
                        'name' => $b->line_name,
                        'country' => $countryInfo['name'],
                        'country_code' => $countryInfo['code'],
                        'sovereign1' => $b->sovereign1,
                        'sovereign2' => $b->sovereign2,
                        'territory1' => $b->territory1,
                        'territory2' => $b->territory2,
                        'bordering_parties' => $borderingStr,
                        'line_type' => $b->line_type,
                        'delimitation_status' => $classification['status'],
                        'status_label' => $classification['status_label'],
                        'color' => $countryInfo['color'],
                        'dasharray' => $classification['dasharray'],
                        'treaty_name' => $b->source1 ?: $b->line_name,
                        'url' => $b->url1,
                        'doc_date' => $b->doc_date,
                        'length_km' => $b->length_km,
                        'source_dataset' => 'Marine Regions Maritime Boundaries Geodatabase v12 (eez_boundaries_v12)',
                        'version' => 'v12',
                        'doi' => '10.14284/632',
                        'license' => 'CC BY 4.0 International',
                        'disclaimer' => 'Batas maritim resmi Marine Regions v12. Status delimitasi diklasifikasikan berdasarkan instrumen hukum traktat/kesepakatan resmi.',
                    ]
                ];
                $boundaryCount++;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'title' => 'ASEAN Exclusive Economic Zones (EEZ) & Maritime Boundaries',
                'scope' => 'ASEAN Member States + Candidate (Brunei, Cambodia, Indonesia, Laos [Landlocked], Malaysia, Myanmar, Philippines, Singapore, Thailand, Vietnam, Timor-Leste)',
                'member_states' => [
                    'Brunei Darussalam', 'Cambodia', 'Indonesia', 'Laos', 'Malaysia',
                    'Myanmar', 'Philippines', 'Singapore', 'Thailand', 'Vietnam', 'Timor-Leste'
                ],
                'landlocked_states' => ['Laos'],
                'count' => count($features),
                'polygon_count' => $polygonCount,
                'boundary_line_count' => $boundaryCount,
                'statuses' => [
                    'AGREED' => 'Agreed / Delimitated',
                    'UNDELIMITED' => 'Undelimited / Unresolved',
                    'DISPUTED' => 'Disputed / Overlapping Claim',
                    'JOINT_REGIME' => 'Joint Regime Area',
                    'UNKNOWN' => 'Unknown / Not Verified',
                ],
                'dataset' => 'Maritime Boundaries Geodatabase v12 (October 2023)',
                'publisher' => 'Flanders Marine Institute (VLIZ) Marine Regions',
                'doi' => '10.14284/632',
                'license' => 'CC BY 4.0 International',
            ]
        ];
    }

    /**
     * Get Australia Exclusive Economic Zone (EEZ) and Maritime Boundaries as GeoJSON FeatureCollection
     *
     * @param array|null $bbox
     * @param string|null $search
     * @param string $type 'all'|'polygons'|'boundaries'
     * @return array
     */
    public function getAustraliaEezFeatureCollection(?array $bbox = null, ?string $search = null, string $type = 'all'): array
    {
        $features = [];
        $polygonCount = 0;
        $boundaryCount = 0;
        $normalizedBBox = $this->normalizeBBox($bbox);

        // 1. Fetch Australia EEZ Zones (Polygons)
        if ($type === 'all' || $type === 'polygons') {
            $zoneQuery = EezZone::query()->where(function ($q) {
                $q->where('sovereign_iso3', 'AUS')
                  ->orWhere('sovereign_state', 'like', '%Australia%')
                  ->orWhere('territory1', 'like', '%Australia%');
            });

            if ($search) {
                $zoneQuery->where(function ($q) use ($search) {
                    $q->where('geoname', 'like', "%{$search}%")
                      ->orWhere('sovereign_state', 'like', "%{$search}%")
                      ->orWhere('territory1', 'like', "%{$search}%");
                });
            }

            if ($normalizedBBox) {
                $zoneQuery->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
            }

            $zones = $zoneQuery->get();

            foreach ($zones as $zone) {
                $geom = is_array($zone->geometry_geojson) 
                    ? $zone->geometry_geojson 
                    : json_decode($zone->geometry_geojson, true);
                if (!$geom) continue;

                $features[] = [
                    'type' => 'Feature',
                    'id' => 'aus-zone-' . $zone->id,
                    'geometry' => $geom,
                    'properties' => [
                        'id' => $zone->id,
                        'feature_type' => 'zone',
                        'geom_type' => $geom['type'] ?? 'Polygon',
                        'name' => $zone->geoname,
                        'country' => 'Australia',
                        'country_code' => 'AUS',
                        'sovereign_iso3' => 'AUS',
                        'territory' => $zone->territory1,
                        'zone_type' => $zone->pol_type,
                        'delimitation_status' => 'AGREED',
                        'status_label' => 'Australian Exclusive Economic Zone (200 NM)',
                        'color' => '#ef4444', // Australia: Red-Orange
                        'area_sqkm' => $zone->area_sqkm,
                        'sovereign1' => $zone->sovereign_state,
                        'sovereign2' => $zone->sovereign2,
                        'bordering_parties' => $zone->sovereign_state,
                        'treaty_name' => 'UNCLOS 1982 Australian Exclusive Economic Zone (200NM)',
                        'source_dataset' => 'Maritime Boundaries Geodatabase v12 (Marine Regions) & Australian Maritime Boundaries (AMSIS / Geoscience Australia)',
                        'version' => 'v12',
                        'doi' => '10.14284/632',
                        'license' => 'CC BY 4.0 International',
                        'doc_date' => $zone->import_date ? (string) $zone->import_date : '2023-10-01',
                        'disclaimer' => 'Batas yurisdiksi maritim resmi Zona Ekonomi Eksklusif Australia berdasarkan UNCLOS 1982 dan traktat bilateral internasional.',
                    ]
                ];
                $polygonCount++;
            }
        }

        // 2. Fetch Australia EEZ Maritime Boundaries (Lines)
        if ($type === 'all' || $type === 'boundaries') {
            $boundaryQuery = EezBoundary::query()->where(function ($q) {
                $q->where('sovereign1', 'like', '%Australia%')
                  ->orWhere('sovereign2', 'like', '%Australia%')
                  ->orWhere('eez1', 'like', '%Australia%')
                  ->orWhere('eez2', 'like', '%Australia%');
            })->whereIn('line_type', ['200 NM', 'Treaty', 'Median line']);

            if ($search) {
                $boundaryQuery->where(function ($q) use ($search) {
                    $q->where('line_name', 'like', "%{$search}%")
                      ->orWhere('sovereign1', 'like', "%{$search}%")
                      ->orWhere('sovereign2', 'like', "%{$search}%")
                      ->orWhere('line_type', 'like', "%{$search}%");
                });
            }

            if ($normalizedBBox) {
                $boundaryQuery->inBoundingBox($normalizedBBox[0], $normalizedBBox[1], $normalizedBBox[2], $normalizedBBox[3]);
            }

            $boundaries = $boundaryQuery->get();

            foreach ($boundaries as $b) {
                $geom = is_array($b->geometry_geojson) 
                    ? $b->geometry_geojson 
                    : json_decode($b->geometry_geojson, true);
                if (!$geom) continue;

                $lineType = (string) $b->line_type;
                if ($lineType === '200 NM') {
                    $status = '200NM_LIMIT';
                    $statusLabel = 'Australia 200 NM Outer EEZ Limit';
                    $color = '#0284c7'; // Azure
                    $dasharray = [4, 2];
                } elseif ($lineType === 'Treaty') {
                    $status = 'AGREED';
                    $statusLabel = 'Agreed Bilateral Maritime Treaty';
                    $color = '#38bdf8'; // Sky blue / cyan
                    $dasharray = null;
                } else {
                    $status = 'UNDELIMITED';
                    $statusLabel = 'Median Line / Equidistant Boundary';
                    $color = '#f59e0b'; // Amber
                    $dasharray = [3, 2];
                }

                $parties = array_filter([$b->sovereign1, $b->sovereign2]);
                $borderingStr = count($parties) > 1 ? implode(' & ', $parties) : ($parties[0] ?? $b->line_name);

                $features[] = [
                    'type' => 'Feature',
                    'id' => 'aus-line-' . $b->id,
                    'geometry' => $geom,
                    'properties' => [
                        'id' => $b->id,
                        'feature_type' => 'boundary_line',
                        'geom_type' => $geom['type'] ?? 'LineString',
                        'line_id' => $b->line_id,
                        'name' => $b->line_name,
                        'country' => 'Australia',
                        'country_code' => 'AUS',
                        'sovereign1' => $b->sovereign1,
                        'sovereign2' => $b->sovereign2,
                        'territory1' => $b->territory1,
                        'territory2' => $b->territory2,
                        'bordering_parties' => $borderingStr,
                        'line_type' => $b->line_type,
                        'delimitation_status' => $status,
                        'status_label' => $statusLabel,
                        'color' => '#ef4444', // Australia: Red-Orange
                        'dasharray' => $dasharray,
                        'treaty_name' => $b->source1 ?: $b->line_name,
                        'url' => $b->url1,
                        'doc_date' => $b->doc_date,
                        'length_km' => $b->length_km,
                        'source_dataset' => 'Maritime Boundaries Geodatabase v12 (eez_boundaries_v12) & Australian Maritime Boundaries (AMSIS / AMB)',
                        'version' => 'v12',
                        'doi' => '10.14284/632',
                        'license' => 'CC BY 4.0 International',
                        'disclaimer' => 'Batas maritim resmi Zona Ekonomi Eksklusif Australia berdasarkan UNCLOS 1982 dan traktat maritim bilateral internasional.',
                    ]
                ];
                $boundaryCount++;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'title' => 'Australia Exclusive Economic Zone (EEZ) & Maritime Boundaries',
                'scope' => 'Commonwealth of Australia & External Territories (Mainland Australia, Christmas Island, Cocos (Keeling) Islands, Norfolk Island, Macquarie Island, Heard and McDonald Islands)',
                'source' => 'Australian Marine Spatial Information System (AMSIS / AMB) & Marine Regions v12 (VLIZ)',
                'count' => count($features),
                'polygon_count' => $polygonCount,
                'boundary_line_count' => $boundaryCount,
                'doi' => '10.14284/632',
                'publisher' => 'Flanders Marine Institute (VLIZ) Marine Regions & Geoscience Australia',
                'license' => 'CC BY 4.0 International',
                'dataset' => 'Maritime Boundaries Geodatabase v12 (October 2023)',
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
     * Get accurate fleet vessel statistics
     * Distinguishes: Total in database, with valid sea positions, active (< 2h), stale (> 2h)
     */
    public function getVesselStatistics(): array
    {
        return Cache::remember('vessel_spatial_statistics', 60, function () {
            $total = Vessel::count();

            $driver = DB::connection()->getDriverName();
            $cutoff = now()->subHours(2)->toDateTimeString();

            if ($driver === 'pgsql') {
                $row = DB::selectOne("
                    SELECT
                        COUNT(DISTINCT vessel_id) AS valid_positions,
                        COUNT(DISTINCT CASE WHEN observed_at >= ? THEN vessel_id END) AS active_vessels
                    FROM vessel_positions
                    WHERE is_on_land = false
                      AND NOT (latitude = 0.0 AND longitude = 0.0)
                      AND (data_quality_score IS NULL OR data_quality_score > 0.0)
                ", [$cutoff]);

                $validPositionsCount = (int) ($row->valid_positions ?? 0);
                $activeCount = (int) ($row->active_vessels ?? 0);
            } else {
                $baseValidQuery = VesselPosition::query()
                    ->where('is_on_land', false)
                    ->whereRaw('NOT (latitude = 0.0 AND longitude = 0.0)')
                    ->where(function ($q) {
                        $q->whereNull('data_quality_score')
                          ->orWhere('data_quality_score', '>', 0.0);
                    });

                $validPositionsCount = (clone $baseValidQuery)
                    ->distinct('vessel_id')
                    ->count('vessel_id');

                $activeCount = (clone $baseValidQuery)
                    ->where('observed_at', '>=', $cutoff)
                    ->distinct('vessel_id')
                    ->count('vessel_id');
            }

            $staleCount = max(0, $validPositionsCount - $activeCount);

            return [
                'total_vessels' => $total,
                'valid_positions' => $validPositionsCount,
                'active_vessels' => $activeCount,
                'stale_vessels' => $staleCount,
            ];
        });
    }

    /**
     * Get Live AIS Vessel Positions as GeoJSON FeatureCollection
     * High-speed, memory-efficient implementation:
     * - Direct SQL join selecting only essential scalar columns (omits raw_payload & metadata)
     * - Bounding box spatial indexing (PostGIS ST_MakeEnvelope / GiST index)
     * - Antimeridian crossing support
     * - Global mode: max 2,000 unique vessels
     * - Viewport mode: unlimited viewport loading via batch pagination
     */
    public function getLiveVesselsFeatureCollection(
        ?array $bbox = null,
        ?string $search = null,
        int $limit = 2000,
        array $filters = [],
        string $mode = 'global',
        int $page = 1,
        ?int $totalInViewport = null,
        ?float $zoom = null
    ): array
    {
        $driver = DB::connection()->getDriverName();
        $mode = in_array(strtolower($mode), ['global', 'viewport'], true) ? strtolower($mode) : 'global';
        $page = max(1, $page);

        if ($mode === 'global') {
            $limit = min(max($limit, 1), 2000);
        } else {
            $limit = min(max($limit, 1), 5000);
        }

        $offset = ($page - 1) * $limit;

        // Collect SQL WHERE clauses and bindings for the inner vessel_positions query
        $posWheres = [
            "(is_on_land = false OR is_on_land IS NULL)",
            "NOT (latitude = 0.0 AND longitude = 0.0)",
            "(data_quality_score IS NULL OR data_quality_score > 0.0)",
        ];
        $posBindings = [];

        // Bounding box filtering for Viewport mode
        if ($mode === 'viewport' && $bbox && count($bbox) === 4) {
            $minLng = (float) $bbox[0];
            $minLat = min((float) $bbox[1], (float) $bbox[3]);
            $maxLng = (float) $bbox[2];
            $maxLat = max((float) $bbox[1], (float) $bbox[3]);

            $isCrossAntimeridian = ($minLng > $maxLng);

            if ($isCrossAntimeridian) {
                $posWheres[] = "(latitude BETWEEN ? AND ?) AND (longitude >= ? OR longitude <= ?)";
                $posBindings[] = $minLat;
                $posBindings[] = $maxLat;
                $posBindings[] = $minLng;
                $posBindings[] = $maxLng;
            } else {
                $posWheres[] = "(latitude BETWEEN ? AND ?) AND (longitude BETWEEN ? AND ?)";
                $posBindings[] = $minLat;
                $posBindings[] = $maxLat;
                $posBindings[] = $minLng;
                $posBindings[] = $maxLng;
            }
        }

        // Position-level filters: SOG, COG, Heading, Freshness, Date Range
        if (isset($filters['min_sog']) && is_numeric($filters['min_sog'])) {
            $posWheres[] = "speed_knots >= ?";
            $posBindings[] = (float) $filters['min_sog'];
        }
        if (isset($filters['max_sog']) && is_numeric($filters['max_sog'])) {
            $posWheres[] = "speed_knots <= ?";
            $posBindings[] = (float) $filters['max_sog'];
        }

        if (isset($filters['min_cog']) && is_numeric($filters['min_cog'])) {
            $posWheres[] = "course_degrees >= ?";
            $posBindings[] = (float) $filters['min_cog'];
        }
        if (isset($filters['max_cog']) && is_numeric($filters['max_cog'])) {
            $posWheres[] = "course_degrees <= ?";
            $posBindings[] = (float) $filters['max_cog'];
        }

        if (!empty($filters['heading_status'])) {
            if ($filters['heading_status'] === 'has_heading') {
                $posWheres[] = "heading_degrees IS NOT NULL AND heading_degrees >= 0.0 AND heading_degrees < 360.0 AND heading_degrees != 511";
            } elseif ($filters['heading_status'] === 'cog_fallback') {
                $posWheres[] = "(heading_degrees IS NULL OR heading_degrees >= 360.0 OR heading_degrees = 511) AND course_degrees IS NOT NULL AND course_degrees >= 0.0 AND course_degrees < 360.0 AND course_degrees != 3600";
            } elseif ($filters['heading_status'] === 'unavailable') {
                $posWheres[] = "(heading_degrees IS NULL OR heading_degrees >= 360.0 OR heading_degrees = 511) AND (course_degrees IS NULL OR course_degrees >= 360.0 OR course_degrees = 3600)";
            }
        }

        if (!empty($filters['freshness'])) {
            if ($filters['freshness'] === 'live') {
                $posWheres[] = "observed_at >= ?";
                $posBindings[] = now()->subHours(2)->toDateTimeString();
            } elseif ($filters['freshness'] === 'stale') {
                $posWheres[] = "observed_at < ?";
                $posBindings[] = now()->subHours(2)->toDateTimeString();
            }
        }

        if (!empty($filters['date_range'])) {
            if ($filters['date_range'] === '24h') {
                $posWheres[] = "observed_at >= ?";
                $posBindings[] = now()->subHours(24)->toDateTimeString();
            } elseif ($filters['date_range'] === '7d') {
                $posWheres[] = "observed_at >= ?";
                $posBindings[] = now()->subDays(7)->toDateTimeString();
            } elseif ($filters['date_range'] === '30d') {
                $posWheres[] = "observed_at >= ?";
                $posBindings[] = now()->subDays(30)->toDateTimeString();
            } elseif ($filters['date_range'] === 'custom') {
                if (!empty($filters['start_date'])) {
                    $posWheres[] = "observed_at >= ?";
                    $posBindings[] = $filters['start_date'];
                }
                if (!empty($filters['end_date'])) {
                    $posWheres[] = "observed_at <= ?";
                    $posBindings[] = $filters['end_date'];
                }
            }
        }

        // Collect SQL WHERE clauses and bindings for the outer vessels query
        $vesselWheres = [];
        $vesselBindings = [];

        if ($search) {
            $likeOp = ($driver === 'pgsql') ? 'ILIKE' : 'LIKE';
            $vesselWheres[] = "(v.name {$likeOp} ? OR v.mmsi {$likeOp} ? OR v.imo {$likeOp} ? OR v.callsign {$likeOp} ?)";
            $searchTerm = "%{$search}%";
            $vesselBindings[] = $searchTerm;
            $vesselBindings[] = $searchTerm;
            $vesselBindings[] = $searchTerm;
            $vesselBindings[] = $searchTerm;
        }

        if (!empty($filters['flag'])) {
            $criteria = FlagStateService::getFilterCriteria($filters['flag']);
            if ($criteria['is_unknown']) {
                $vesselWheres[] = "(v.flag_state IS NULL OR v.flag_state = '' OR LOWER(v.flag_state) IN ('unknown', 'un', '-'))";
            } else {
                $codePlaceholders = implode(',', array_fill(0, count($criteria['codes']), '?'));
                $flagClause = "v.flag_state IN ({$codePlaceholders})";
                foreach ($criteria['codes'] as $c) {
                    $vesselBindings[] = $c;
                }

                if (!empty($criteria['mids'])) {
                    $midClauses = [];
                    foreach ($criteria['mids'] as $mid) {
                        $midClauses[] = "v.mmsi LIKE ?";
                        $vesselBindings[] = "{$mid}%";
                    }
                    $midSql = implode(' OR ', $midClauses);
                    $flagClause = "({$flagClause} OR ((v.flag_state IS NULL OR v.flag_state = '' OR LOWER(v.flag_state) IN ('unknown', 'un', '-')) AND ({$midSql})))";
                }
                $vesselWheres[] = $flagClause;
            }
        }

        // Resolve vessel types filter (defaults to fishing only unless explicitly set to 'all' or specific types)
        $typesInput = $filters['vessel_types'] ?? $filters['types'] ?? ($filters['vessel_type'] ?? null);

        $selectedTypes = [];
        if ($typesInput !== null && $typesInput !== '') {
            if (is_array($typesInput)) {
                $selectedTypes = array_map(fn($t) => strtolower(trim((string)$t)), $typesInput);
            } else {
                $trimmed = strtolower(trim((string)$typesInput));
                if ($trimmed === 'all') {
                    $selectedTypes = ['all'];
                } else {
                    $parts = explode(',', $trimmed);
                    $selectedTypes = array_filter(array_map('trim', $parts));
                }
            }
        }

        // DEFAULT TO FISHING ONLY if no type filter specified:
        if (empty($selectedTypes)) {
            $selectedTypes = ['fishing'];
        }

        $validTypes = [];
        if (!in_array('all', $selectedTypes, true)) {
            foreach ($selectedTypes as $st) {
                $clean = preg_replace('/[^a-z0-9_\-]/', '', $st);
                if ($clean !== '') {
                    $validTypes[] = $clean;
                }
            }
            if (empty($validTypes)) {
                $validTypes = ['fishing'];
            }

            if (count($validTypes) === 1) {
                $vesselWheres[] = "LOWER(TRIM(COALESCE(v.vessel_type, ''))) = ?";
                $vesselBindings[] = $validTypes[0];
            } else {
                $placeholders = implode(',', array_fill(0, count($validTypes), '?'));
                $vesselWheres[] = "LOWER(TRIM(COALESCE(v.vessel_type, ''))) IN ({$placeholders})";
                foreach ($validTypes as $vt) {
                    $vesselBindings[] = $vt;
                }
            }
        }

        $posWhereSql = implode(' AND ', $posWheres);
        $vesselWhereSql = !empty($vesselWheres) ? ('WHERE ' . implode(' AND ', $vesselWheres)) : '';

        // If totalInViewport is not already known, include window count
        $countSelect = ($totalInViewport === null) ? ", COUNT(*) OVER() AS full_count" : "";

        if ($driver === 'pgsql') {
            $sql = "
                WITH target_vessels AS (
                    SELECT
                        v.id, v.name, v.mmsi, v.imo, v.callsign, v.hull_code,
                        v.flag_state, v.vessel_type, v.enrichment_status, v.verification_status
                    FROM vessels v
                    {$vesselWhereSql}
                ),
                latest_pos AS (
                    SELECT DISTINCT ON (vp.vessel_id)
                        vp.id, vp.vessel_id, vp.latitude, vp.longitude, vp.speed_knots, vp.course_degrees,
                        vp.heading_degrees, vp.nav_status, vp.observed_at, vp.data_quality_score, vp.is_on_land, vp.source_provider_id
                    FROM vessel_positions vp
                    JOIN target_vessels tv ON tv.id = vp.vessel_id
                    WHERE {$posWhereSql}
                    ORDER BY vp.vessel_id, vp.observed_at DESC
                )
                SELECT
                    lp.id,
                    lp.latitude,
                    lp.longitude,
                    lp.speed_knots,
                    lp.course_degrees,
                    lp.heading_degrees,
                    lp.nav_status,
                    lp.observed_at,
                    lp.data_quality_score,
                    lp.is_on_land,
                    tv.id AS vessel_id,
                    tv.name,
                    tv.mmsi,
                    tv.imo,
                    tv.callsign,
                    tv.hull_code,
                    tv.flag_state,
                    tv.vessel_type,
                    tv.enrichment_status,
                    tv.verification_status,
                    dp.name AS provider_name
                    {$countSelect}
                FROM latest_pos lp
                JOIN target_vessels tv ON tv.id = lp.vessel_id
                LEFT JOIN data_providers dp ON dp.id = lp.source_provider_id
                ORDER BY lp.observed_at DESC, lp.id DESC
                LIMIT ? OFFSET ?
            ";
        } else {
            // SQLite / fallback compatible window query
            $sql = "
                WITH target_vessels AS (
                    SELECT
                        v.id, v.name, v.mmsi, v.imo, v.callsign, v.hull_code,
                        v.flag_state, v.vessel_type, v.enrichment_status, v.verification_status
                    FROM vessels v
                    {$vesselWhereSql}
                ),
                ranked_pos AS (
                    SELECT
                        vp.id, vp.vessel_id, vp.latitude, vp.longitude, vp.speed_knots, vp.course_degrees,
                        vp.heading_degrees, vp.nav_status, vp.observed_at, vp.data_quality_score, vp.is_on_land, vp.source_provider_id,
                        ROW_NUMBER() OVER (PARTITION BY vp.vessel_id ORDER BY vp.observed_at DESC, vp.id DESC) as rn
                    FROM vessel_positions vp
                    JOIN target_vessels tv ON tv.id = vp.vessel_id
                    WHERE {$posWhereSql}
                ),
                latest_pos AS (
                    SELECT * FROM ranked_pos WHERE rn = 1
                )
                SELECT
                    lp.id,
                    lp.latitude,
                    lp.longitude,
                    lp.speed_knots,
                    lp.course_degrees,
                    lp.heading_degrees,
                    lp.nav_status,
                    lp.observed_at,
                    lp.data_quality_score,
                    lp.is_on_land,
                    tv.id AS vessel_id,
                    tv.name,
                    tv.mmsi,
                    tv.imo,
                    tv.callsign,
                    tv.hull_code,
                    tv.flag_state,
                    tv.vessel_type,
                    tv.enrichment_status,
                    tv.verification_status,
                    dp.name AS provider_name
                    {$countSelect}
                FROM latest_pos lp
                JOIN target_vessels tv ON tv.id = lp.vessel_id
                LEFT JOIN data_providers dp ON dp.id = lp.source_provider_id
                ORDER BY lp.observed_at DESC, lp.id DESC
                LIMIT ? OFFSET ?
            ";
        }

        $allBindings = array_merge($vesselBindings, $posBindings, [$limit, $offset]);

        $cachePayload = [
            'bbox' => $bbox,
            'search' => $search,
            'limit' => $limit,
            'mode' => $mode,
            'page' => $page,
            'totalInViewport' => $totalInViewport,
            'zoom' => $zoom !== null ? round($zoom, 1) : null,
            'filters' => $filters,
        ];
        $cacheKey = 'vessels_geo_' . md5(json_encode($cachePayload));

        return Cache::remember($cacheKey, 15, function () use (
            $sql, $allBindings, $totalInViewport, $limit, $mode, $page, $bbox, $zoom, $selectedTypes, $validTypes
        ) {
            $rows = DB::select($sql, $allBindings);

            if ($totalInViewport === null) {
                $totalInViewport = !empty($rows) ? (int) ($rows[0]->full_count ?? count($rows)) : 0;
            }

            $totalPages = $limit > 0 ? (int) max(1, ceil($totalInViewport / $limit)) : 1;
            $hasMore = $mode === 'viewport' && ($page < $totalPages);
            $nextPage = $hasMore ? $page + 1 : null;

            $features = [];
            $nowTs = time();

            foreach ($rows as $pos) {
                $lat = (float) $pos->latitude;
                $lng = (float) $pos->longitude;
                if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || ($lat === 0.0 && $lng === 0.0) || (bool) $pos->is_on_land) {
                    continue;
                }

                $flagInfo = FlagStateService::resolveFromCodeAndMmsi($pos->flag_state, $pos->mmsi);

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

                $obsTime = $pos->observed_at ? strtotime((string) $pos->observed_at) : false;
                $isFresh = $obsTime ? (($nowTs - $obsTime) <= 7200) : false;
                $observedAtIso = $obsTime ? gmdate('Y-m-d\TH:i:s\Z', $obsTime) : null;

                $features[] = [
                    'type' => 'Feature',
                    'id' => $pos->id,
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$lng, $lat],
                    ],
                    'properties' => [
                        'vessel_id' => $pos->vessel_id,
                        'name' => $pos->name,
                        'mmsi' => $pos->mmsi,
                        'imo' => $pos->imo,
                        'callsign' => $pos->callsign,
                        'hull_code' => $pos->hull_code,
                        'flag_state' => $flagInfo['iso2'],
                        'flag_country' => $flagInfo['country_name'],
                        'flag_emoji' => $flagInfo['flag_emoji'],
                        'flag_label' => $flagInfo['label'],
                        'flag_status' => $flagInfo['status'],
                        'flag_verified' => $flagInfo['is_verified'],
                        'vessel_type' => $pos->vessel_type,
                        'enrichment_status' => $pos->enrichment_status ?? 'not_enriched',
                        'verification_status' => $pos->verification_status ?? 'unverified',
                        'is_manually_verified' => ($pos->verification_status === 'verified'),
                        'speed_knots' => $pos->speed_knots !== null && is_finite((float) $pos->speed_knots) ? round((float) $pos->speed_knots, 1) : null,
                        'course_degrees' => $cog,
                        'heading_degrees' => $heading,
                        'orientation_degrees' => $orientationDegrees,
                        'orientation_source' => $orientationSource,
                        'orientation_type' => $orientationType,
                        'has_orientation' => $hasOrientation,
                        'nav_status' => $pos->nav_status,
                        'observed_at' => $observedAtIso,
                        'is_fresh' => $isFresh,
                        'data_quality_score' => (float) $pos->data_quality_score,
                        'provider' => $pos->provider_name ?? 'Native AIS',
                    ]
                ];
            }

            $stats = $this->getVesselStatistics();

            return [
                'type' => 'FeatureCollection',
                'features' => $features,
                'metadata' => [
                    'mode' => $mode,
                    'zoom' => $zoom,
                    'bbox' => $bbox,
                    'count' => count($features),
                    'rendered_count' => count($features),
                    'limit' => $limit,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'total_in_viewport' => $totalInViewport,
                    'has_more' => $hasMore,
                    'next_page' => $nextPage,
                    'vessel_types' => in_array('all', $selectedTypes, true) ? ['all'] : $validTypes,
                    'default_filter' => 'fishing',
                    'total_vessels' => $stats['total_vessels'],
                    'valid_positions' => $stats['valid_positions'],
                    'active_vessels' => $stats['active_vessels'],
                    'stale_vessels' => $stats['stale_vessels'],
                    'generated_at' => now()->toIso8601String(),
                ]
            ];
        });
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
                    'coordinates' => [(float) $enc->longitude, (float) $enc->latitude],
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
                    'coordinates' => [(float) $det->longitude, (float) $det->latitude],
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

    /**
     * Calculate geodesic distance between two coordinate pairs in Nautical Miles using Haversine formula.
     */
    public function haversineDistanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);

        $dLat = $lat2Rad - $lat1Rad;
        $dLon = $lon2Rad - $lon1Rad;

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos($lat1Rad) * cos($lat2Rad) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        // Earth radius in nautical miles: 3440.065 NM
        return 3440.065 * $c;
    }

    /**
     * Generate GeoJSON FeatureCollection for a vessel's historical AIS track (Unlimited Historical Track).
     *
     * @param Vessel $vessel
     * @param int $limit Maximum historical positions to return in this batch (default: 1000, max: 5000)
     * @param string|null $timeRange '24h', '48h', '72h', '7d', '14d', '30d', '90d', '180d', '1y', 'all', 'custom'
     * @param array $options Additional options: 'start_date', 'start_time', 'end_date', 'end_time', 'cursor', 'fresh'
     * @return array GeoJSON FeatureCollection containing LineString, Point features, and forensic statistics
     */
    public function getVesselTrackFeatureCollection(
        Vessel $vessel,
        int $limit = 1000,
        ?string $timeRange = '7d',
        array $options = []
    ): array {
        $limit = min(max($limit, 1), 5000);
        $rangeStr = strtolower(trim($timeRange ?: '7d'));
        $cursor = $options['cursor'] ?? null;
        $isFresh = !empty($options['fresh']);

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
                } catch (\Throwable $e) {
                    $startDateTime = null;
                }
            }
            if ($endDateStr) {
                try {
                    $parsedEnd = \Carbon\Carbon::parse("{$endDateStr} {$endTimeStr}");
                    $endDateTime = $parsedEnd->gt(now()) ? now() : $parsedEnd;
                } catch (\Throwable $e) {
                    $endDateTime = now();
                }
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
        } elseif ($rangeStr === '1h') {
            $startDateTime = now()->subHours(1);
        } elseif ($rangeStr === '6h') {
            $startDateTime = now()->subHours(6);
        } elseif (is_numeric($rangeStr)) {
            $startDateTime = now()->subHours((int) $rangeStr);
        } else {
            $startDateTime = now()->subDays(7);
        }

        // Cache evaluation (Section 10): 30s to 300s TTL depending on time range volatility
        $cacheTtlSeconds = match (true) {
            in_array($rangeStr, ['1h', '6h', '24h', '48h']) => 30,
            in_array($rangeStr, ['72h', '7d', '14d']) => 60,
            default => 300,
        };

        $cacheKey = sprintf(
            'hist_track_%s_%s_%s_%s_%d_%s',
            $vessel->id,
            $rangeStr,
            $startDateTime ? $startDateTime->timestamp : 'none',
            $endDateTime ? $endDateTime->timestamp : 'none',
            $limit,
            $cursor ? md5($cursor) : 'head'
        );

        if (!$isFresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Retrieve all-time observation bounds for vessel without full-table scan (uses vessel_id, observed_at index)
        $allTimeBounds = VesselPosition::where('vessel_id', $vessel->id)
            ->selectRaw('MIN(observed_at) as earliest_obs, MAX(observed_at) as latest_obs, COUNT(*) as total_count')
            ->first();

        // Optimized column selection: explicitly omit heavy raw_payload JSON column
        $columns = [
            'id',
            'vessel_id',
            'latitude',
            'longitude',
            'speed_knots',
            'course_degrees',
            'heading_degrees',
            'nav_status',
            'observed_at',
            'data_quality_score',
            'is_gap_indicator',
        ];

        $baseQuery = VesselPosition::where('vessel_id', $vessel->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [-90.0, 90.0])
            ->whereBetween('longitude', [-180.0, 180.0])
            ->whereRaw('NOT (latitude = 0.0 AND longitude = 0.0)')
            ->where(function ($q) {
                $q->whereNull('data_quality_score')
                  ->orWhere('data_quality_score', '>', 0.0);
            });

        if ($startDateTime) {
            $baseQuery->where('observed_at', '>=', $startDateTime);
        }
        if ($endDateTime) {
            $baseQuery->where('observed_at', '<=', $endDateTime);
        }

        // Count in filtered range using index
        $rangeTotalCount = (clone $baseQuery)->count();

        // Apply cursor pagination if specified
        $cursorQuery = (clone $baseQuery);
        if ($cursor) {
            try {
                $cursorDate = \Carbon\Carbon::parse($cursor);
                $cursorQuery->where('observed_at', '>', $cursorDate);
            } catch (\Throwable $e) {
                // Ignore malformed cursor
            }
        }

        // Fetch limit + 1 to detect whether more data exists in range
        $rawPositions = $cursorQuery->select($columns)
            ->orderBy('observed_at', 'asc')
            ->limit($limit + 1)
            ->get();

        $hasMore = false;
        $nextCursor = null;
        if ($rawPositions->count() > $limit) {
            $hasMore = true;
            $rawPositions = $rawPositions->slice(0, $limit);
            $lastItem = $rawPositions->last();
            $nextCursor = $lastItem->observed_at ? $lastItem->observed_at->toIso8601String() : null;
        }

        // Sanitize and deduplicate timestamps
        $validPositions = [];
        $seenTimestamps = [];

        foreach ($rawPositions as $pos) {
            $lat = (float) $pos->latitude;
            $lng = (float) $pos->longitude;

            if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || ($lat === 0.0 && $lng === 0.0)) {
                continue;
            }

            $timeKey = $pos->observed_at ? $pos->observed_at->getTimestamp() : $pos->id;
            if (isset($seenTimestamps[$timeKey])) {
                $prevIndex = $seenTimestamps[$timeKey];
                $validPositions[$prevIndex] = $pos;
                continue;
            }

            $seenTimestamps[$timeKey] = count($validPositions);
            $validPositions[] = $pos;
        }

        $validPositions = array_values($validPositions);
        $count = count($validPositions);

        // Calculate analytical statistics
        $estimatedDistanceNm = 0.0;
        $validSpeeds = [];
        $gapCount = 0;
        $maxGapSeconds = 4 * 3600; // 4 hours

        for ($i = 0; $i < $count; $i++) {
            $cur = $validPositions[$i];
            $spd = $cur->speed_knots !== null ? (float) $cur->speed_knots : null;
            if ($spd !== null && is_finite($spd) && $spd >= 0.0 && $spd <= 102.0) {
                $validSpeeds[] = $spd;
            }

            if ($i === 0) {
                continue;
            }

            $prev = $validPositions[$i - 1];
            $timeGap = ($cur->observed_at && $prev->observed_at)
                ? abs($cur->observed_at->getTimestamp() - $prev->observed_at->getTimestamp())
                : 0;

            $dLat = abs((float) $cur->latitude - (float) $prev->latitude);
            $dLng = abs((float) $cur->longitude - (float) $prev->longitude);

            if ($timeGap > $maxGapSeconds || $dLat > 2.0 || $dLng > 2.0 || $cur->is_gap_indicator) {
                $gapCount++;
            } else {
                $dist = $this->haversineDistanceNm(
                    (float) $prev->latitude,
                    (float) $prev->longitude,
                    (float) $cur->latitude,
                    (float) $cur->longitude
                );
                if (is_finite($dist) && $dist > 0.0 && $dist < 500.0) {
                    $estimatedDistanceNm += $dist;
                }
            }
        }

        $avgSpeed = !empty($validSpeeds) ? round(array_sum($validSpeeds) / count($validSpeeds), 1) : null;
        $maxSpeed = !empty($validSpeeds) ? round(max($validSpeeds), 1) : null;
        $firstObs = $count > 0 ? $validPositions[0]->observed_at?->toIso8601String() : null;
        $lastObs = $count > 0 ? $validPositions[$count - 1]->observed_at?->toIso8601String() : null;

        $features = [];

        // Segment tracks to avoid connecting across significant gaps (> 4 hours or large distance jumps)
        $segments = [];
        $currentSegment = [];

        foreach ($validPositions as $pos) {
            if (empty($currentSegment)) {
                $currentSegment[] = $pos;
                continue;
            }

            $prevPos = end($currentSegment);
            $timeGap = ($pos->observed_at && $prevPos->observed_at)
                ? abs($pos->observed_at->getTimestamp() - $prevPos->observed_at->getTimestamp())
                : 0;

            $dLat = abs((float) $pos->latitude - (float) $prevPos->latitude);
            $dLng = abs((float) $pos->longitude - (float) $prevPos->longitude);

            if ($timeGap > $maxGapSeconds || ($dLat > 2.0 || $dLng > 2.0) || $pos->is_gap_indicator) {
                if (count($currentSegment) >= 2) {
                    $segments[] = $currentSegment;
                }
                $currentSegment = [$pos];
            } else {
                $currentSegment[] = $pos;
            }
        }
        if (count($currentSegment) >= 2) {
            $segments[] = $currentSegment;
        }

        // Add LineString features for valid segments
        foreach ($segments as $sIdx => $seg) {
            $coordinates = array_map(function ($p) {
                return [(float) $p->longitude, (float) $p->latitude];
            }, $seg);

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => $coordinates,
                ],
                'properties' => [
                    'feature_type' => 'ais_vessel_track_line',
                    'segment_index' => $sIdx,
                    'vessel_id' => $vessel->id,
                    'mmsi' => $vessel->mmsi,
                    'name' => $vessel->name,
                    'point_count' => count($seg),
                    'start_time' => $seg[0]->observed_at?->toIso8601String(),
                    'end_time' => $seg[count($seg) - 1]->observed_at?->toIso8601String(),
                ],
            ];
        }

        // Add individual Point features for each observation along the track
        foreach ($validPositions as $idx => $p) {
            $isStart = ($idx === 0);
            $isLatest = ($idx === $count - 1);

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $p->longitude, (float) $p->latitude],
                ],
                'properties' => [
                    'feature_type' => 'ais_track_point',
                    'position_id' => $p->id,
                    'vessel_id' => $vessel->id,
                    'mmsi' => $vessel->mmsi,
                    'name' => $vessel->name,
                    'latitude' => (float) $p->latitude,
                    'longitude' => (float) $p->longitude,
                    'observed_at' => $p->observed_at?->toIso8601String(),
                    'speed_knots' => $p->speed_knots !== null && is_finite((float) $p->speed_knots) ? round((float) $p->speed_knots, 1) : null,
                    'course_degrees' => $p->course_degrees !== null && is_finite((float) $p->course_degrees) ? round((float) $p->course_degrees, 1) : null,
                    'heading_degrees' => $p->heading_degrees !== null && is_finite((float) $p->heading_degrees) ? round((float) $p->heading_degrees, 1) : null,
                    'nav_status' => $p->nav_status,
                    'is_start' => $isStart,
                    'is_latest' => $isLatest,
                    'is_gap' => (bool) $p->is_gap_indicator,
                    'is_fresh' => $p->isFresh(),
                ],
            ];
        }

        $allTimeEarliest = $allTimeBounds?->earliest_obs ? \Carbon\Carbon::parse($allTimeBounds->earliest_obs)->toIso8601String() : null;
        $allTimeLatest = $allTimeBounds?->latest_obs ? \Carbon\Carbon::parse($allTimeBounds->latest_obs)->toIso8601String() : null;
        $allTimeTotal = (int) ($allTimeBounds?->total_count ?? 0);

        $result = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'properties' => [
                'vessel_id' => $vessel->id,
                'mmsi' => $vessel->mmsi,
                'name' => $vessel->name,
                'vessel_type' => $vessel->vessel_type,
                'flag_code' => $vessel->flag_code,
                'position_count' => $count,
                'total_in_range' => $rangeTotalCount,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
                'time_range' => $rangeStr,
                'start_at' => $startDateTime?->toIso8601String(),
                'end_at' => $endDateTime?->toIso8601String(),
                'track_status' => $count >= 2 ? 'available' : ($count === 1 ? 'single_point' : 'empty'),
                'message' => $count === 0
                    ? ($rangeTotalCount === 0
                        ? "No AIS positions recorded within requested period ({$rangeStr})." . ($allTimeTotal > 0 ? " Total history available from {$allTimeEarliest} to {$allTimeLatest}." : '')
                        : "No positions returned.")
                    : "Historical AIS track loaded ({$count} points).",
                'statistics' => [
                    'vessel_name' => $vessel->name,
                    'mmsi' => $vessel->mmsi,
                    'vessel_type' => $vessel->vessel_type,
                    'first_recorded_position' => $count > 0 ? [
                        'latitude' => (float) $validPositions[0]->latitude,
                        'longitude' => (float) $validPositions[0]->longitude,
                        'observed_at' => $firstObs,
                    ] : null,
                    'last_recorded_position' => $count > 0 ? [
                        'latitude' => (float) $validPositions[$count - 1]->latitude,
                        'longitude' => (float) $validPositions[$count - 1]->longitude,
                        'observed_at' => $lastObs,
                    ] : null,
                    'total_positions_range' => $rangeTotalCount,
                    'points_loaded' => $count,
                    'estimated_distance_nm' => round($estimatedDistanceNm, 1),
                    'estimated_distance_km' => round($estimatedDistanceNm * 1.852, 1),
                    'average_speed_knots' => $avgSpeed,
                    'maximum_speed_knots' => $maxSpeed,
                    'first_observation' => $firstObs,
                    'last_observation' => $lastObs,
                    'gap_count' => $gapCount,
                    'all_time_first_observation' => $allTimeEarliest,
                    'all_time_last_observation' => $allTimeLatest,
                    'all_time_total_positions' => $allTimeTotal,
                ],
            ],
        ];

        try {
            Cache::put($cacheKey, $result, $cacheTtlSeconds);
        } catch (\Throwable $e) {
            // Ignore cache storage errors
        }

        return $result;
    }
}
