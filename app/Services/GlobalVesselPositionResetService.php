<?php

namespace App\Services;

use App\Models\GlobalLandPolygon;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GlobalVesselPositionResetService
{
    public const DEFAULT_STALE_HOURS = 24;

    public function __construct(
        protected GlobalLandValidationService $landValidator
    ) {}

    /**
     * Inspect fleet-wide vessel positions and calculate data quality metrics.
     *
     * @param int $staleHours
     * @return array
     */
    public function inspect(int $staleHours = self::DEFAULT_STALE_HOURS): array
    {
        $startTime = microtime(true);
        $staleThreshold = now()->subHours($staleHours);
        $driver = DB::connection()->getDriverName();

        $totalVessels = Vessel::count();
        $totalPositions = VesselPosition::count();

        // 1. Coordinates at Null Island (0, 0)
        $nullIslandPositions = VesselPosition::where('latitude', 0.0)
            ->where('longitude', 0.0)
            ->count();

        // 2. Out of bounds coordinates
        $outOfBoundsPositions = VesselPosition::where(function ($q) {
            $q->where('latitude', '<', -90.0)
              ->orWhere('latitude', '>', 90.0)
              ->orWhere('longitude', '<', -180.0)
              ->orWhere('longitude', '>', 180.0);
        })->count();

        // 3. Land positions
        $landPositions = 0;
        if ($driver === 'pgsql' && $this->landValidator->isTablePopulated()) {
            $landPositions = (int) DB::selectOne("
                SELECT count(*) as cnt
                FROM vessel_positions vp
                JOIN global_land_polygons glp ON ST_Covers(glp.geom, vp.geom)
                WHERE vp.geom IS NOT NULL
            ")?->cnt;
        } else {
            // Count already flagged or fallback
            $landPositions = VesselPosition::where('is_on_land', true)->count();
        }

        // 4. Valid sea positions
        $validSeaPositionsQuery = VesselPosition::query()
            ->where('is_on_land', false)
            ->where(function ($q) {
                $q->whereNull('data_quality_score')
                  ->orWhere('data_quality_score', '>', 0.0);
            })
            ->whereRaw('NOT (latitude = 0.0 AND longitude = 0.0)')
            ->whereBetween('latitude', [-90.0, 90.0])
            ->whereBetween('longitude', [-180.0, 180.0]);

        $validSeaPositions = (clone $validSeaPositionsQuery)->count();
        $freshPositions = (clone $validSeaPositionsQuery)->where('observed_at', '>=', $staleThreshold)->count();
        $stalePositions = (clone $validSeaPositionsQuery)->where('observed_at', '<', $staleThreshold)->count();

        // 5. Vessels with valid sea positions vs vessels with no valid sea positions
        $vesselsWithSeaPositions = (clone $validSeaPositionsQuery)
            ->distinct('vessel_id')
            ->count('vessel_id');

        $vesselsWithoutSeaPositions = max(0, $totalVessels - $vesselsWithSeaPositions);

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'fleet_summary' => [
                'total_vessels' => $totalVessels,
                'total_positions' => $totalPositions,
                'vessels_with_valid_sea_position' => $vesselsWithSeaPositions,
                'vessels_without_valid_sea_position' => $vesselsWithoutSeaPositions,
            ],
            'coordinates_breakdown' => [
                'valid_sea_positions' => $validSeaPositions,
                'fresh_positions' => $freshPositions,
                'stale_positions' => $stalePositions,
                'land_positions' => $landPositions,
                'null_island_positions' => $nullIslandPositions,
                'out_of_bounds_positions' => $outOfBoundsPositions,
            ],
            'land_dataset_ready' => $this->landValidator->isTablePopulated(),
            'stale_hours_threshold' => $staleHours,
            'inspection_duration_sec' => $duration,
        ];
    }

    /**
     * Execute fleet-wide vessel position reset & resynchronization.
     *
     * @param bool $dryRun
     * @param int $staleHours
     * @param int $batchSize
     * @param int|null $adminUserId
     * @return array
     */
    public function execute(
        bool $dryRun = true,
        int $staleHours = self::DEFAULT_STALE_HOURS,
        int $batchSize = 1000,
        ?int $adminUserId = null
    ): array {
        $startTime = microtime(true);
        $inspection = $this->inspect($staleHours);
        $driver = DB::connection()->getDriverName();

        if ($dryRun) {
            return [
                'mode' => 'dry_run',
                'message' => 'Dry-run preview completed. 0 database records were altered or deleted.',
                'inspection' => $inspection,
                'execution_duration_sec' => round(microtime(true) - $startTime, 2),
            ];
        }

        // Apply mode: acquire lock
        $lock = Cache::lock('ais_global_position_reset_lock', 120);
        if (!$lock->get()) {
            return [
                'mode' => 'apply',
                'status' => 'locked',
                'message' => 'Another global position reset operation is currently in progress.',
                'execution_duration_sec' => 0.0,
            ];
        }

        $flaggedLandCount = 0;
        $flaggedInvalidCount = 0;
        $updatedVesselsCount = 0;
        $clearedVesselsCount = 0;

        try {
            DB::transaction(function () use (
                $driver,
                $batchSize,
                &$flaggedLandCount,
                &$flaggedInvalidCount,
                &$updatedVesselsCount,
                &$clearedVesselsCount
            ) {
                // Step 1: Flag Null Island (0,0) and out-of-bounds coordinates
                $flaggedInvalidCount = VesselPosition::where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('latitude', 0.0)->where('longitude', 0.0);
                    })->orWhere('latitude', '<', -90.0)
                      ->orWhere('latitude', '>', 90.0)
                      ->orWhere('longitude', '<', -180.0)
                      ->orWhere('longitude', '>', 180.0);
                })->where(function ($q) {
                    $q->whereNull('data_quality_score')
                      ->orWhere('data_quality_score', '>', 0.0);
                })->update([
                    'data_quality_score' => 0.0,
                ]);

                // Step 2: Flag positions covered by global land polygons
                if ($driver === 'pgsql' && $this->landValidator->isTablePopulated()) {
                    $flaggedLandCount = DB::affectingStatement("
                        UPDATE vessel_positions vp
                        SET is_on_land = true,
                            data_quality_score = 0.0
                        FROM global_land_polygons glp
                        WHERE vp.geom IS NOT NULL
                          AND ST_Covers(glp.geom, vp.geom)
                          AND (vp.is_on_land = false OR vp.data_quality_score > 0.0)
                    ");
                } else {
                    // Fallback chunking with validator
                    VesselPosition::where('is_on_land', false)
                        ->where(function ($q) {
                            $q->whereNull('data_quality_score')
                              ->orWhere('data_quality_score', '>', 0.0);
                        })
                        ->chunkById($batchSize, function ($positions) use (&$flaggedLandCount) {
                            foreach ($positions as $pos) {
                                if ($this->landValidator->isOnLand((float) $pos->latitude, (float) $pos->longitude)) {
                                    $pos->update([
                                        'is_on_land' => true,
                                        'data_quality_score' => 0.0,
                                    ]);
                                    $flaggedLandCount++;
                                }
                            }
                        });
                }

                // Step 3: Reconstruct fleet-wide last_seen_at pointers from newest valid sea positions
                if ($driver === 'pgsql') {
                    // Create temporary table with newest valid sea position per vessel
                    DB::statement("DROP TABLE IF EXISTS temp_latest_sea_positions;");
                    DB::statement("
                        CREATE TEMP TABLE temp_latest_sea_positions AS
                        SELECT DISTINCT ON (vessel_id) vessel_id, observed_at
                        FROM vessel_positions
                        WHERE is_on_land = false
                          AND (data_quality_score IS NULL OR data_quality_score > 0.0)
                          AND NOT (latitude = 0.0 AND longitude = 0.0)
                          AND latitude BETWEEN -90.0 AND 90.0
                          AND longitude BETWEEN -180.0 AND 180.0
                        ORDER BY vessel_id, observed_at DESC;
                    ");
                    DB::statement("CREATE INDEX idx_temp_lsp_vid ON temp_latest_sea_positions(vessel_id);");

                    // Update vessels that have valid sea positions
                    $updatedVesselsCount = DB::affectingStatement("
                        UPDATE vessels v
                        SET last_seen_at = t.observed_at
                        FROM temp_latest_sea_positions t
                        WHERE v.id = t.vessel_id
                          AND (v.last_seen_at IS DISTINCT FROM t.observed_at);
                    ");

                    // Clear last_seen_at pointer for vessels with NO valid sea position (suppress marker)
                    $clearedVesselsCount = DB::affectingStatement("
                        UPDATE vessels v
                        SET last_seen_at = NULL
                        WHERE v.last_seen_at IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM temp_latest_sea_positions t WHERE t.vessel_id = v.id
                          );
                    ");

                    DB::statement("DROP TABLE IF EXISTS temp_latest_sea_positions;");
                } else {
                    // Fallback for SQLite / test environments
                    Vessel::chunkById($batchSize, function ($vessels) use (&$updatedVesselsCount, &$clearedVesselsCount) {
                        foreach ($vessels as $vessel) {
                            $latestSeaPos = VesselPosition::where('vessel_id', $vessel->id)
                                ->where('is_on_land', false)
                                ->where(function ($q) {
                                    $q->whereNull('data_quality_score')
                                      ->orWhere('data_quality_score', '>', 0.0);
                                })
                                ->whereRaw('NOT (latitude = 0.0 AND longitude = 0.0)')
                                ->whereBetween('latitude', [-90.0, 90.0])
                                ->whereBetween('longitude', [-180.0, 180.0])
                                ->orderBy('observed_at', 'desc')
                                ->first();

                            if ($latestSeaPos) {
                                if (!$vessel->last_seen_at || $vessel->last_seen_at->ne($latestSeaPos->observed_at)) {
                                    $vessel->update(['last_seen_at' => $latestSeaPos->observed_at]);
                                    $updatedVesselsCount++;
                                }
                            } else {
                                if ($vessel->last_seen_at !== null) {
                                    $vessel->update(['last_seen_at' => null]);
                                    $clearedVesselsCount++;
                                }
                            }
                        }
                    });
                }

                // Step 4: Clear all relevant map and metric caches
                Cache::forget('map_vessels_geojson_cache');
                Cache::forget('vessels_count_metric');
                Cache::forget('vessel_positions_count_metric');
            });

            // Step 5: Log audit trail
            Log::info("GlobalVesselPositionResetService: Completed fleet-wide position reset & synchronization.", [
                'admin_user_id' => $adminUserId,
                'stale_hours' => $staleHours,
                'flagged_land' => $flaggedLandCount,
                'flagged_invalid' => $flaggedInvalidCount,
                'updated_vessels' => $updatedVesselsCount,
                'cleared_vessels' => $clearedVesselsCount,
            ]);

            return [
                'mode' => 'apply',
                'status' => 'completed',
                'message' => 'Fleet-wide vessel position reset & resynchronization successfully applied.',
                'summary' => [
                    'flagged_land_positions' => $flaggedLandCount,
                    'flagged_invalid_positions' => $flaggedInvalidCount,
                    'rebuilt_vessels_pointers' => $updatedVesselsCount,
                    'cleared_vessels_without_sea_position' => $clearedVesselsCount,
                ],
                'post_inspection' => $this->inspect($staleHours),
                'execution_duration_sec' => round(microtime(true) - $startTime, 2),
            ];
        } finally {
            $lock->release();
        }
    }
}

