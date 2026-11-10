<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\EezZone;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IndonesiaVesselPositionSyncService
{
    public const INDONESIAN_EEZ_MRGID = 8492;
    public const DEFAULT_STALE_HOURS = 24;

    public function __construct(
        protected AisStreamAdapter $aisAdapter,
        protected SpatialQueryService $spatialService
    ) {}

    /**
     * Inspect and summarize vessel positions within Indonesian waters.
     *
     * @param int $staleHours
     * @return array
     */
    public function inspect(int $staleHours = self::DEFAULT_STALE_HOURS): array
    {
        $startTime = microtime(true);
        $staleThreshold = now()->subHours($staleHours);

        // 1. Retrieve the authoritative Indonesian EEZ boundary record
        $eez = EezZone::where('mrgid', self::INDONESIAN_EEZ_MRGID)
            ->orWhere('geoname', 'like', '%Indonesia%')
            ->first();

        $eezFound = $eez !== null;
        $driver = DB::connection()->getDriverName();

        // 2. Count total fleet in operational database
        $totalVessels = Vessel::count();
        $totalPositions = VesselPosition::count();

        // 3. Spatially query positions inside Indonesian EEZ
        $indonesiaVesselIds = [];
        $indonesiaPositionsCount = 0;
        $stalePositionsCount = 0;
        $freshPositionsCount = 0;
        $invalidCoordinatesCount = 0;
        $outOfOrderCount = 0;

        if ($eezFound && $driver === 'pgsql') {
            // High-performance spatial indexing query using PostGIS ST_Covers / ST_Within
            $indonesiaPositionsQuery = VesselPosition::from('vessel_positions as vp')
                ->join('eez_zones as ez', function ($join) {
                    $join->where('ez.mrgid', self::INDONESIAN_EEZ_MRGID);
                })
                ->whereRaw('ST_Covers(ez.geom, ST_SetSRID(ST_MakePoint(vp.longitude, vp.latitude), 4326))');

            $indonesiaPositionsCount = (clone $indonesiaPositionsQuery)->count();
            $indonesiaVesselIds = (clone $indonesiaPositionsQuery)->distinct('vp.vessel_id')->pluck('vp.vessel_id')->toArray();

            $freshPositionsCount = (clone $indonesiaPositionsQuery)
                ->where('vp.observed_at', '>=', $staleThreshold)
                ->count();

            $stalePositionsCount = (clone $indonesiaPositionsQuery)
                ->where('vp.observed_at', '<', $staleThreshold)
                ->count();
        } elseif ($eezFound) {
            // SQLite / fallback test driver using bounding box / GeoJSON envelope
            $bbox = $eez->bbox ?: [94.0, -11.5, 141.5, 6.5];
            $minLng = (float) $bbox[0];
            $minLat = (float) $bbox[1];
            $maxLng = (float) $bbox[2];
            $maxLat = (float) $bbox[3];

            $indonesiaPositionsQuery = VesselPosition::whereBetween('latitude', [$minLat, $maxLat])
                ->whereBetween('longitude', [$minLng, $maxLng]);

            $indonesiaPositionsCount = (clone $indonesiaPositionsQuery)->count();
            $indonesiaVesselIds = (clone $indonesiaPositionsQuery)->distinct()->pluck('vessel_id')->toArray();

            $freshPositionsCount = (clone $indonesiaPositionsQuery)
                ->where('observed_at', '>=', $staleThreshold)
                ->count();

            $stalePositionsCount = (clone $indonesiaPositionsQuery)
                ->where('observed_at', '<', $staleThreshold)
                ->count();
        }

        // 4. Identify invalid coordinate records (outside maritime limits or null island)
        $invalidCoordinatesCount = VesselPosition::where(function ($q) {
            $q->where('latitude', '<', -90.0)
              ->orWhere('latitude', '>', 90.0)
              ->orWhere('longitude', '<', -180.0)
              ->orWhere('longitude', '>', 180.0)
              ->orWhere(function ($nullIsland) {
                  $nullIsland->where('latitude', 0.0)->where('longitude', 0.0);
              });
        })->count();

        // 5. Detect vessels whose last_seen_at does not match their latest valid observed_at
        $desyncedVesselsCount = 0;
        if (!empty($indonesiaVesselIds)) {
            $desyncedVesselsCount = Vessel::whereIn('id', $indonesiaVesselIds)
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('vessel_positions')
                        ->whereColumn('vessel_positions.vessel_id', 'vessels.id')
                        ->where(function ($check) {
                            $check->whereRaw('vessels.last_seen_at IS NULL')
                                  ->orWhereRaw('vessel_positions.observed_at > vessels.last_seen_at');
                        });
                })->count();
        }

        // 6. Inspect AIS Ingestion Worker Status
        $workerStatus = $this->getAisWorkerStatus();

        $duration = round(microtime(true) - $startTime, 3);

        return [
            'status' => 'success',
            'eez' => [
                'found' => $eezFound,
                'mrgid' => $eez?->mrgid ?? self::INDONESIAN_EEZ_MRGID,
                'geoname' => $eez?->geoname ?? 'Indonesian Exclusive Economic Zone',
                'pol_type' => $eez?->pol_type ?? '200NM',
            ],
            'fleet_summary' => [
                'total_vessels' => $totalVessels,
                'total_historical_positions' => $totalPositions,
                'vessels_in_indonesia' => count($indonesiaVesselIds),
                'positions_in_indonesia' => $indonesiaPositionsCount,
            ],
            'freshness_analysis' => [
                'stale_hours_threshold' => $staleHours,
                'fresh_positions' => $freshPositionsCount,
                'stale_positions' => $stalePositionsCount,
                'invalid_coordinates' => $invalidCoordinatesCount,
                'desynced_vessels' => $desyncedVesselsCount,
            ],
            'ais_worker' => $workerStatus,
            'execution_duration_sec' => $duration,
        ];
    }

    /**
     * Execute safe position reset and synchronization for Indonesian waters.
     *
     * @param bool $dryRun
     * @param int $staleHours
     * @param bool $rebuildLatest
     * @param int|null $adminUserId
     * @return array
     */
    public function execute(
        bool $dryRun = true,
        int $staleHours = self::DEFAULT_STALE_HOURS,
        bool $rebuildLatest = true,
        ?int $adminUserId = null
    ): array {
        $startTime = microtime(true);
        $inspection = $this->inspect($staleHours);

        if ($dryRun) {
            return [
                'mode' => 'dry_run',
                'applied' => false,
                'message' => 'Dry-run inspection completed successfully. No operational database records were modified.',
                'inspection' => $inspection,
                'execution_duration_sec' => round(microtime(true) - $startTime, 3),
            ];
        }

        // Acquire an atomic lock to prevent concurrent executions
        $lock = Cache::lock('ais_indonesia_position_reset_lock', 60);
        if (!$lock->get()) {
            return [
                'mode' => 'apply',
                'applied' => false,
                'message' => 'Concurrent reset operation detected. Another sync process is currently running.',
                'inspection' => $inspection,
                'execution_duration_sec' => round(microtime(true) - $startTime, 3),
            ];
        }

        try {
            $updatedVesselsCount = 0;
            $invalidPositionsQuarantined = 0;

            DB::transaction(function () use ($inspection, $rebuildLatest, &$updatedVesselsCount, &$invalidPositionsQuarantined) {
                // 1. Quarantining or flagging invalid coordinates without deleting historical records
                // (Preserve raw payload, but set data_quality_score = 0.0 to exclude from active map rendering)
                $invalidPositionsQuarantined = VesselPosition::where(function ($q) {
                    $q->where('latitude', '<', -90.0)
                      ->orWhere('latitude', '>', 90.0)
                      ->orWhere('longitude', '<', -180.0)
                      ->orWhere('longitude', '>', 180.0)
                      ->orWhere(function ($nullIsland) {
                          $nullIsland->where('latitude', 0.0)->where('longitude', 0.0);
                      });
                })->where('data_quality_score', '>', 0.0)
                  ->update(['data_quality_score' => 0.0]);

                // 2. Rebuild latest position pointers if requested
                if ($rebuildLatest) {
                    $driver = DB::connection()->getDriverName();

                    if ($driver === 'pgsql') {
                        // High-speed PostgreSQL set-based CTE update to synchronize last_seen_at with true newest valid position
                        $sql = "
                            WITH latest_positions AS (
                                SELECT DISTINCT ON (vessel_id)
                                    vessel_id,
                                    observed_at
                                FROM vessel_positions
                                WHERE data_quality_score > 0.0
                                ORDER BY vessel_id, observed_at DESC
                            )
                            UPDATE vessels v
                            SET
                                last_seen_at = lp.observed_at,
                                updated_at = NOW()
                            FROM latest_positions lp
                            WHERE v.id = lp.vessel_id
                              AND (v.last_seen_at IS NULL OR v.last_seen_at != lp.observed_at);
                        ";
                        $updatedVesselsCount = DB::affectingStatement($sql);
                    } else {
                        // Chunked Eloquent synchronization for SQLite / test environments
                        Vessel::whereHas('positions', function ($q) {
                            $q->where('data_quality_score', '>', 0.0);
                        })->chunk(200, function ($vessels) use (&$updatedVesselsCount) {
                            foreach ($vessels as $vessel) {
                                $latest = VesselPosition::where('vessel_id', $vessel->id)
                                    ->where('data_quality_score', '>', 0.0)
                                    ->orderBy('observed_at', 'desc')
                                    ->first();

                                if ($latest && (!$vessel->last_seen_at || !$vessel->last_seen_at->equalTo($latest->observed_at))) {
                                    $vessel->update(['last_seen_at' => $latest->observed_at]);
                                    $updatedVesselsCount++;
                                }
                            }
                        });
                    }
                }

                // 3. Invalidate related application layer caches
                Cache::forget('vessel_positions_count_metric');
                Cache::forget('vessels_count_metric');
                Cache::forget('map_vessels_geojson_cache');
            });

            // 4. Record enterprise audit log entry
            AuditLog::log(
                'ais_indonesia_position_reset',
                'vessel_positions',
                'indonesia_waters_resync',
                [
                    'stale_hours_threshold' => $staleHours,
                    'rebuilt_latest' => $rebuildLatest,
                    'updated_vessels_count' => $updatedVesselsCount,
                    'quarantined_invalid_positions' => $invalidPositionsQuarantined,
                    'total_positions_preserved' => $inspection['fleet_summary']['total_historical_positions'],
                    'admin_user_id' => $adminUserId,
                    'executed_at' => now()->toIso8601String(),
                ]
            );

            Log::info("Indonesia Vessel Position Resync applied by User #{$adminUserId}: {$updatedVesselsCount} vessels synchronized, {$invalidPositionsQuarantined} invalid coordinates quarantined, 100% position history preserved.");

            $duration = round(microtime(true) - $startTime, 3);

            return [
                'mode' => 'apply',
                'applied' => true,
                'message' => "Indonesia vessel position resynchronization executed successfully. {$updatedVesselsCount} vessels synchronized with true latest AIS positions. Zero historical records were deleted.",
                'summary' => [
                    'updated_vessels' => $updatedVesselsCount,
                    'quarantined_invalid_positions' => $invalidPositionsQuarantined,
                    'historical_positions_preserved' => $inspection['fleet_summary']['total_historical_positions'],
                    'stale_threshold_hours' => $staleHours,
                ],
                'actions_taken' => [
                    'vessels_last_seen_updated' => $updatedVesselsCount,
                    'invalid_positions_quarantined' => $invalidPositionsQuarantined,
                ],
                'inspection' => $this->inspect($staleHours),
                'execution_duration_sec' => $duration,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Retrieve AIS stream worker and daemon health telemetry.
     */
    public function getAisWorkerStatus(): array
    {
        $isWorkerAlive = (bool) Cache::get('ais_stream_active_worker_alive', false);
        $telemetryStatus = (string) Cache::get('ais_stream_telemetry_status', 'standby');
        $lastConnectedAt = Cache::get('ais_stream_last_connected_at');
        $lastMessageAt = Cache::get('ais_stream_last_message_at');
        $lastPositionAt = Cache::get('ais_stream_last_position_at');

        // Check if there are active positions received in the last 2 hours
        $latestDbPos = VesselPosition::orderBy('observed_at', 'desc')->first();
        $isStreamingDb = $latestDbPos && $latestDbPos->observed_at && $latestDbPos->observed_at->gt(now()->subHours(2));

        $status = 'standby';
        if ($isStreamingDb) {
            $status = 'streaming';
        } elseif ($isWorkerAlive) {
            $status = 'awaiting_telemetry';
        }

        $provider = DataProvider::where('slug', 'ais_stream')->first();

        return [
            'worker_alive' => $isWorkerAlive,
            'status' => $status,
            'telemetry_status' => $telemetryStatus,
            'provider' => 'ais_stream',
            'service_provider_id' => $provider?->id ?? 1,
            'last_connected_at' => $lastConnectedAt,
            'last_message_at' => $lastMessageAt,
            'last_position_at' => $lastPositionAt,
            'latest_db_position_at' => $latestDbPos?->observed_at?->toIso8601String(),
            'counters' => $this->aisAdapter->getMetrics(),
        ];
    }
}

