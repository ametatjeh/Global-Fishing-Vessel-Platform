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
     * Get Live AIS Vessel Positions as GeoJSON FeatureCollection
     * Optimized query using direct position indices and driver-specific distinct selection
     */
    public function getLiveVesselsFeatureCollection(?array $bbox = null, ?string $search = null, int $limit = 200, array $filters = []): array
    {
        $driver = DB::connection()->getDriverName();
        $limit = $limit > 0 ? $limit : 200;

        // Base query on vessel_positions to leverage indexes on lat/lng/observed_at
        // Exclude quarantined or invalid coordinate records (data_quality_score <= 0.0)
        // Strictly exclude default (0, 0) unacquired GPS sentinel positions
        $posQuery = VesselPosition::query()
            ->where(function ($q) {
                $q->whereNull('data_quality_score')
                  ->orWhere('data_quality_score', '>', 0.0);
            })
            ->whereRaw('NOT (latitude = 0.0 AND longitude = 0.0)');

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
            $criteria = FlagStateService::getFilterCriteria($filters['flag']);
            if ($criteria['is_unknown']) {
                $vesselQuery->where(function ($q) {
                    $q->whereNull('flag_state')
                      ->orWhere('flag_state', '')
                      ->orWhereRaw('LOWER(flag_state) IN (?, ?, ?)', ['unknown', 'un', '-']);
                });
            } else {
                $vesselQuery->where(function ($sub) use ($criteria) {
                    $sub->whereIn('flag_state', $criteria['codes']);
                    if (!empty($criteria['mids'])) {
                        $sub->orWhere(function ($midQ) use ($criteria) {
                            $midQ->where(function ($emptyFlag) {
                                $emptyFlag->whereNull('flag_state')
                                    ->orWhere('flag_state', '')
                                    ->orWhereRaw('LOWER(flag_state) IN (?, ?, ?)', ['unknown', 'un', '-']);
                            })->where(function ($prefixQ) use ($criteria) {
                                foreach ($criteria['mids'] as $mid) {
                                    $prefixQ->orWhere('mmsi', 'like', "{$mid}%");
                                }
                            });
                        });
                    }
                });
            }
        }

        if (!empty($filters['vessel_type'])) {
            $vesselFiltersActive = true;
            $vesselQuery->where('vessel_type', strtolower(trim($filters['vessel_type'])));
        }

        if ($vesselFiltersActive) {
            $posQuery->whereIn('vessel_id', $vesselQuery->select('id'));
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
            if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || ($lat === 0.0 && $lng === 0.0)) {
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
                    'enrichment_status' => $vessel->enrichment_status ?? 'not_enriched',
                    'verification_status' => $vessel->verification_status ?? 'unverified',
                    'is_manually_verified' => $vessel->isManuallyVerified(),
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
     * Generate GeoJSON FeatureCollection for a vessel's historical AIS track (Tahap 11).
     *
     * @param Vessel $vessel
     * @param int $limit Maximum historical positions to return (default: 200, max: 1000)
     * @return array GeoJSON FeatureCollection containing LineString and Point features
     */
    public function getVesselTrackFeatureCollection(Vessel $vessel, int $limit = 200, ?string $timeRange = null): array
    {
        $limit = min(max($limit, 10), 2000);

        $query = VesselPosition::where('vessel_id', $vessel->id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [-90.0, 90.0])
            ->whereBetween('longitude', [-180.0, 180.0])
            ->whereRaw('NOT (latitude = 0.0 AND longitude = 0.0)')
            ->where(function ($q) {
                $q->whereNull('data_quality_score')
                  ->orWhere('data_quality_score', '>', 0.0);
            });

        if (!empty($timeRange)) {
            $rangeStr = strtolower(trim($timeRange));
            if ($rangeStr === '1h') {
                $query->where('observed_at', '>=', now()->subHours(1));
            } elseif ($rangeStr === '6h') {
                $query->where('observed_at', '>=', now()->subHours(6));
            } elseif ($rangeStr === '24h') {
                $query->where('observed_at', '>=', now()->subHours(24));
            } elseif ($rangeStr === '7d') {
                $query->where('observed_at', '>=', now()->subDays(7));
            } elseif (is_numeric($rangeStr)) {
                $query->where('observed_at', '>=', now()->subHours((int) $rangeStr));
            }
        }

        $rawPositions = $query->orderBy('observed_at', 'asc')
            ->limit($limit)
            ->get();

        // Sanitize and deduplicate timestamps
        $validPositions = [];
        $seenTimestamps = [];

        foreach ($rawPositions as $pos) {
            $lat = (float) $pos->latitude;
            $lng = (float) $pos->longitude;

            if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || ($lat === 0.0 && $lng === 0.0)) {
                continue;
            }

            // Consistent handling of duplicate timestamps: retain latest position record
            $timeKey = $pos->observed_at ? $pos->observed_at->getTimestamp() : $pos->id;
            if (isset($seenTimestamps[$timeKey])) {
                // Replace previous entry with the most up-to-date position record
                $prevIndex = $seenTimestamps[$timeKey];
                $validPositions[$prevIndex] = $pos;
                continue;
            }

            $seenTimestamps[$timeKey] = count($validPositions);
            $validPositions[] = $pos;
        }

        // Re-index array cleanly
        $validPositions = array_values($validPositions);
        $count = count($validPositions);

        $features = [];

        if ($count === 0) {
            return [
                'type' => 'FeatureCollection',
                'features' => [],
                'properties' => [
                    'vessel_id' => $vessel->id,
                    'mmsi' => $vessel->mmsi,
                    'name' => $vessel->name,
                    'position_count' => 0,
                    'track_status' => 'empty',
                    'message' => 'No historical AIS track available',
                ],
            ];
        }

        // Segment tracks to avoid connecting across significant gaps (> 4 hours or large distance jumps)
        $segments = [];
        $currentSegment = [];
        $maxGapSeconds = 4 * 3600; // 4 hours

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

            if ($timeGap > $maxGapSeconds || ($dLat > 2.0 || $dLng > 2.0)) {
                // Large gap detected: finish current segment and begin new one
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
                    'is_start' => $isStart,
                    'is_latest' => $isLatest,
                    'is_fresh' => $p->isFresh(),
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'properties' => [
                'vessel_id' => $vessel->id,
                'mmsi' => $vessel->mmsi,
                'name' => $vessel->name,
                'position_count' => $count,
                'track_status' => $count >= 2 ? 'available' : 'single_point',
                'message' => $count >= 2 ? 'Historical AIS track available' : 'Single AIS position available (insufficient for track line)',
            ],
        ];
    }
}
