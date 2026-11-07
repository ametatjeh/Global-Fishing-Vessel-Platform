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
     * Inspect all cargo and tanker vessels and their related database records.
     */
    public function inspectPurgeTargets(): array
    {
        $cargoVessels = Vessel::where('vessel_type', 'cargo')->get();
        $tankerVessels = Vessel::where('vessel_type', 'tanker')->get();

        $cargoIds = $cargoVessels->pluck('id')->toArray();
        $tankerIds = $tankerVessels->pluck('id')->toArray();
        $allTargetIds = array_merge($cargoIds, $tankerIds);

        $cargoMmsis = $cargoVessels->pluck('mmsi')->filter()->values()->toArray();
        $tankerMmsis = $tankerVessels->pluck('mmsi')->filter()->values()->toArray();
        $allTargetMmsis = array_merge($cargoMmsis, $tankerMmsis);

        $positionsCount = VesselPosition::whereIn('vessel_id', $allTargetIds)->count();
        $identifiersCount = VesselIdentifier::whereIn('vessel_id', $allTargetIds)->count();
        $tracksCount = VesselTrack::whereIn('vessel_id', $allTargetIds)->count();

        // Check relational references to protect historical integrity
        $encountersV1Count = VesselEncounter::whereIn('vessel_1_id', $allTargetIds)->count();
        $encountersV2Count = VesselEncounter::whereIn('vessel_2_id', $allTargetIds)->count();
        $encounterVesselsCount = EncounterVessel::whereIn('vessel_id', $allTargetIds)->count();
        $authorizationsCount = VesselAuthorization::whereIn('vessel_id', $allTargetIds)->count();
        $complianceEventsCount = ComplianceEvent::whereIn('vessel_id', $allTargetIds)->count();

        $investigationsCount = 0;
        if (SchemaHasTable('investigations')) {
            $investigationsCount = DB::table('investigations')->whereIn('target_vessel_id', $allTargetIds)->count();
        }

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
            'total_target_vessels' => count($allTargetIds),
            'target_vessel_ids' => $allTargetIds,
            'target_mmsis' => $allTargetMmsis,
            'impacted_records' => [
                'vessels' => count($allTargetIds),
                'vessel_positions' => $positionsCount,
                'vessel_identifiers' => $identifiersCount,
                'vessel_tracks' => $tracksCount,
            ],
            'protected_references' => [
                'vessel_encounters_v1' => $encountersV1Count,
                'vessel_encounters_v2' => $encountersV2Count,
                'encounter_vessels' => $encounterVesselsCount,
                'authorizations' => $authorizationsCount,
                'compliance_events' => $complianceEventsCount,
                'investigations' => $investigationsCount,
            ],
            'fishing_vessels_remaining' => Vessel::where('vessel_type', 'fishing')->count(),
            'other_vessels_remaining' => Vessel::whereNotIn('vessel_type', ['cargo', 'tanker', 'fishing'])->count(),
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
            $dumpFile = $backupDir . '/gfvip_pre_purge_cargo_tanker_' . $timestamp . '.dump';
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
     * Execute controlled purge of cargo and tanker vessels.
     *
     * @param bool $dryRun
     * @param int|null $adminUserId
     * @param string $reason
     * @return array
     */
    public function executePurge(bool $dryRun = true, ?int $adminUserId = null, string $reason = 'Penghapusan data internal kapal cargo dan tanker sesuai kebijakan GFVIP'): array
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

        if (empty($targetIds)) {
            return [
                'status' => 'completed',
                'message' => 'Tidak ada kapal dengan klasifikasi cargo atau tanker yang ditemukan dalam database operasional.',
                'inspection' => $inspection,
                'deleted' => [
                    'cargo_vessels' => 0,
                    'tanker_vessels' => 0,
                    'positions' => 0,
                    'identifiers' => 0,
                    'tracks' => 0,
                ],
                'backup_file' => $backupFile,
            ];
        }

        return DB::transaction(function () use ($targetIds, $inspection, $adminUserId, $reason, $backupFile) {
            $targetVessels = Vessel::whereIn('id', $targetIds)->get();
            $validAdminId = ($adminUserId && \App\Models\User::where('id', $adminUserId)->exists()) ? $adminUserId : null;

            // 1. Register MMSIs into ProhibitedVessel registry to permanently block re-ingestion
            foreach ($targetVessels as $v) {
                if ($v->mmsi) {
                    ProhibitedVessel::firstOrCreate(
                        ['mmsi' => $v->mmsi],
                        [
                            'reason' => 'cargo_tanker_policy',
                            'original_name' => $v->name,
                            'original_type' => $v->vessel_type,
                            'blocked_by_user_id' => $validAdminId,
                        ]
                    );
                }
            }

            // 2. Count deleted records
            $deletedPositions = VesselPosition::whereIn('vessel_id', $targetIds)->delete();
            $deletedIdentifiers = VesselIdentifier::whereIn('vessel_id', $targetIds)->delete();
            $deletedTracks = VesselTrack::whereIn('vessel_id', $targetIds)->delete();

            $deletedCargoCount = $inspection['cargo']['count'];
            $deletedTankerCount = $inspection['tanker']['count'];

            // 3. Delete vessel records
            $deletedVesselsCount = Vessel::whereIn('id', $targetIds)->delete();

            // 4. Log audit trail
            AuditLog::log(
                'purge_cargo_tanker_vessels',
                'vessels',
                'cargo_tanker_purge_batch',
                [
                    'deleted_vessels_total' => $deletedVesselsCount,
                    'deleted_cargo_vessels' => $deletedCargoCount,
                    'deleted_tanker_vessels' => $deletedTankerCount,
                    'deleted_positions' => $deletedPositions,
                    'deleted_identifiers' => $deletedIdentifiers,
                    'deleted_tracks' => $deletedTracks,
                    'reason' => $reason,
                    'backup_file' => $backupFile,
                    'admin_user_id' => $adminUserId,
                    'registered_prohibited_mmsis' => $inspection['target_mmsis'],
                ]
            );

            Log::info("GFVIP Cargo/Tanker Purge executed by Admin #{$adminUserId}: {$deletedVesselsCount} vessels and {$deletedPositions} positions purged.");

            return [
                'status' => 'completed',
                'message' => "Pembersihan data internal kapal cargo dan tanker berhasil dieksekusi secara terkontrol.",
                'backup_file' => $backupFile,
                'deleted' => [
                    'cargo_vessels' => $deletedCargoCount,
                    'tanker_vessels' => $deletedTankerCount,
                    'total_vessels' => $deletedVesselsCount,
                    'positions' => $deletedPositions,
                    'identifiers' => $deletedIdentifiers,
                    'tracks' => $deletedTracks,
                ],
                'prohibited_mmsis_registered' => count($inspection['target_mmsis']),
                'remaining_vessels' => [
                    'fishing' => Vessel::where('vessel_type', 'fishing')->count(),
                    'other' => Vessel::whereNotIn('vessel_type', ['cargo', 'tanker', 'fishing'])->count(),
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
