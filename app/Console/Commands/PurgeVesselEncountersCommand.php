<?php

namespace App\Console\Commands;

use App\Models\VesselEncounter;
use App\Services\DataRetentionPolicyService;
use Illuminate\Console\Command;

class PurgeVesselEncountersCommand extends Command
{
    protected $signature = 'encounters:purge
                            {--force : Bypass interactive safety confirmation}
                            {--chunk=1000 : Number of records to delete per batch chunk}
                            {--dry-run : Display total target records without deleting anything}';

    protected $description = 'Safely purge all historical Vessel Encounter Events in throttled batches while keeping vessels, positions, and operational evidence intact';

    public function handle(DataRetentionPolicyService $service): int
    {
        $this->newLine();
        $this->line('<fg=red;options=bold>========================================================================</>');
        $this->line('<fg=red;options=bold>   VESSEL ENCOUNTER EVENTS PURGE & RETENTION CLEANUP</>');
        $this->line('<fg=red;options=bold>========================================================================</>');
        $this->newLine();

        $stats = $service->getEncounterStorageStatistics(forceFresh: true);
        $totalEncounters = $stats['total_encounters'];
        $totalParticipants = $stats['total_participants'];

        $this->table(
            ['Metric', 'Current Database Count'],
            [
                ['Total Vessel Encounters', number_format($totalEncounters)],
                ['Total Participant Records', number_format($totalParticipants)],
                ['Candidate Encounters', number_format($stats['candidate'])],
                ['Needs Review Encounters', number_format($stats['needs_review'])],
                ['Verified Encounters', number_format($stats['verified'])],
                ['Rejected Encounters', number_format($stats['rejected'])],
                ['Oldest Timestamp', $stats['oldest'] ?? '—'],
                ['Newest Timestamp', $stats['newest'] ?? '—'],
            ]
        );

        if ($totalEncounters === 0) {
            $this->info("✓ No vessel encounters to delete (database already empty).");
            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("[DRY RUN MODE] No records were deleted.");
            return Command::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');

        if (!$this->option('force')) {
            $this->warn("WARNING: This will permanently delete ALL {$totalEncounters} Vessel Encounter records and participant links.");
            $this->line("Note: Vessel identities (vessels), AIS telemetry (vessel_positions), and live tracking will NOT be touched.");
            if (!$this->confirm("Do you wish to proceed with deleting all {$totalEncounters} vessel encounters?", false)) {
                $this->comment("Operation aborted by user.");
                return Command::SUCCESS;
            }
        }

        $this->info("Starting throttled batch purge (chunk size: {$chunkSize})...");

        $result = $service->purgeAllVesselEncounters(
            chunkSize: $chunkSize,
            userId: null
        );

        $this->table(
            ['Metric', 'Result'],
            [
                ['Deleted Vessel Encounters', number_format($result['deleted_encounters'])],
                ['Deleted Participant Associations', number_format($result['deleted_participants'])],
                ['Execution Duration', "{$result['duration_sec']} seconds"],
                ['Remaining Encounters in Database', number_format(VesselEncounter::count())],
            ]
        );

        $this->info("✓ " . $result['message']);
        return Command::SUCCESS;
    }
}
