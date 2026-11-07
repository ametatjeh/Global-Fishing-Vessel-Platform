<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\EncounterVessel;
use App\Models\ProhibitedVessel;
use App\Models\Vessel;
use App\Models\VesselAuthorization;
use App\Models\VesselEncounter;
use App\Models\VesselIdentifier;
use App\Models\VesselPosition;
use App\Models\VesselTrack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VesselPurgeService
{
    /**
     * Inspect all non-fishing target vessels (cargo, tanker, pleasure) and their related database records.
     */
    public function inspectPurgeTargets(): array
    {
        $cargoVessels = Vessel::where('vessel_type', 'cargo')->get();
        $tankerVessels = Vessel::where('vessel_type', 'tanker')->get();
        $pleasureVessels = Vessel::where('vessel_type', 'pleasure')->get();

        $cargoIds = $cargoVessels->pluck('id')->toArray();
        $tankerIds = $tankerVessels->pluck('id')->toArray();
        $pleasureIds = $pleasureVessels->pluck('id')->toArray();
        $allTargetIds = array_values(array_unique(array_merge($cargoIds, $tankerIds, $pleasureIds)));

        $cargoMmsis = $cargoVessels->pluck('mmsi')->filter()->values()->toArray();
        $tankerMmsis = $tankerVessels->pluck('mmsi')->filter()->values()->toArray();
        $pleasureMmsis = $pleasureVessels->pluck('mmsi')->filter()->values()->toArray();
        $allTargetMmsis = array_values(array_unique(array_merge($cargoMmsis, $tankerMmsis, $pleasureMmsis)));

        // Identify protected references to preserve forensic and historical integrity
        $encounterV1VesselIds = VesselEncounter::whereIn('vessel_1_id', $allTargetIds)->pluck('vessel_1_id')->toArray();
        $encounterV2VesselIds = VesselEncounter::whereIn('vessel_2_id', $allTargetIds)->pluck('vessel_2_id')->toArray();
        $encounterParticipantIds = EncounterVessel::whereIn('vessel_id', $allTargetIds)->pluck('vessel_id')->toArray();
        $allEncounterReferencedIds = array_unique(array_merge($encounterV1VesselIds, $encounterV2VesselIds, $encounterParticipantIds));

        $sarReferencedIds = [];
        if (SchemaHasTable('satellite_detections')) {
            $sarReferencedIds = DB::table('satellite_detections')
                ->whereIn('correlated_vessel_id', $allTargetIds)
                ->pluck('correlated_vessel_id')
                ->toArray();
        }

        $investigationReferencedIds = [];
        if (SchemaHasTable('investigations')) {
            $investigationReferencedIds = DB::table('investigations')
                ->whereIn('target_vessel_id', $allTargetIds)
                ->pluck('target_vessel_id')
                ->toArray();
        }

        $authorizationsCount = VesselAuthorization::whereIn('vessel_id', $allTargetIds)->count();
        $complianceEventsCount = ComplianceEvent::whereIn('vessel_id', $allTargetIds)->count();

        // Protected vessels are those tied to encounters, SAR correlations, or active investigations
        $protectedVesselIds = array_values(array_unique(array_merge(
            $allEncounterReferencedIds,
            $sarReferencedIds,
            $investigationReferencedIds
        )));

        // Deletable vessels are those with NO encounters, NO SAR correlations, and NO investigations
        $deletableVesselIds = array_values(array_diff($allTargetIds, $protectedVesselIds));

        $positionsCount = VesselPosition::whereIn('vessel_id', $allTargetIds)->count();
        $deletablePositionsCount = VesselPosition::whereIn('vessel_id', $deletableVesselIds)->count();
        $identifiersCount = VesselIdentifier::whereIn('vessel_id', $allTargetIds)->count();
        $tracksCount = VesselTrack::whereIn('vessel_id', $allTargetIds)->count();

        return [
            'cargo' => [
                'count' => $cargoVessels->count(),
                'vessels' => $cargoVessels->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'mmsi' => $v->mmsi,
                    'type' => $v->vessel_type,
                    'positions_count' => $v->positions()->count(),
                ])->toArray(),
            ],
            'tanker' => [
                'count' => $tankerVessels->count(),
                'vessels' => $tankerVessels->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'mmsi' => $v->mmsi,
                    'type' => $v->vessel_type,
                    'positions_count' => $v->positions()->count(),
                ])->toArray(),
            ],
            'pleasure' => [
                'count' => $pleasureVessels->count(),
                'vessels' => $pleasureVessels->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'mmsi' => $v->mmsi,
                    'type' => $v->vessel_type,
                    'positions_count' => $v->positions()->count(),
                ])->toArray(),
            ],
            'total_target_vessels' => count($allTargetIds),
            'target_vessel_ids' => $allTargetIds,
            'deletable_vessel_ids' => $deletableVesselIds,
            'protected_vessel_ids' => $protectedVesselIds,
            'target_mmsis' => $allTargetMmsis,
            'impacted_records' => [
                'vessels_total' => count($allTargetIds),
                'vessels_deletable' => count($deletableVesselIds),
                'vessels_protected' => count($protectedVesselIds),
                'vessel_positions' => $positionsCount,
                'vessel_positions_deletable' => $deletablePositionsCount,
                'vessel_identifiers' => $identifiersCount,
                'vessel_tracks' => $tracksCount,
            ],
            'protected_references' => [
                'vessel_encounters_v1' => count($encounterV1VesselIds),
                'vessel_encounters_v2' => count($encounterV2VesselIds),
                'encounter_vessels' => count($encounterParticipantIds),
                'sar_correlations' => count($sarReferencedIds),
                'investigations' => count($investigationReferencedIds),
                'authorizations' => $authorizationsCount,
                'compliance_events' => $complianceEventsCount,
            ],
            'fishing_vessels_remaining' => Vessel::where('vessel_type', 'fishing')->count(),
            'other_vessels_remaining' => Vessel::whereNotIn('vessel_type', ['cargo', 'tanker', 'pleasure', 'fishing'])->count(),
        ];
    }

    /**
     * Create an emergency pre-purge backup dump.
     */
    public function createPrePurgeBackup(): ?string
    {
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $dumpFile = $backupDir . '/gfvip_pre_purge_non_fishing_' . $timestamp . '.dump';
            $db = config('database.connections.pgsql.database');
            $user = config('database.connections.pgsql.username');
            $pass = config('database.connections.pgsql.password');
            $host = config('database.connections.pgsql.host');
            $port = config('database.connections.pgsql.port');

            $pgDumpPaths = [
                'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe',
                'C:\\Program Files\\PostgreSQL\\17\\bin\\pg_dump.exe',
                'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe',
                '/usr/bin/pg_dump',
                'pg_dump',
            ];

            $pgDumpBin = null;
            foreach ($pgDumpPaths as $p) {
                if (file_exists($p) || @exec("which {$p} 2>/dev/null")) {
                    $pgDumpBin = $p;
                    break;
                }
            }

            if ($pgDumpBin) {
                putenv("PGPASSWORD={$pass}");
                $cmd = sprintf('"%s" -h %s -p %s -U %s -F c -b -f "%s" %s 2>&1', $pgDumpBin, $host, $port, $user, $dumpFile, $db);
                $out = [];
                $ret = 0;
                exec($cmd, $out, $ret);

                if ($ret === 0 && file_exists($dumpFile)) {
                    return $dumpFile;
                }
            }
        }

        // Fallback snapshot metadata record
        $metaSnapshotFile = $backupDir . '/gfvip_pre_purge_manifest_' . $timestamp . '.json';
        file_put_contents($metaSnapshotFile, json_encode($this->inspectPurgeTargets(), JSON_PRETTY_PRINT));
        return $metaSnapshotFile;
    }

    /**
     * Execute controlled purge of non-fishing vessels (cargo, tanker, pleasure).
     *
     * @param bool $dryRun
     * @param int|null $adminUserId
     * @param string $reason
     * @return array
     */
    public function executePurge(bool $dryRun = true, ?int $adminUserId = null, string $reason = 'Penghapusan data internal kapal non-fishing (cargo, tanker, pleasure) sesuai kebijakan GFVIP'): array
    {
        $inspection = $this->inspectPurgeTargets();

        if ($dryRun) {
            return [
                'status' => 'dry_run',
                'message' => 'Laporan pemeriksaan dry-run berhasil dibuat. Tidak ada data yang dihapus dari database operasional.',
                'inspection' => $inspection,
                'backup_file' => null,
            ];
        }

        $backupFile = $this->createPrePurgeBackup();
        $targetIds = $inspection['target_vessel_ids'];
        $deletableIds = $inspection['deletable_vessel_ids'];
        $protectedIds = $inspection['protected_vessel_ids'];

        if (empty($targetIds)) {
            return [
                'status' => 'completed',
                'message' => 'Tidak ada kapal dengan klasifikasi cargo, tanker, atau pleasure yang ditemukan dalam database operasional.',
                'inspection' => $inspection,
                'deleted' => [
                    'cargo_vessels' => 0,
                    'tanker_vessels' => 0,
                    'pleasure_vessels' => 0,
                    'total_vessels' => 0,
                    'positions' => 0,
                    'identifiers' => 0,
                    'tracks' => 0,
                ],
                'protected_vessels_preserved' => 0,
                'backup_file' => $backupFile,
            ];
        }

        return DB::transaction(function () use ($targetIds, $deletableIds, $protectedIds, $inspection, $adminUserId, $reason, $backupFile) {
            $allTargetVessels = Vessel::whereIn('id', $targetIds)->get();
            $validAdminId = ($adminUserId && \App\Models\User::where('id', $adminUserId)->exists()) ? $adminUserId : null;

            // 1. Register ALL target MMSIs (both deletable and protected) into ProhibitedVessel registry
            // This permanently blocks re-ingestion of live AIS positions for any of them
            foreach ($allTargetVessels as $v) {
                if ($v->mmsi) {
                    ProhibitedVessel::firstOrCreate(
                        ['mmsi' => $v->mmsi],
                        [
                            'reason' => 'non_fishing_policy',
                            'original_name' => $v->name,
                            'original_type' => $v->vessel_type,
                            'blocked_by_user_id' => $validAdminId,
                        ]
                    );
                }
            }

            // 2. Count deleted records exclusively on deletable targets (no encounters/SAR/investigations)
            $deletedPositions = 0;
            $deletedIdentifiers = 0;
            $deletedTracks = 0;
            $deletedVesselsCount = 0;
            $deletedCargoCount = 0;
            $deletedTankerCount = 0;
            $deletedPleasureCount = 0;

            if (!empty($deletableIds)) {
                $deletedPositions = VesselPosition::whereIn('vessel_id', $deletableIds)->delete();
                $deletedIdentifiers = VesselIdentifier::whereIn('vessel_id', $deletableIds)->delete();
                $deletedTracks = VesselTrack::whereIn('vessel_id', $deletableIds)->delete();

                $deletableVessels = Vessel::whereIn('id', $deletableIds)->get();
                $deletedCargoCount = $deletableVessels->where('vessel_type', 'cargo')->count();
                $deletedTankerCount = $deletableVessels->where('vessel_type', 'tanker')->count();
                $deletedPleasureCount = $deletableVessels->where('vessel_type', 'pleasure')->count();

                // Delete only deletable vessels
                $deletedVesselsCount = Vessel::whereIn('id', $deletableIds)->delete();
            }

            // 3. Log audit trail
            AuditLog::log(
                'purge_cargo_tanker_vessels',
                'vessels',
                'cargo_tanker_pleasure_purge_batch',
                [
                    'deleted_vessels_total' => $deletedVesselsCount,
                    'deleted_cargo_vessels' => $deletedCargoCount,
                    'deleted_tanker_vessels' => $deletedTankerCount,
                    'deleted_pleasure_vessels' => $deletedPleasureCount,
                    'protected_vessels_preserved' => count($protectedIds),
                    'deleted_positions' => $deletedPositions,
                    'deleted_identifiers' => $deletedIdentifiers,
                    'deleted_tracks' => $deletedTracks,
                    'reason' => $reason,
                    'backup_file' => $backupFile,
                    'admin_user_id' => $adminUserId,
                    'registered_prohibited_mmsis' => $inspection['target_mmsis'],
                ]
            );

            Log::info("GFVIP Non-Fishing Purge executed by Admin #{$adminUserId}: {$deletedVesselsCount} vessels and {$deletedPositions} positions purged. " . count($protectedIds) . " protected encounter/SAR vessels preserved.");

            return [
                'status' => 'completed',
                'message' => 'Pembersihan data internal kapal cargo, tanker, dan pleasure berhasil dieksekusi secara terkontrol.',
                'backup_file' => $backupFile,
                'deleted' => [
                    'cargo_vessels' => $deletedCargoCount,
                    'tanker_vessels' => $deletedTankerCount,
                    'pleasure_vessels' => $deletedPleasureCount,
                    'total_vessels' => $deletedVesselsCount,
                    'positions' => $deletedPositions,
                    'identifiers' => $deletedIdentifiers,
                    'tracks' => $deletedTracks,
                ],
                'protected_vessels_preserved' => count($protectedIds),
                'prohibited_mmsis_registered' => count($inspection['target_mmsis']),
                'remaining_vessels' => [
                    'fishing' => Vessel::where('vessel_type', 'fishing')->count(),
                    'other' => Vessel::whereNotIn('vessel_type', ['cargo', 'tanker', 'pleasure', 'fishing'])->count(),
                ],
            ];
        });
    }

    /**
     * Grant an administrative exception for an MMSI.
     */
    public function grantException(string $mmsi, int $adminUserId, string $notes): bool
    {
        $cleanMmsi = preg_replace('/\D/', '', $mmsi);
        $record = ProhibitedVessel::where('mmsi', $cleanMmsi)->first();

        if (!$record) {
            return false;
        }

        $record->update([
            'exception_granted_at' => now(),
            'exception_notes' => $notes,
        ]);

        AuditLog::log(
            'grant_vessel_exception',
            'prohibited_vessel',
            $cleanMmsi,
            [
                'admin_user_id' => $adminUserId,
                'notes' => $notes,
            ]
        );

        return true;
    }
}

function SchemaHasTable(string $table): bool {
    try {
        return \Illuminate\Support\Facades\Schema::hasTable($table);
    } catch (\Throwable) {
        return false;
    }
}
