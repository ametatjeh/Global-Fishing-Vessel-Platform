<?php

namespace App\Services;

use App\Models\AisRetentionPolicy;
use App\Models\AisRetentionSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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

        $settings->update([
            'auto_cleanup_enabled' => $autoCleanupEnabled,
            'cleanup_schedule' => $cleanupSchedule,
            'cleanup_time' => $cleanupTime,
            'updated_by' => $actor->id,
        ]);

        $newPolicies = AisRetentionPolicy::all()->toArray();
        $newSettings = $settings->fresh()->toArray();

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
     * Calculate comprehensive storage and position statistics.
     */
    public function getStorageStatistics(): array
    {
        $totalPositions = DB::table('vessel_positions')->count();

        // Count positions grouped by vessel type
        $positionsByTypeQuery = DB::table('vessel_positions')
            ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
            ->select('vessels.vessel_type', DB::raw('count(*) as count'))
            ->groupBy('vessels.vessel_type')
            ->pluck('count', 'vessel_type')
            ->toArray();

        // Fishing count
        $fishingPositions = $positionsByTypeQuery['fishing'] ?? 0;

        // Unknown count (vessel_type is 'unknown' or null)
        $unknownPositions = DB::table('vessel_positions')
            ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
            ->where(function ($q) {
                $q->where('vessels.vessel_type', 'unknown')
                  ->orWhereNull('vessels.vessel_type');
            })
            ->count();

        // Non-fishing count
        $nonFishingPositions = max(0, $totalPositions - $fishingPositions);

        // Percentages
        $fishingPct = $totalPositions > 0 ? round(($fishingPositions / $totalPositions) * 100, 1) : 0;
        $nonFishingPct = $totalPositions > 0 ? round(($nonFishingPositions / $totalPositions) * 100, 1) : 0;
        $unknownPct = $totalPositions > 0 ? round(($unknownPositions / $totalPositions) * 100, 1) : 0;

        // Oldest and newest records
        $minMax = DB::table('vessel_positions')->selectRaw('min(observed_at) as oldest, max(observed_at) as newest')->first();

        // Database sizes
        $dbSize = 'Unknown';
        $positionsTableBytes = 0;
        $positionsTableSize = '0 B';

        if (DB::getDriverName() === 'pgsql') {
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
                // Sum all other types that are not in standard categories
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

        // Calculate candidate purge count across non-fishing types
        $candidates = $this->getCandidatePositionsQuery();
        $candidateCount = $candidates['count'];

        // Growth projection: estimate daily ingestion based on existing record span
        $projectedMonthlyGrowthBytes = 0;
        if ($minMax && $minMax->oldest && $minMax->newest && $totalPositions > 0) {
            $oldestTime = Carbon::parse($minMax->oldest);
            $newestTime = Carbon::parse($minMax->newest);
            $spanDays = max(1, $oldestTime->diffInDays($newestTime));
            $dailyRate = $totalPositions / $spanDays;
            $projectedMonthlyGrowthBytes = $dailyRate * 30 * $avgRowBytes;
        }

        return [
            'total_positions' => $totalPositions,
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
            'oldest_record' => $minMax->oldest ?? null,
            'newest_record' => $minMax->newest ?? null,
            'projected_monthly_growth' => $this->formatBytes($projectedMonthlyGrowthBytes),
            'breakdown' => $breakdown,
            'candidate_purge_count' => $candidateCount,
            'candidate_estimated_freed' => $this->formatBytes($candidateCount * $avgRowBytes),
        ];
    }

    /**
     * Retrieve all vessel IDs that have operational evidence and must NEVER be deleted.
     * Includes: Encounters, SAR Detections, Dark Vessel / AIS Gap events, Investigations, Compliance Events, Audit Logs.
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

        // 5. Audit Logs for Vessel Entities
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
                ->pluck('vessel_id')
                ->toArray();
            $ids = array_merge($ids, $gapVessels);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Retrieve all vessel IDs under Protective Hold:
     * - The 5 historical unverified vessels
     * - Unverified fishing vessels (lacking legitimate AIS code 30 and analyst verification)
     * - Disputed vessels with conflicting manual vs AIS evidence
     * - Vessels with verification_status in ('under_review', 'unverified', 'unverified_historical', 'disputed')
     */
    public function getProtectiveHoldVesselIds(): array
    {
        return Vessel::underProtectiveHold()->pluck('id')->toArray();
    }

    /**
     * Retrieve all vessel IDs that are Verified Fishing:
     * Strictly synchronizes with Vessel::isVerifiedFishing() via Vessel::verifiedFishing() scope.
     * Enforces non-conflict rule: AIS non-fishing codes (e.g. 70, 80) strictly disqualify from Verified Fishing.
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
        $allFishing = DB::table('vessels')->where('vessel_type', 'fishing')->pluck('id')->toArray();

        return array_values(array_unique(array_filter(array_merge(
            $evidence,
            $protectiveHold,
            $verifiedFishing,
            $allFishing
        ))));
    }

    /**
     * Query candidate positions eligible for purge based on retention policy.
     * Fishing positions (both verified permanent and unverified hold), protective holds, and operational evidence are strictly excluded.
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
                    ->where('vessels.vessel_type', 'fishing')
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

            // Candidate Query: must strictly exclude all protected vessels and gap indicator positions
            $query = DB::table('vessel_positions')
                ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                ->where('vessels.vessel_type', '!=', 'fishing')
                ->where('vessel_positions.observed_at', '<', $cutoffDate)
                ->where('vessel_positions.is_gap_indicator', false);

            if (!empty($allProtectedVesselIds)) {
                $query->whereNotIn('vessel_positions.vessel_id', $allProtectedVesselIds);
            }

            if ($type === 'unknown') {
                $query->where(function ($q) {
                    $q->where('vessels.vessel_type', 'unknown')
                      ->orWhereNull('vessels.vessel_type');
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
                ->where('vessels.vessel_type', '!=', 'fishing')
                ->where('vessel_positions.observed_at', '<', $cutoffDate)
                ->where(function ($q) use ($allProtectedVesselIds) {
                    $q->whereIn('vessel_positions.vessel_id', $allProtectedVesselIds)
                      ->orWhere('vessel_positions.is_gap_indicator', true);
                });

            if ($type === 'unknown') {
                $heldQuery->where(function ($q) {
                    $q->where('vessels.vessel_type', 'unknown')
                      ->orWhereNull('vessels.vessel_type');
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
    public function simulateRetentionPurge(): array
    {
        $stats = $this->getStorageStatistics();
        $candidateData = $this->getCandidatePositionsQuery();

        // Count historical vessel positions
        $historicalVesselPositions = DB::table('vessel_positions')
            ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
            ->whereIn('vessels.mmsi', Vessel::HISTORICAL_PROTECTED_MMSIS)
            ->count();

        // Dark vessel indicator positions
        $darkVesselPositions = DB::table('vessel_positions')
            ->where('is_gap_indicator', true)
            ->count();

        return [
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
    }

    /**
     * Execute retention purge in batches.
     * Strictly protects Fishing vessels (verified permanent & unverified hold) and operational evidence vessels.
     */
    public function executeRetentionPurge(bool $force = false, ?int $userId = null): array
    {
        $settings = $this->getSettings();

        if (!$settings->auto_cleanup_enabled && !$force) {
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
        $batchSize = $settings->chunk_size > 0 ? $settings->chunk_size : 1000;

        foreach ($policies as $type => $policy) {
            // Strictly exclude fishing & immutable policies
            if ($type === 'fishing' || $policy->is_immutable || !$policy->is_enabled || empty($policy->retention_days)) {
                continue;
            }

            $retentionDays = (int) $policy->retention_days;
            $cutoffDate = now()->subDays($retentionDays);

            $typePurged = 0;

            do {
                // Find candidate IDs in batches
                $idQuery = DB::table('vessel_positions')
                    ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                    ->where('vessels.vessel_type', '!=', 'fishing')
                    ->where('vessel_positions.observed_at', '<', $cutoffDate)
                    ->where('vessel_positions.is_gap_indicator', false);

                if (!empty($allProtectedVesselIds)) {
                    $idQuery->whereNotIn('vessel_positions.vessel_id', $allProtectedVesselIds);
                }

                if ($type === 'unknown') {
                    $idQuery->where(function ($q) {
                        $q->where('vessels.vessel_type', 'unknown')
                          ->orWhereNull('vessels.vessel_type');
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

                // Delete within transaction with pre-flight safeguard
                DB::beginTransaction();
                try {
                    // Pre-flight safeguard: verify none of the batch belongs to a protected vessel or is a gap indicator
                    $violation = DB::table('vessel_positions')
                        ->join('vessels', 'vessel_positions.vessel_id', '=', 'vessels.id')
                        ->whereIn('vessel_positions.id', $batchIds)
                        ->where(function ($q) use ($allProtectedVesselIds) {
                            $q->whereIn('vessel_positions.vessel_id', $allProtectedVesselIds)
                              ->orWhere('vessels.vessel_type', 'fishing')
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
                } catch (\Throwable $e) {
                    DB::rollBack();
                    Log::error("Retention purge transaction failed: {$e->getMessage()}");
                    throw $e;
                }

            } while (count($batchIds) === $batchSize);

            $purgedByType[$type] = $typePurged;
        }

        // Update settings state
        $summary = [
            'executed_at' => now()->toIso8601String(),
            'total_purged' => $totalPurged,
            'purged_by_type' => $purgedByType,
            'protected_evidence_vessels' => count($allProtectedVesselIds),
            'fishing_preserved' => true,
        ];

        $settings->update([
            'last_cleanup_at' => now(),
            'last_cleanup_records_purged' => $totalPurged,
            'last_cleanup_status' => 'SUCCESS',
            'last_cleanup_summary' => $summary,
        ]);

        // Audit Log
        AuditLog::log(
            action: 'delete',
            entityType: 'ais_retention_cleanup',
            entityId: (string) $settings->id,
            details: $summary,
            userId: $userId
        );

        Log::info("AIS Data Retention Purge executed. Total positions purged: {$totalPurged}. Fishing and protected data 100% preserved.");

        return [
            'success' => true,
            'message' => "AIS retention cleanup executed successfully. {$totalPurged} non-fishing position records purged.",
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
    protected function formatBytes(float|int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
