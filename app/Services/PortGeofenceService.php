<?php

namespace App\Services;

use App\Models\Port;
use App\Models\VesselPosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PortGeofenceService
{
    protected ?bool $tablePopulated = null;
    protected string $driver;

    public function __construct(
        protected ?GlobalLandValidationService $landService = null
    ) {
        $this->driver = DB::connection()->getDriverName();
        $this->landService = $landService ?? app(GlobalLandValidationService::class);
    }

    /**
     * Determine if a coordinate falls inside a port or its surrounding exclusion buffer zone.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float|null $customBufferMeters
     * @return array|false Returns port details if inside buffer, false otherwise
     */
    public function isInsidePortOrBuffer(float $latitude, float $longitude, ?float $customBufferMeters = null): array|false
    {
        if (!is_finite($latitude) || !is_finite($longitude) ||
            $latitude < -90.0 || $latitude > 90.0 ||
            $longitude < -180.0 || $longitude > 180.0 ||
            ($latitude == 0.0 && $longitude == 0.0)) {
            return false;
        }

        if (!$this->isTablePopulated()) {
            return false;
        }

        // 1. PostGIS Native Spatial Query using ST_DWithin and geography casting
        if ($this->driver === 'pgsql') {
            try {
                $query = "
                    SELECT id, name, country, port_size, buffer_radius_meters,
                           ROUND(ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)::numeric, 2) AS distance_meters
                    FROM ports
                    WHERE geom IS NOT NULL
                      AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
                    ORDER BY distance_meters ASC
                    LIMIT 1
                ";

                $bufferToUse = $customBufferMeters ?? 15000; // Search window up to 15km
                $result = DB::selectOne($query, [$longitude, $latitude, $longitude, $latitude, $bufferToUse]);

                if ($result) {
                    $threshold = $customBufferMeters ?? (int) $result->buffer_radius_meters;
                    if ((float) $result->distance_meters <= $threshold) {
                        return [
                            'inside' => true,
                            'id' => $result->id,
                            'name' => $result->name,
                            'country' => $result->country,
                            'port_size' => $result->port_size,
                            'buffer_radius_meters' => $threshold,
                            'distance_meters' => (float) $result->distance_meters,
                        ];
                    }
                }

                return false;
            } catch (\Throwable $e) {
                Log::warning("PostGIS ST_DWithin port check failed, using mathematical fallback: " . $e->getMessage());
            }
        }

        // 2. Mathematical Fallback Engine (SQLite / Testing environments)
        return $this->evaluateDistanceFallback($latitude, $longitude, $customBufferMeters);
    }

    /**
     * Verify whether a location is strictly in open sea waters.
     * Open sea requires:
     *   1. Not on land (docks, berths, terminals, coastal reclamation)
     *   2. Not inside any port or port exclusion buffer (anchorage, harbor, approach)
     */
    public function isLocationInOpenSea(float $latitude, float $longitude): array
    {
        if (!is_finite($latitude) || !is_finite($longitude) ||
            $latitude < -90.0 || $latitude > 90.0 ||
            $longitude < -180.0 || $longitude > 180.0 ||
            ($latitude == 0.0 && $longitude == 0.0)) {
            return [
                'open_sea' => false,
                'reason' => 'invalid_coordinates',
                'description' => 'Invalid or Null Island coordinates (0.0, 0.0).',
            ];
        }

        // Check 1: Landmass exclusion (ports, piers, docks, terminals, inland waters)
        if ($this->landService && $this->landService->isOnLand($latitude, $longitude)) {
            return [
                'open_sea' => false,
                'reason' => 'on_land',
                'description' => 'Location falls on landmass or dock / pier infrastructure.',
            ];
        }

        // Check 2: Port & harbor buffer exclusion
        $portCheck = $this->isInsidePortOrBuffer($latitude, $longitude);
        if ($portCheck !== false) {
            return [
                'open_sea' => false,
                'reason' => 'inside_port_buffer',
                'port' => $portCheck,
                'description' => "Location is inside {$portCheck['name']} ({$portCheck['country']}) port exclusion buffer ({$portCheck['distance_meters']}m from port center).",
            ];
        }

        return [
            'open_sea' => true,
            'reason' => null,
            'description' => 'Location is in open sea waters outside all port exclusion zones.',
        ];
    }

    /**
     * Evaluate if two vessel positions can legitimately form an open sea encounter.
     *
     * @param VesselPosition|array $posA
     * @param VesselPosition|array $posB
     * @return array{allowed: bool, reason: string|null, details: mixed}
     */
    public function canFormEncounter(mixed $posA, mixed $posB): array
    {
        $latA = is_array($posA) ? (float) ($posA['latitude'] ?? $posA['lat'] ?? 0) : (float) $posA->latitude;
        $lngA = is_array($posA) ? (float) ($posA['longitude'] ?? $posA['lng'] ?? 0) : (float) $posA->longitude;
        $sogA = is_array($posA) ? ($posA['speed_knots'] ?? $posA['sog'] ?? null) : $posA->speed_knots;
        $navA = is_array($posA) ? ($posA['navigational_status'] ?? $posA['nav_status'] ?? null) : $posA->navigational_status;

        $latB = is_array($posB) ? (float) ($posB['latitude'] ?? $posB['lat'] ?? 0) : (float) $posB->latitude;
        $lngB = is_array($posB) ? (float) ($posB['longitude'] ?? $posB['lng'] ?? 0) : (float) $posB->longitude;
        $sogB = is_array($posB) ? ($posB['speed_knots'] ?? $posB['sog'] ?? null) : $posB->speed_knots;
        $navB = is_array($posB) ? ($posB['navigational_status'] ?? $posB['nav_status'] ?? null) : $posB->navigational_status;

        // 1. Verify Vessel A location is in open sea
        $checkA = $this->isLocationInOpenSea($latA, $lngA);
        if (!$checkA['open_sea']) {
            return [
                'allowed' => false,
                'reason' => 'vessel_1_' . $checkA['reason'],
                'details' => $checkA['description'],
            ];
        }

        // 2. Verify Vessel B location is in open sea
        $checkB = $this->isLocationInOpenSea($latB, $lngB);
        if (!$checkB['open_sea']) {
            return [
                'allowed' => false,
                'reason' => 'vessel_2_' . $checkB['reason'],
                'details' => $checkB['description'],
            ];
        }

        // 3. Verify Midpoint is in open sea
        $midLat = ($latA + $latB) / 2;
        $midLng = ($lngA + $lngB) / 2;
        $checkMid = $this->isLocationInOpenSea($midLat, $midLng);
        if (!$checkMid['open_sea']) {
            return [
                'allowed' => false,
                'reason' => 'midpoint_' . $checkMid['reason'],
                'details' => $checkMid['description'],
            ];
        }

        // 4. Navigational status indicator check (1 = At anchor, 5 = Moored, 6 = Aground)
        $anchoredStatuses = [1, 5, 6, '1', '5', '6', 'at_anchor', 'moored', 'aground'];
        $isAAnchored = in_array(strtolower((string) $navA), $anchoredStatuses, true);
        $isBAnchored = in_array(strtolower((string) $navB), $anchoredStatuses, true);

        if ($isAAnchored && $isBAnchored) {
            return [
                'allowed' => false,
                'reason' => 'anchored_nav_status',
                'details' => 'Both vessels report at anchor or moored navigational status.',
            ];
        }

        // 5. Zero-speed anchorage cluster check: both vessels stationary (< 0.2 knots)
        if ($sogA !== null && $sogB !== null && (float) $sogA < 0.2 && (float) $sogB < 0.2) {
            // Check if within 25 km of any port (extended anchorage grounds)
            $extendedCheckA = $this->isInsidePortOrBuffer($latA, $lngA, 25000);
            if ($extendedCheckA !== false) {
                return [
                    'allowed' => false,
                    'reason' => 'anchorage_drift_stationary',
                    'details' => "Stationary vessels (<0.2 kts) within outer anchorage range of {$extendedCheckA['name']}.",
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'details' => 'Valid open sea encounter candidate.',
        ];
    }

    /**
     * Check if ports table is populated.
     */
    public function isTablePopulated(): bool
    {
        if ($this->tablePopulated === null) {
            try {
                $this->tablePopulated = Schema::hasTable('ports') && Port::exists();
            } catch (\Throwable $e) {
                $this->tablePopulated = false;
            }
        }

        return $this->tablePopulated;
    }

    /**
     * Fallback distance calculation using bounding box filter + Haversine formula.
     */
    protected function evaluateDistanceFallback(float $lat, float $lng, ?float $customBufferMeters): array|false
    {
        $maxBuffer = $customBufferMeters ?? 15000;
        // 1 deg latitude ~ 111,320m
        $deltaLat = $maxBuffer / 111320.0;
        $deltaLng = $maxBuffer / (111320.0 * max(cos(deg2rad($lat)), 0.01));

        $candidates = Port::query()
            ->whereBetween('latitude', [$lat - $deltaLat, $lat + $deltaLat])
            ->whereBetween('longitude', [$lng - $deltaLng, $lng + $deltaLng])
            ->get();

        foreach ($candidates as $port) {
            $dist = $this->haversineDistance($lat, $lng, $port->latitude, $port->longitude);
            $threshold = $customBufferMeters ?? $port->buffer_radius_meters;

            if ($dist <= $threshold) {
                return [
                    'inside' => true,
                    'id' => $port->id,
                    'name' => $port->name,
                    'country' => $port->country,
                    'port_size' => $port->port_size,
                    'buffer_radius_meters' => $threshold,
                    'distance_meters' => round($dist, 2),
                ];
            }
        }

        return false;
    }

    /**
     * Calculate Great-Circle distance in meters between two lat/long points.
     */
    public function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Import official WPI (World Port Index) dataset into ports table.
     *
     * @param string|null $filePath
     * @param bool $force
     * @return array
     */
    public function importDataset(?string $filePath = null, bool $force = false): array
    {
        $startTime = microtime(true);
        $path = $filePath ?: database_path('seeders/data/world_port_index.json');

        if (!file_exists($path)) {
            throw new \RuntimeException("Port dataset not found at {$path}.");
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (!$data || !isset($data['ports']) || !is_array($data['ports'])) {
            throw new \RuntimeException("Invalid WPI JSON structure in {$path}");
        }

        if ($force) {
            Port::truncate();
            $this->tablePopulated = false;
        }

        $portsList = $data['ports'];
        $total = count($portsList);
        $inserted = 0;

        $batch = [];
        $driver = $this->driver;

        DB::beginTransaction();
        try {
            foreach ($portsList as $p) {
                $lat = (float) ($p['latitude'] ?? 0);
                $lng = (float) ($p['longitude'] ?? 0);

                if ($lat == 0.0 && $lng == 0.0) {
                    continue;
                }

                $portSize = $p['port_size'] ?? 'Small';
                $bufferRadius = Port::defaultBufferMetersForSize($portSize);

                $batch[] = [
                    'wpi_port_id' => $p['wpi_port_id'] ?? null,
                    'port_code' => isset($p['wpi_port_id']) ? 'WPI-' . $p['wpi_port_id'] : null,
                    'name' => $p['wpi_port_name'] ?? ($p['point_of_interest'] ?? 'Unknown Port'),
                    'country' => $p['country'] ?? 'Unknown',
                    'state' => $p['state'] ?? null,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'port_size' => $portSize,
                    'buffer_radius_meters' => $bufferRadius,
                    'max_vessel_size' => $p['max_vessel_size'] ?? null,
                    'point_of_interest' => $p['point_of_interest'] ?? null,
                    'metadata' => json_encode([
                        'channel_depth_max_m' => $p['channel_depth_max_m'] ?? null,
                        'anchorage_depth_max_m' => $p['anchorage_depth_max_m'] ?? null,
                        'cargo_pier_depth_max_m' => $p['cargo_pier_depth_max_m'] ?? null,
                        'mean_tidal_range_m' => $p['mean_tidal_range_m'] ?? null,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= 500) {
                    Port::insert($batch);
                    $inserted += count($batch);
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                Port::insert($batch);
                $inserted += count($batch);
            }

            if ($driver === 'pgsql') {
                DB::statement("
                    UPDATE ports 
                    SET geom = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)
                    WHERE geom IS NULL
                ");
            }

            DB::commit();
            $this->tablePopulated = true;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'total_in_file' => $total,
            'inserted' => $inserted,
            'duration_sec' => $duration,
        ];
    }
}
