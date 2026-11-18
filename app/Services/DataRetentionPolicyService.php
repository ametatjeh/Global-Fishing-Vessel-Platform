<?php

namespace App\Services;

use App\Models\AisRetentionPolicy;
use App\Models\AisRetentionRun;
use App\Models\AisRetentionSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DataRetentionPolicyService
{
    /**
     * Standard order for displaying vessel type policies.
     */
    protected const ORDERED_TYPES = [
        'fishing',
        'cargo',
        'tanker',
        'passenger',
        'tug',
        'pleasure',
        'other',
        'unknown',
    ];

    /**
     * Cache key for retention statistics.
     */
    public const CACHE_KEY_STATS = 'ais_retention_storage_stats';
    public const CACHE_KEY_ENCOUNTER_STATS = 'ais_retention_encounter_stats';

    /**
     * Cache TTL in seconds (1 hour).
     */
    public const CACHE_TTL_SECONDS = 3600;

    /**
     * Get all retention policies ordered with Fishing first.
     */
    public function getPolicies(): Collection
    {
        $policies = AisRetentionPolicy::all()->keyBy('vessel_type');

        $result = collect();
        foreach (self::ORDERED_TYPES as $type) {
            if ($policies->has($type)) {
                $result->push($policies->get($type));
            }
        }

        // Add any remaining types that weren't in the standard order
        foreach ($policies as $type => $policy) {
            if (!in_array($type, self::ORDERED_TYPES, true)) {
                $result->push($policy);
            }
        }

        return $result;
    }

    /**
     * Get global retention settings singleton.
     */
    public function getSettings(): AisRetentionSetting
    {
        return AisRetentionSetting::instance();
    }

    /**
     * Update policies and settings with strict immutable protection for Fishing.
     */
    public function updatePoliciesAndSettings(array $policiesData, array $settingsData, User $actor): array
    {
        $oldPolicies = AisRetentionPolicy::all()->toArray();
        $oldSettings = $this->getSettings()->toArray();

        // 1. Process each policy
        foreach ($policiesData as $vesselType => $data) {
            $policy = AisRetentionPolicy::where('vessel_type', $vesselType)->first();
            if (!$policy) {
                continue;
            }

            // CRITICAL RULE: Fishing retention is permanently immutable
            if ($vesselType === 'fishing' || $policy->is_immutable) {
                $policy->retention_days = null;
                $policy->is_immutable = true;
                $policy->is_enabled = true; // Always active
                $policy->save();
                continue;
            }

            $retentionDays = isset($data['retention_days']) && $data['retention_days'] !== ''
                ? max(1, (int) $data['retention_days'])
                : 90;

            $isEnabled = !empty($data['is_enabled']);

            $policy->update([
                'retention_days' => $retentionDays,
                'is_enabled' => $isEnabled,
            ]);
        }

        // 2. Update global settings
        $settings = $this->getSettings();
        $autoCleanupEnabled = !empty($settingsData['auto_cleanup_enabled']);
        $cleanupSchedule = in_array($settingsData['cleanup_schedule'] ?? '', ['daily', 'weekly', 'monthly'], true)
            ? $settingsData['cleanup_schedule']
            : 'daily';
        $cleanupTime = !empty($settingsData['cleanup_time']) ? $settingsData['cleanup_time'] : '03:00';
        $chunkSize = !empty($settingsData['chunk_size']) ? max(100, min(5000, (int) $settingsData['chunk_size'])) : 1000;

        $settings->update([
            'auto_cleanup_enabled' => $autoCleanupEnabled,
            'cleanup_schedule' => $cleanupSchedule,
            'cleanup_time' => $cleanupTime,
            'chunk_size' => $chunkSize,
            'updated_by' => $actor->id,
        ]);

        $newPolicies = AisRetentionPolicy::all()->toArray();
        $newSettings = $settings->fresh()->toArray();

        // Invalidate cached statistics so next view recalculates or reflects new policies
        Cache::forget(self::CACHE_KEY_STATS);

        // 3. Record Audit Log
        AuditLog::log(
            action: 'update',
            entityType: 'ais_retention_policy',
            entityId: (string) $settings->id,
            details: [
                'action_label' => 'Perubahan Kebijakan Retensi Data AIS',
                'actor' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                    'role' => $actor->role,
                ],
                'old_settings' => $oldSettings,
                'new_settings' => $newSettings,
                'changed_at' => now()->toIso8601String(),
            ],
            userId: $actor->id
        );

        return [
            'success' => true,
            'message' => 'AIS data retention policies updated successfully.',
        ];
    }

    /**
     * Calculate comprehensive storage and position statistics without heavy full-table locks.
     * Uses PostgreSQL reltuples catalog estimate on large production tables and caches results.
     */
    public function getStorageStatistics(bool $forceFresh = false): array
    {
        // Check cache first unless explicitly forced
        if (!$forceFresh && Cache::has(self::CACHE_KEY_STATS)) {
            $cached = Cache::get(self::CACHE_KEY_STATS);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $isPgsql = DB::getDriverName() === 'pgsql';
        $isEstimate = false;
        $totalPositions = 0;

        // Fast PostgreSQL statistics estimate for vessel_positions (avoids full table scan on 28GB table)
        if ($isPgsql && !app()->environment('testing')) {
            try {
                $stat = DB::selectOne("SELECT reltuples::bigint as count FROM pg_class WHERE relname = 'vessel_positions'");
                if ($stat && $stat->count !== null && (int) $stat->count >= 0) {
                    $totalPositions = (int) $stat->count;
                    $isEstimate = true;
                }
            } catch (\Throwable $e) {
                Log::warning("[Retention Stats] Fast reltuples query failed, falling back to count(): {$e->getMessage()}");
            }
        }

        // Fallback for testing or when reltuples is unavailable
        if ($totalPositions <= 0) {
            $totalPositions = DB::table('vessel_positions')->count();
            $isEstimate = false;
        }

        // Fast vessel count grouped by vessel_type from vessels table (~38k rows, very fast < 15ms)
        $vesselsByType = DB::table('vessels')
            ->select('vessel_type', DB::raw('count(*) as count'))
            ->groupBy('vessel_type')
            ->pluck('count', 'vessel_type')
            ->toArray();

        $totalVessels = max(1, array_sum($vesselsByType));

        // In testing or small local environments, compute exact position breakdown
        if (app()->environment('testing') || $totalPositions < 100000) {
            $positionsByTypeQuery = DB::table('vessel_positions')
                ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                ->select('vessels.vessel_type', DB::raw('count(*) as count'))
                ->groupBy('vessels.vessel_type')
                ->pluck('count', 'vessel_type')
                ->toArray();

            $fishingPositions = $positionsByTypeQuery['fishing'] ?? 0;
            $unknownPositions = DB::table('vessel_positions')
                ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                ->where(function ($q) {
                    $q->where('vessels.vessel_type', 'unknown')
                      ->orWhereNull('vessels.vessel_type');
                })
                ->count();
        } else {
            // In large production database, estimate position volume proportionally from vessel fleet counts
            // to eliminate catastrophic 28GB hash joins on HTTP page loads
            $fishingRatio = ($vesselsByType['fishing'] ?? 0) / $totalVessels;
            $unknownRatio = (($vesselsByType['unknown'] ?? 0) + ($vesselsByType[''] ?? 0)) / $totalVessels;

            $fishingPositions = (int) round($totalPositions * $fishingRatio);
            $unknownPositions = (int) round($totalPositions * $unknownRatio);
            $positionsByTypeQuery = [];
            foreach ($vesselsByType as $type => $count) {
                $positionsByTypeQuery[$type] = (int) round($totalPositions * ($count / $totalVessels));
            }
        }

        // Non-fishing count
        $nonFishingPositions = max(0, $totalPositions - $fishingPositions);

        // Percentages
        $fishingPct = $totalPositions > 0 ? round(($fishingPositions / $totalPositions) * 100, 1) : 0;
        $nonFishingPct = $totalPositions > 0 ? round(($nonFishingPositions / $totalPositions) * 100, 1) : 0;
        $unknownPct = $totalPositions > 0 ? round(($unknownPositions / $totalPositions) * 100, 1) : 0;

        // Oldest and newest records via index (B-Tree index on observed_at, executes in < 1ms)
        $oldest = DB::table('vessel_positions')->orderBy('observed_at', 'asc')->value('observed_at');
        $newest = DB::table('vessel_positions')->orderBy('observed_at', 'desc')->value('observed_at');

        // Database sizes
        $dbSize = 'Unknown';
        $positionsTableBytes = 0;
        $positionsTableSize = '0 B';

        if ($isPgsql) {
            try {
                $dbSizeRow = DB::selectOne('SELECT pg_size_pretty(pg_database_size(current_database())) as size');
                if ($dbSizeRow) {
                    $dbSize = $dbSizeRow->size;
                }

                $tableBytesRow = DB::selectOne("SELECT pg_total_relation_size('vessel_positions') as bytes, pg_size_pretty(pg_total_relation_size('vessel_positions')) as size");
                if ($tableBytesRow) {
                    $positionsTableBytes = (int) $tableBytesRow->bytes;
                    $positionsTableSize = $tableBytesRow->size;
                }
            } catch (\Throwable $e) {
                $positionsTableBytes = $totalPositions * 128;
                $positionsTableSize = $this->formatBytes($positionsTableBytes);
            }
        } else {
            $dbSize = '120 MB (SQLite Test)';
            $positionsTableBytes = $totalPositions * 128;
            $positionsTableSize = $this->formatBytes($positionsTableBytes);
        }

        // Average row size
        $avgRowBytes = $totalPositions > 0 ? ($positionsTableBytes / $totalPositions) : 128;

        // Size estimate per vessel type
        $breakdown = [];
        $policies = $this->getPolicies()->keyBy('vessel_type');

        foreach (self::ORDERED_TYPES as $type) {
            $count = $positionsByTypeQuery[$type] ?? 0;
            if ($type === 'unknown') {
                $count = $unknownPositions;
            } elseif ($type === 'other') {
                $standardKeys = ['fishing', 'cargo', 'tanker', 'passenger', 'tug', 'pleasure', 'unknown'];
                $count = 0;
                foreach ($positionsByTypeQuery as $k => $c) {
                    if (!in_array($k, $standardKeys, true)) {
                        $count += $c;
                    }
                }
            }

            $policy = $policies->get($type);
            $estimatedBytes = $count * $avgRowBytes;

            $breakdown[$type] = [
                'type' => $type,
                'label' => $policy ? $policy->label : ucfirst($type),
                'count' => $count,
                'percentage' => $totalPositions > 0 ? round(($count / $totalPositions) * 100, 1) : 0,
                'retention_days' => $policy?->retention_days,
                'is_immutable' => $policy?->is_immutable ?? ($type === 'fishing'),
                'is_enabled' => $policy?->is_enabled ?? true,
                'estimated_size' => $this->formatBytes($estimatedBytes),
            ];
        }

        // Candidate count: in testing compute directly; in production, pull from latest completed run or simulation
        $latestCompletedRun = AisRetentionRun::where('status', 'completed')->latest()->first();
        if (app()->environment('testing') || !$latestCompletedRun) {
            $candidates = $this->getCandidatePositionsQuery();
            $candidateCount = $candidates['count'];
        } else {
            $candidateCount = $latestCompletedRun->is_dry_run
                ? ($latestCompletedRun->summary['total_candidate_positions'] ?? 0)
                : $latestCompletedRun->total_records_purged;
        }

        // Growth projection: estimate daily ingestion based on existing record span
        $projectedMonthlyGrowthBytes = 0;
        if ($oldest && $newest && $totalPositions > 0) {
            $oldestTime = Carbon::parse($oldest);
            $newestTime = Carbon::parse($newest);
            $spanDays = max(1, $oldestTime->diffInDays($newestTime));
            $dailyRate = $totalPositions / $spanDays;
            $projectedMonthlyGrowthBytes = $dailyRate * 30 * $avgRowBytes;
        }

        $stats = [
            'total_positions' => $totalPositions,
            'is_estimate' => $isEstimate,
            'fishing_positions' => $fishingPositions,
            'fishing_percentage' => $fishingPct,
            'non_fishing_positions' => $nonFishingPositions,
            'non_fishing_percentage' => $nonFishingPct,
            'unknown_positions' => $unknownPositions,
            'unknown_percentage' => $unknownPct,
            'database_size' => $dbSize,
            'positions_table_size' => $positionsTableSize,
            'positions_table_bytes' => $positionsTableBytes,
            'avg_row_bytes' => round($avgRowBytes, 1),
            'oldest_record' => $oldest,
            'newest_record' => $newest,
            'projected_monthly_growth' => $this->formatBytes($projectedMonthlyGrowthBytes),
            'breakdown' => $breakdown,
            'candidate_purge_count' => $candidateCount,
            'candidate_estimated_freed' => $this->formatBytes($candidateCount * $avgRowBytes),
            'calculated_at' => now()->toIso8601String(),
        ];

        // Store in cache for 1 hour
        Cache::put(self::CACHE_KEY_STATS, $stats, self::CACHE_TTL_SECONDS);

        return $stats;
    }

    /**
     * Retrieve all vessel IDs that have operational evidence and must NEVER be deleted.
     * Enforces UUID string validation to prevent PostgreSQL syntax errors on UUID casts.
     */
    public function getProtectedOperationalVesselIds(): array
    {
        $ids = [];

        // 1. Encounters
        if (Schema::hasTable('vessel_encounters')) {
            $v1 = DB::table('vessel_encounters')->whereNotNull('vessel_1_id')->pluck('vessel_1_id')->toArray();
            $v2 = DB::table('vessel_encounters')->whereNotNull('vessel_2_id')->pluck('vessel_2_id')->toArray();
            $ids = array_merge($ids, $v1, $v2);
        }

        if (Schema::hasTable('encounter_vessels')) {
            $ev = DB::table('encounter_vessels')->whereNotNull('vessel_id')->pluck('vessel_id')->toArray();
            $ids = array_merge($ids, $ev);
        }

        // 2. SAR Satellite Detections & Dark Vessel Correlated Events
        if (Schema::hasTable('satellite_detections')) {
            $sar = DB::table('satellite_detections')->whereNotNull('correlated_vessel_id')->pluck('correlated_vessel_id')->toArray();
            $ids = array_merge($ids, $sar);
        }

        // 3. Active & Closed Investigations
        if (Schema::hasTable('investigations')) {
            $inv = DB::table('investigations')->whereNotNull('target_vessel_id')->pluck('target_vessel_id')->toArray();
            $ids = array_merge($ids, $inv);
        }

        // 4. Compliance Events
        if (Schema::hasTable('compliance_events')) {
            $comp = DB::table('compliance_events')->whereNotNull('vessel_id')->pluck('vessel_id')->toArray();
            $ids = array_merge($ids, $comp);
        }

        // 5. Audit Logs for Vessel Entities (UUID filtered)
        if (Schema::hasTable('audit_logs')) {
            $audit = DB::table('audit_logs')
                ->where('entity_type', 'vessel')
                ->whereNotNull('entity_id')
                ->pluck('entity_id')
                ->toArray();
            $ids = array_merge($ids, $audit);
        }

        // 6. Dark Vessel / Gap Indicator Positions
        if (Schema::hasTable('vessel_positions')) {
            $gapVessels = DB::table('vessel_positions')
                ->where('is_gap_indicator', true)
                ->distinct()
                ->pluck('vessel_id')
                ->toArray();
            $ids = array_merge($ids, $gapVessels);
        }

        // Strict sanitation: remove nulls, empties, non-strings, and validate UUID format when applicable
        $filtered = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            $strId = trim((string) $id);
            if ($strId === '') {
                continue;
            }
            if (\Illuminate\Support\Str::isUuid($strId) || ctype_digit($strId)) {
                $filtered[] = $strId;
            }
        }

        return array_values($filtered);
    }

    /**
     * Retrieve all vessel IDs under Protective Hold.
     */
    public function getProtectiveHoldVesselIds(): array
    {
        return Vessel::underProtectiveHold()->pluck('id')->toArray();
    }

    /**
     * Retrieve all vessel IDs that are Verified Fishing.
     */
    public function getVerifiedFishingVesselIds(): array
    {
        return Vessel::verifiedFishing()->pluck('id')->toArray();
    }

    /**
     * Retrieve all vessel IDs that must be completely excluded from retention purge.
     */
    public function getAllProtectedVesselIds(): array
    {
        $evidence = $this->getProtectedOperationalVesselIds();
        $protectiveHold = $this->getProtectiveHoldVesselIds();
        $verifiedFishing = $this->getVerifiedFishingVesselIds();
        $allFishing = DB::table('vessels')
            ->whereRaw("LOWER(TRIM(COALESCE(vessel_type, ''))) = 'fishing'")
            ->pluck('id')
            ->toArray();

        return array_values(array_unique(array_filter(array_merge(
            $evidence,
            $protectiveHold,
            $verifiedFishing,
            $allFishing
        ))));
    }

    /**
     * Query candidate positions eligible for purge based on retention policy.
     * Fishing positions, protective holds, and operational evidence are strictly excluded.
     */
    public function getCandidatePositionsQuery(): array
    {
        $allProtectedVesselIds = $this->getAllProtectedVesselIds();
        $operationalEvidenceIds = $this->getProtectedOperationalVesselIds();
        $protectiveHoldIds = $this->getProtectiveHoldVesselIds();
        $verifiedFishingIds = $this->getVerifiedFishingVesselIds();

        $policies = $this->getPolicies()->keyBy('vessel_type');

        $candidatesByType = [];
        $totalCandidates = 0;
        $totalHeld = 0;
        $standardTypes = ['cargo', 'tanker', 'passenger', 'tug', 'pleasure', 'unknown'];

        foreach ($policies as $type => $policy) {
            // CRITICAL: Fishing is always permanently protected (verified) or in protective hold (unverified)
            if ($type === 'fishing') {
                $verifiedCount = DB::table('vessel_positions')
                    ->whereIn('vessel_id', $verifiedFishingIds)
                    ->count();

                $unverifiedCount = DB::table('vessel_positions')
                    ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                    ->whereRaw("LOWER(TRIM(COALESCE(vessels.vessel_type, ''))) = 'fishing'")
                    ->whereNotIn('vessel_positions.vessel_id', $verifiedFishingIds)
                    ->count();

                $candidatesByType[$type] = [
                    'type' => $type,
                    'label' => $policy->label,
                    'retention_days' => null,
                    'cutoff_date' => null,
                    'candidates' => 0,
                    'held' => $unverifiedCount,
                    'verified_permanent' => $verifiedCount,
                    'hold_reasons' => [
                        'Fishing Permanently Protected (Verified with AIS Code 30 or Analyst Sign-off)',
                        'Protective Hold (Unverified Fishing awaiting verification)',
                    ],
                    'oldest_affected_date' => null,
                    'newest_affected_date' => null,
                    'status' => 'PROTECTED_PERMANENT',
                ];
                $totalHeld += $unverifiedCount;
                continue;
            }

            if (!$policy->is_enabled || empty($policy->retention_days)) {
                $candidatesByType[$type] = [
                    'type' => $type,
                    'label' => $policy->label,
                    'retention_days' => $policy->retention_days,
                    'cutoff_date' => null,
                    'candidates' => 0,
                    'held' => 0,
                    'hold_reasons' => ['Policy is disabled or set to indefinite retention'],
                    'oldest_affected_date' => null,
                    'newest_affected_date' => null,
                    'status' => 'POLICY_DISABLED',
                ];
                continue;
            }

            $retentionDays = (int) $policy->retention_days;
            $cutoffDate = now()->subDays($retentionDays);

            // Candidate Query: strictly excludes all protected vessels, gap indicators, and fishing
            $query = DB::table('vessel_positions')
                ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                ->whereRaw("NOT (LOWER(TRIM(COALESCE(vessels.vessel_type, ''))) = 'fishing')")
                ->where('vessel_positions.observed_at', '<', $cutoffDate)
                ->where('vessel_positions.is_gap_indicator', false);

            if (!empty($allProtectedVesselIds)) {
                $query->whereNotIn('vessel_positions.vessel_id', $allProtectedVesselIds);
            }

            if ($type === 'unknown') {
                $query->where(function ($q) {
                    $q->where('vessels.vessel_type', 'unknown')
                      ->orWhereNull('vessels.vessel_type')
                      ->orWhereRaw("TRIM(vessels.vessel_type) = ''");
                });
            } elseif ($type === 'other') {
                $query->whereNotIn('vessels.vessel_type', array_merge($standardTypes, ['fishing']));
            } else {
                $query->where('vessels.vessel_type', $type);
            }

            $candidateCount = $query->count();
            $dateRange = (clone $query)->selectRaw('min(vessel_positions.observed_at) as oldest, max(vessel_positions.observed_at) as newest')->first();

            // Held query: positions of this type that are past cutoff but held due to safeguards
            $heldQuery = DB::table('vessel_positions')
                ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                ->whereRaw("NOT (LOWER(TRIM(COALESCE(vessels.vessel_type, ''))) = 'fishing')")
                ->where('vessel_positions.observed_at', '<', $cutoffDate)
                ->where(function ($q) use ($allProtectedVesselIds) {
                    if (!empty($allProtectedVesselIds)) {
                        $q->whereIn('vessel_positions.vessel_id', $allProtectedVesselIds);
                    }
                    $q->orWhere('vessel_positions.is_gap_indicator', true);
                });

            if ($type === 'unknown') {
                $heldQuery->where(function ($q) {
                    $q->where('vessels.vessel_type', 'unknown')
                      ->orWhereNull('vessels.vessel_type')
                      ->orWhereRaw("TRIM(vessels.vessel_type) = ''");
                });
            } elseif ($type === 'other') {
                $heldQuery->whereNotIn('vessels.vessel_type', array_merge($standardTypes, ['fishing']));
            } else {
                $heldQuery->where('vessels.vessel_type', $type);
            }

            $heldCount = $heldQuery->count();
            $totalHeld += $heldCount;

            $holdReasons = [];
            if ($heldCount > 0) {
                $holdReasons[] = 'Protected by Operational References (SAR, Encounters, Dark Vessel, Investigations, Audit Logs) or Protective Hold';
            }

            $status = $candidateCount > 0 ? 'ELIGIBLE_FOR_PURGE' : ($heldCount > 0 ? 'PROTECTIVE_HOLD' : 'NO_CANDIDATES');

            $candidatesByType[$type] = [
                'type' => $type,
                'label' => $policy->label,
                'retention_days' => $retentionDays,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'candidates' => $candidateCount,
                'held' => $heldCount,
                'hold_reasons' => $holdReasons,
                'oldest_affected_date' => $dateRange->oldest ?? null,
                'newest_affected_date' => $dateRange->newest ?? null,
                'status' => $status,
            ];

            $totalCandidates += $candidateCount;
        }

        return [
            'count' => $totalCandidates,
            'total_held' => $totalHeld,
            'by_type' => $candidatesByType,
            'protected_vessel_count' => count($allProtectedVesselIds),
            'operational_evidence_count' => count($operationalEvidenceIds),
            'protective_hold_count' => count($protectiveHoldIds),
            'verified_fishing_count' => count($verifiedFishingIds),
        ];
    }

    /**
     * Run simulation (Dry-Run) without touching the database.
     */
    public function simulateRetentionPurge(?AisRetentionRun $run = null): array
    {
        $stats = $this->getStorageStatistics(forceFresh: true);
        $candidateData = $this->getCandidatePositionsQuery();

        // Historical vessel positions count
        $historicalVesselPositions = DB::table('vessel_positions')
            ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
            ->whereIn('vessels.mmsi', Vessel::HISTORICAL_PROTECTED_MMSIS)
            ->count();

        // Dark vessel indicator positions
        $darkVesselPositions = DB::table('vessel_positions')
            ->where('is_gap_indicator', true)
            ->count();

        $summary = [
            'simulated_at' => now()->toIso8601String(),
            'is_dry_run' => true,
            'total_positions' => $stats['total_positions'],
            'fishing_positions_protected' => $stats['fishing_positions'],
            'verified_fishing_positions' => $candidateData['by_type']['fishing']['verified_permanent'] ?? 0,
            'unverified_fishing_positions' => $candidateData['by_type']['fishing']['held'] ?? 0,
            'historical_vessels_protected' => count(Vessel::HISTORICAL_PROTECTED_MMSIS),
            'historical_vessel_positions_protected' => $historicalVesselPositions,
            'dark_vessel_positions_protected' => $darkVesselPositions,
            'protected_evidence_vessels' => $candidateData['operational_evidence_count'],
            'protective_hold_vessels' => $candidateData['protective_hold_count'],
            'total_candidate_positions' => $candidateData['count'],
            'eligible_positions_count' => $candidateData['count'],
            'held_positions_count' => $candidateData['total_held'],
            'unknown_positions' => $stats['unknown_positions'],
            'estimated_freed_bytes' => $candidateData['count'] * $stats['avg_row_bytes'],
            'estimated_freed_size' => $this->formatBytes($candidateData['count'] * $stats['avg_row_bytes']),
            'breakdown' => $candidateData['by_type'],
        ];

        if ($run) {
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'duration_seconds' => round(now()->diffInMilliseconds($run->started_at ?? now()) / 1000, 2),
                'summary' => $summary,
            ]);
        }

        return $summary;
    }

    /**
     * Central execution engine for an AisRetentionRun (supports both Purge and Simulation).
     */
    public function executeRetentionRun(AisRetentionRun $run): array
    {
        $startTime = now();
        $run->update([
            'status' => 'running',
            'started_at' => $startTime,
            'error_message' => null,
        ]);

        if ($run->run_type === 'encounter_purge') {
            return $this->purgeAllVesselEncounters(
                chunkSize: $run->chunk_size ?? 1000,
                userId: $run->initiated_by_user_id,
                run: $run
            );
        }

        if ($run->is_dry_run) {
            return $this->simulateRetentionPurge($run);
        }

        return $this->executeRetentionPurge(
            force: true,
            userId: $run->initiated_by_user_id,
            run: $run
        );
    }

    /**
     * Execute retention purge in batches.
     * Strictly protects Fishing vessels (verified permanent & unverified hold) and operational evidence vessels.
     */
    public function executeRetentionPurge(bool $force = false, ?int $userId = null, ?AisRetentionRun $run = null): array
    {
        $settings = $this->getSettings();

        if (!$settings->auto_cleanup_enabled && !$force) {
            if ($run) {
                $run->update([
                    'status' => 'cancelled',
                    'error_message' => 'Automatic cleanup is disabled in System Settings.',
                    'completed_at' => now(),
                ]);
            }
            return [
                'success' => false,
                'message' => 'Automatic cleanup dinonaktifkan dalam System Settings. Gunakan opsi force atau aktifkan automatic cleanup terlebih dahulu.',
                'records_purged' => 0,
            ];
        }

        $allProtectedVesselIds = $this->getAllProtectedVesselIds();
        $policies = $this->getPolicies()->keyBy('vessel_type');
        $standardTypes = ['cargo', 'tanker', 'passenger', 'tug', 'pleasure', 'unknown'];

        $purgedByType = [];
        $totalPurged = 0;
        $batchCount = 0;
        $batchSize = $run?->chunk_size ?? ($settings->chunk_size > 0 ? $settings->chunk_size : 1000);

        $startTime = microtime(true);

        foreach ($policies as $type => $policy) {
            // Strictly exclude fishing & immutable policies
            if ($type === 'fishing' || $policy->is_immutable || !$policy->is_enabled || empty($policy->retention_days)) {
                continue;
            }

            $retentionDays = (int) $policy->retention_days;
            $cutoffDate = now()->subDays($retentionDays);

            $typePurged = 0;

            do {
                // Check if run was cancelled via UI
                if ($run && $run->fresh()->isCancelled()) {
                    Log::info("[Retention Purge] Run #{$run->id} was cancelled by administrator.");
                    break 2;
                }

                // Query oldest candidate IDs using index on observed_at
                $idQuery = DB::table('vessel_positions')
                    ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                    ->whereRaw("NOT (LOWER(TRIM(COALESCE(vessels.vessel_type, ''))) = 'fishing')")
                    ->where('vessel_positions.observed_at', '<', $cutoffDate)
                    ->where('vessel_positions.is_gap_indicator', false);

                if (!empty($allProtectedVesselIds)) {
                    $idQuery->whereNotIn('vessel_positions.vessel_id', $allProtectedVesselIds);
                }

                if ($type === 'unknown') {
                    $idQuery->where(function ($q) {
                        $q->where('vessels.vessel_type', 'unknown')
                          ->orWhereNull('vessels.vessel_type')
                          ->orWhereRaw("TRIM(vessels.vessel_type) = ''");
                    });
                } elseif ($type === 'other') {
                    $idQuery->whereNotIn('vessels.vessel_type', array_merge($standardTypes, ['fishing']));
                } else {
                    $idQuery->where('vessels.vessel_type', $type);
                }

                $batchIds = $idQuery->limit($batchSize)->pluck('vessel_positions.id')->toArray();

                if (empty($batchIds)) {
                    break;
                }

                // Delete within separate transaction per batch with pre-flight defense-in-depth safeguard
                DB::beginTransaction();
                try {
                    $violation = DB::table('vessel_positions')
                        ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                        ->whereIn('vessel_positions.id', $batchIds)
                        ->where(function ($q) use ($allProtectedVesselIds) {
                            if (!empty($allProtectedVesselIds)) {
                                $q->whereIn('vessel_positions.vessel_id', $allProtectedVesselIds);
                            }
                            $q->orWhereRaw("LOWER(TRIM(COALESCE(vessels.vessel_type, ''))) = 'fishing'")
                              ->orWhere('vessel_positions.is_gap_indicator', true);
                        })
                        ->exists();

                    if ($violation) {
                        throw new \RuntimeException('Integrity safeguard violation: Attempted to delete protected position in purge batch.');
                    }

                    $deleted = DB::table('vessel_positions')->whereIn('id', $batchIds)->delete();
                    DB::commit();

                    $typePurged += $deleted;
                    $totalPurged += $deleted;
                    $batchCount++;

                    // Update live progress on run model
                    if ($run) {
                        $run->update([
                            'total_records_purged' => $totalPurged,
                            'batch_count' => $batchCount,
                            'purged_by_type' => array_merge($purgedByType, [$type => $typePurged]),
                        ]);
                    }

                    // Brief 50ms pause between batches to prevent I/O disk saturation and allow autovacuum / AIS ingestion
                    if (!app()->environment('testing')) {
                        usleep(50000);
                    }
                } catch (\Throwable $e) {
                    DB::rollBack();
                    Log::error("Retention purge transaction failed: {$e->getMessage()}");
                    if ($run) {
                        $run->update([
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                            'completed_at' => now(),
                        ]);
                    }
                    throw $e;
                }

            } while (count($batchIds) === $batchSize);

            $purgedByType[$type] = $typePurged;
        }

        $duration = round(microtime(true) - $startTime, 2);

        // Update settings state
        $summary = [
            'executed_at' => now()->toIso8601String(),
            'total_purged' => $totalPurged,
            'batch_count' => $batchCount,
            'purged_by_type' => $purgedByType,
            'protected_evidence_vessels' => count($allProtectedVesselIds),
            'fishing_preserved' => true,
            'duration_seconds' => $duration,
        ];

        $settings->update([
            'last_cleanup_at' => now(),
            'last_cleanup_records_purged' => $totalPurged,
            'last_cleanup_status' => 'SUCCESS',
            'last_cleanup_summary' => $summary,
        ]);

        if ($run) {
            $finalStatus = $run->fresh()->isCancelled() ? 'cancelled' : 'completed';
            $run->update([
                'status' => $finalStatus,
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'purged_by_type' => $purgedByType,
                'summary' => $summary,
            ]);
        }

        // Invalidate stats cache so UI sees fresh metrics
        Cache::forget(self::CACHE_KEY_STATS);

        // Record Audit Log
        AuditLog::log(
            action: 'delete',
            entityType: 'ais_retention_cleanup',
            entityId: (string) ($run?->id ?? $settings->id),
            details: $summary,
            userId: $userId
        );

        Log::info("AIS Data Retention Purge executed. Total positions purged: {$totalPurged} in {$batchCount} batches. Fishing data 100% preserved.");

        return [
            'success' => true,
            'message' => "AIS retention cleanup executed successfully. {$totalPurged} non-fishing position records purged in {$batchCount} batches ({$duration}s).",
            'records_purged' => $totalPurged,
            'summary' => $summary,
        ];
    }

    /**
     * Compute next scheduled run timestamp.
     */
    public function getNextScheduledRun(): ?Carbon
    {
        $settings = $this->getSettings();
        if (!$settings->auto_cleanup_enabled) {
            return null;
        }

        $timeParts = explode(':', $settings->cleanup_time ?: '03:00');
        $hour = (int) ($timeParts[0] ?? 3);
        $minute = (int) ($timeParts[1] ?? 0);

        $next = now()->setTime($hour, $minute, 0);

        if ($settings->cleanup_schedule === 'daily') {
            if ($next->isPast()) {
                $next->addDay();
            }
        } elseif ($settings->cleanup_schedule === 'weekly') {
            // Run on Sunday
            $next = now()->next(Carbon::SUNDAY)->setTime($hour, $minute, 0);
        } elseif ($settings->cleanup_schedule === 'monthly') {
            // Run on the 1st of next month
            $next = now()->addMonth()->startOfMonth()->setTime($hour, $minute, 0);
        }

        return $next;
    }

    /**
     * Format bytes into human readable format.
     */
    public function formatBytes(float|int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * Calculate comprehensive statistics for Vessel Encounter Events.
     */
    public function getEncounterStorageStatistics(bool $forceFresh = false): array
    {
        if (!$forceFresh && Cache::has(self::CACHE_KEY_ENCOUNTER_STATS)) {
            $cached = Cache::get(self::CACHE_KEY_ENCOUNTER_STATS);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if (!Schema::hasTable('vessel_encounters')) {
            return [
                'total_encounters' => 0,
                'total_participants' => 0,
                'candidate' => 0,
                'needs_review' => 0,
                'verified' => 0,
                'rejected' => 0,
                'oldest' => null,
                'newest' => null,
                'is_empty' => true,
            ];
        }

        $totalEncounters = DB::table('vessel_encounters')->count();
        $totalParticipants = Schema::hasTable('encounter_vessels') ? DB::table('encounter_vessels')->count() : 0;

        $statusBreakdown = DB::table('vessel_encounters')
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $oldest = $totalEncounters > 0 ? DB::table('vessel_encounters')->orderBy('start_time', 'asc')->value('start_time') : null;
        $newest = $totalEncounters > 0 ? DB::table('vessel_encounters')->orderBy('start_time', 'desc')->value('start_time') : null;

        $stats = [
            'total_encounters' => $totalEncounters,
            'total_participants' => $totalParticipants,
            'candidate' => $statusBreakdown['candidate'] ?? 0,
            'needs_review' => $statusBreakdown['needs_review'] ?? 0,
            'verified' => $statusBreakdown['verified'] ?? 0,
            'rejected' => $statusBreakdown['rejected'] ?? 0,
            'oldest' => $oldest,
            'newest' => $newest,
            'is_empty' => $totalEncounters === 0,
        ];

        Cache::put(self::CACHE_KEY_ENCOUNTER_STATS, $stats, self::CACHE_TTL_SECONDS);

        return $stats;
    }

    /**
     * Purge all Vessel Encounter Events in safe, throttled batches.
     * Preserves vessels, vessel positions, AIS live tracking, and compliance events structure.
     *
     * @param int $chunkSize
     * @param string|null $userId
     * @param AisRetentionRun|null $run
     * @return array{success: bool, deleted_encounters: int, deleted_participants: int, duration_sec: float, message: string}
     */
    public function purgeAllVesselEncounters(
        int $chunkSize = 1000,
        ?string $userId = null,
        ?AisRetentionRun $run = null
    ): array {
        $startTime = microtime(true);
        $totalDeletedEncounters = 0;
        $totalDeletedParticipants = 0;
        $batchCount = 0;

        if (!Schema::hasTable('vessel_encounters')) {
            return [
                'success' => true,
                'deleted_encounters' => 0,
                'deleted_participants' => 0,
                'duration_sec' => 0,
                'message' => 'Tabel vessel_encounters tidak ditemukan.',
            ];
        }

        // 1. Initial count
        $initialCount = DB::table('vessel_encounters')->count();

        if ($run) {
            $run->update([
                'status' => 'running',
                'started_at' => now(),
                'total_candidates' => $initialCount,
            ]);
        }

        if ($initialCount === 0) {
            if ($run) {
                $run->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'total_records_purged' => 0,
                    'batch_count' => 0,
                    'duration_seconds' => 0,
                    'summary' => ['message' => 'No vessel encounter records to delete (already empty).'],
                ]);
            }
            Cache::forget(self::CACHE_KEY_ENCOUNTER_STATS);
            return [
                'success' => true,
                'deleted_encounters' => 0,
                'deleted_participants' => 0,
                'duration_sec' => 0,
                'message' => 'Tidak ada record Vessel Encounter Events yang perlu dihapus (database sudah kosong).',
            ];
        }

        // 2. Safely nullify foreign keys in compliance_events and investigations
        try {
            if (Schema::hasTable('compliance_events') && Schema::hasColumn('compliance_events', 'related_encounter_id')) {
                DB::table('compliance_events')
                    ->whereNotNull('related_encounter_id')
                    ->update(['related_encounter_id' => null]);
            }

            if (Schema::hasTable('investigations') && Schema::hasColumn('investigations', 'related_encounter_id')) {
                DB::table('investigations')
                    ->whereNotNull('related_encounter_id')
                    ->update(['related_encounter_id' => null]);
            }
        } catch (\Throwable $e) {
            Log::warning("[Encounter Purge] Could not nullify related_encounter_id: " . $e->getMessage());
        }

        // 3. Batched chunk deletion
        $batchSize = max(100, min(5000, $chunkSize));

        while (true) {
            // Check cancellation request
            if ($run && $run->fresh()->status === 'cancelled') {
                break;
            }

            $chunkIds = DB::table('vessel_encounters')
                ->limit($batchSize)
                ->pluck('id')
                ->toArray();

            if (empty($chunkIds)) {
                break;
            }

            $deletedInBatch = DB::transaction(function () use ($chunkIds, &$totalDeletedParticipants) {
                if (Schema::hasTable('encounter_vessels')) {
                    $evDeleted = DB::table('encounter_vessels')
                        ->whereIn('encounter_id', $chunkIds)
                        ->delete();
                    $totalDeletedParticipants += $evDeleted;
                }

                return DB::table('vessel_encounters')
                    ->whereIn('id', $chunkIds)
                    ->delete();
            });

            $totalDeletedEncounters += $deletedInBatch;
            $batchCount++;

            if ($run) {
                $run->update([
                    'total_records_purged' => $totalDeletedEncounters,
                    'batch_count' => $batchCount,
                ]);
            }

            // 20ms pause between batches to protect disk I/O and autovacuum
            usleep(20000);
        }

        $duration = round(microtime(true) - $startTime, 2);

        // Clear statistics cache
        Cache::forget(self::CACHE_KEY_ENCOUNTER_STATS);
        Cache::forget(self::CACHE_KEY_STATS);

        // Audit log
        AuditLog::log(
            'purge_vessel_encounters',
            'vessel_encounter',
            null,
            [
                'deleted_encounters' => $totalDeletedEncounters,
                'deleted_participants' => $totalDeletedParticipants,
                'initial_count' => $initialCount,
                'remaining_count' => DB::table('vessel_encounters')->count(),
                'batch_count' => $batchCount,
                'duration_sec' => $duration,
            ],
            userId: $userId
        );

        if ($run) {
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'total_records_purged' => $totalDeletedEncounters,
                'batch_count' => $batchCount,
                'duration_seconds' => (int) round($duration),
                'summary' => [
                    'deleted_encounters' => $totalDeletedEncounters,
                    'deleted_participants' => $totalDeletedParticipants,
                    'duration_sec' => $duration,
                ],
            ]);
        }

        return [
            'success' => true,
            'deleted_encounters' => $totalDeletedEncounters,
            'deleted_participants' => $totalDeletedParticipants,
            'duration_sec' => $duration,
            'message' => "Berhasil menghapus {$totalDeletedEncounters} Vessel Encounter Events dan {$totalDeletedParticipants} participant records dalam {$batchCount} batch ({$duration}s).",
        ];
    }
}
