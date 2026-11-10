<?php

namespace App\Services;

use App\Models\GlobalLandPolygon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GlobalLandValidationService
{
    protected ?bool $tablePopulated = null;
    protected ?array $geoJsonCache = null;
    protected string $driver;

    public function __construct()
    {
        $this->driver = DB::connection()->getDriverName();
    }

    /**
     * Check if a given coordinate (lat, lng) is on a landmass.
     *
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public function isOnLand(float $latitude, float $longitude): bool
    {
        // 1. Boundary & Sentinel Validation
        if (!is_finite($latitude) || !is_finite($longitude) ||
            $latitude < -90.0 || $latitude > 90.0 ||
            $longitude < -180.0 || $longitude > 180.0 ||
            ($latitude == 0.0 && $longitude == 0.0)) {
            return false; // Invalid or Null Island, handled by coordinate validation
        }

        // 2. PostGIS Native Spatial Evaluation using ST_Covers
        if ($this->driver === 'pgsql' && $this->isTablePopulated()) {
            try {
                $result = DB::selectOne("
                    SELECT 1 
                    FROM global_land_polygons 
                    WHERE geom IS NOT NULL 
                      AND ST_Covers(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326)) 
                    LIMIT 1
                ", [$longitude, $latitude]);

                return $result !== null;
            } catch (\Throwable $e) {
                Log::warning("PostGIS ST_Covers evaluation failed, falling back to GeoJSON parser: " . $e->getMessage());
            }
        }

        // 3. Fallback Point-in-Polygon Engine (e.g. SQLite / Test Environment)
        return $this->pointInPolygonFallback($latitude, $longitude);
    }

    /**
     * Determine if the database has land polygon records.
     */
    public function isTablePopulated(): bool
    {
        if ($this->tablePopulated === null) {
            try {
                $this->tablePopulated = GlobalLandPolygon::exists();
            } catch (\Throwable $e) {
                $this->tablePopulated = false;
            }
        }

        return $this->tablePopulated;
    }

    /**
     * Import official Natural Earth Land dataset into PostGIS database.
     *
     * @param string|null $filePath
     * @param bool $force
     * @return array
     */
    public function importDataset(?string $filePath = null, bool $force = false): array
    {
        $startTime = microtime(true);
        $path = $filePath ?: database_path('seeders/data/ne_50m_land.geojson');

        if (!file_exists($path)) {
            throw new \RuntimeException("Land GeoJSON dataset not found at {$path}. Please download ne_50m_land.geojson first.");
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (!$data || !isset($data['features']) || !is_array($data['features'])) {
            throw new \RuntimeException("Invalid GeoJSON structure in {$path}");
        }

        if ($force) {
            GlobalLandPolygon::truncate();
            $this->tablePopulated = false;
        }

        $features = $data['features'];
        $total = count($features);
        $inserted = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($features as $idx => $feat) {
                $geom = $feat['geometry'] ?? null;
                $props = $feat['properties'] ?? [];

                if (!$geom || empty($geom['coordinates'])) {
                    continue;
                }

                $bbox = $this->calculateBoundingBox($geom);
                $featureName = $props['featurecla'] ?? ($props['name'] ?? "Land-{$idx}");

                $polygon = GlobalLandPolygon::create([
                    'feature_name' => $featureName,
                    'source' => 'natural_earth',
                    'scale' => '50m',
                    'min_lat' => $bbox['min_lat'],
                    'max_lat' => $bbox['max_lat'],
                    'min_lng' => $bbox['min_lng'],
                    'max_lng' => $bbox['max_lng'],
                    'geometry_geojson' => json_encode($geom),
                ]);

                if ($this->driver === 'pgsql') {
                    // Update geometry with ST_MakeValid, ST_CollectionExtract, and ST_Multi into SRID 4326
                    DB::statement("
                        UPDATE global_land_polygons 
                        SET geom = ST_SetSRID(ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(?)), 3)), 4326)
                        WHERE id = ?
                    ", [json_encode($geom), $polygon->id]);
                }

                $inserted++;
            }

            DB::commit();
            $this->tablePopulated = true;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'total_features' => $total,
            'inserted' => $inserted,
            'errors' => $errors,
            'duration_sec' => $duration,
        ];
    }

    /**
     * Compute envelope / bounding box [min_lat, max_lat, min_lng, max_lng] for a geometry.
     */
    protected function calculateBoundingBox(array $geom): array
    {
        $minLat = 90.0;
        $maxLat = -90.0;
        $minLng = 180.0;
        $maxLng = -180.0;

        $type = $geom['type'] ?? '';
        $coords = $geom['coordinates'] ?? [];

        $extractPoints = function ($ring) use (&$minLat, &$maxLat, &$minLng, &$maxLng, &$extractPoints) {
            if (!is_array($ring)) return;
            if (isset($ring[0]) && is_numeric($ring[0]) && isset($ring[1]) && is_numeric($ring[1])) {
                $lng = (float) $ring[0];
                $lat = (float) $ring[1];
                if ($lat < $minLat) $minLat = $lat;
                if ($lat > $maxLat) $maxLat = $lat;
                if ($lng < $minLng) $minLng = $lng;
                if ($lng > $maxLng) $maxLng = $lng;
            } else {
                foreach ($ring as $sub) {
                    $extractPoints($sub);
                }
            }
        };

        $extractPoints($coords);

        return [
            'min_lat' => $minLat <= $maxLat ? $minLat : null,
            'max_lat' => $minLat <= $maxLat ? $maxLat : null,
            'min_lng' => $minLng <= $maxLng ? $minLng : null,
            'max_lng' => $minLng <= $maxLng ? $maxLng : null,
        ];
    }

    /**
     * In-memory point in polygon fallback algorithm.
     */
    protected function pointInPolygonFallback(float $latitude, float $longitude): bool
    {
        if ($this->geoJsonCache === null) {
            $path = database_path('seeders/data/ne_50m_land.geojson');
            if (!file_exists($path)) {
                return false;
            }
            $this->geoJsonCache = json_decode(file_get_contents($path), true)['features'] ?? [];
        }

        foreach ($this->geoJsonCache as $feat) {
            $geom = $feat['geometry'] ?? null;
            if (!$geom) continue;

            $type = $geom['type'] ?? '';
            $coords = $geom['coordinates'] ?? [];

            if ($type === 'Polygon') {
                if (!empty($coords[0]) && $this->pointInLinearRing($longitude, $latitude, $coords[0])) {
                    return true;
                }
            } elseif ($type === 'MultiPolygon') {
                foreach ($coords as $poly) {
                    if (!empty($poly[0]) && $this->pointInLinearRing($longitude, $latitude, $poly[0])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Ray-casting linear ring intersection test.
     */
    protected function pointInLinearRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $ring[$i][0]; $yi = $ring[$i][1];
            $xj = $ring[$j][0]; $yj = $ring[$j][1];
            $intersect = (($yi > $y) != ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        return $inside;
    }
}

