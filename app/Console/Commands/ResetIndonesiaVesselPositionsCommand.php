<?php

namespace App\Console\Commands;

use App\Services\IndonesiaVesselPositionSyncService;
use Illuminate\Console\Command;

class ResetIndonesiaVesselPositionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ais:reset-indonesia-positions
                            {--dry-run : Preview inspection changes without modifying database records}
                            {--apply : Execute approved position synchronization}
                            {--stale-hours=24 : Freshness threshold in hours (default: 24)}
                            {--rebuild-latest : Reconstruct vessels last_seen_at pointers from newest historical AIS records}
                            {--report : Output a comprehensive Markdown operational report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely reset stale vessel positions in Indonesian waters, reconstruct latest valid positions from AIS history, and synchronize map state.';

    public function handle(IndonesiaVesselPositionSyncService $service): int
    {
        $dryRunOpt = $this->option('dry-run');
        $applyOpt = $this->option('apply');
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $rebuildLatest = (bool) $this->option('rebuild-latest');
        $showReport = (bool) $this->option('report');

        // Safety enforcement: Default behavior is DRY-RUN unless explicit --apply is provided
        $isApply = $applyOpt && !$dryRunOpt;

        $this->newLine();
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->line('<fg=cyan;options=bold>   GLOBAL FISHING VESSEL TRACKING - INDONESIA WATERS RESYNC</>');
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->newLine();

        if (!$isApply) {
            $this->warn(' [MODE: DRY-RUN PREVIEW] No operational database records will be modified.');
            $this->line(' To execute approved changes, re-run with: <fg=yellow>php artisan ais:reset-indonesia-positions --apply</>');
        } else {
            $this->alert(' [MODE: LIVE EXECUTION] Modifying operational vessel latest_seen_at pointers and cache.');
        }
        $this->newLine();

        $this->info(" Inspecting positions against authoritative Indonesian EEZ boundary (MRGID: 8492)...");
        $result = $service->execute(
            dryRun: !$isApply,
            staleHours: $staleHours,
            rebuildLatest: $rebuildLatest || $isApply,
            adminUserId: null
        );

        $inspection = $result['inspection'] ?? [];
        $fleet = $inspection['fleet_summary'] ?? [];
        $freshness = $inspection['freshness_analysis'] ?? [];
        $worker = $inspection['ais_worker'] ?? [];

        // Display Summary Table
        $this->table(
            ['Metric Parameter', 'Value / Status', 'Description'],
            [
                ['Indonesian EEZ Boundary', ($inspection['eez']['found'] ?? false) ? '<fg=green>AUTHENTIC (MRGID 8492)</>' : '<fg=yellow>FALLBACK BBOX</>', 'Flanders Marine Institute v12 PostGIS polygon'],
                ['Total Registered Fleet', number_format($fleet['total_vessels'] ?? 0), 'Total vessels across operational database'],
                ['Historical Positions Preserved', number_format($fleet['total_historical_positions'] ?? 0), '100% position history preserved (0 deletions)'],
                ['Vessels in Indonesian Waters', number_format($fleet['vessels_in_indonesia'] ?? 0), 'Vessels with trajectory within Indonesian EEZ'],
                ['Positions in Indonesian Waters', number_format($fleet['positions_in_indonesia'] ?? 0), 'Historical coordinates bounded by Indonesian EEZ'],
                ['Fresh Positions (< ' . $staleHours . 'h)', '<fg=green>' . number_format($freshness['fresh_positions'] ?? 0) . '</>', 'Broadcasting recently within stale threshold'],
                ['Stale Positions (>= ' . $staleHours . 'h)', '<fg=yellow>' . number_format($freshness['stale_positions'] ?? 0) . '</>', 'Awaiting fresh AIS position broadcasts'],
                ['Invalid Coordinates Flagged', '<fg=red>' . number_format($freshness['invalid_coordinates'] ?? 0) . '</>', 'Quarantined from live map rendering'],
                ['Pointers Desynchronized', number_format($freshness['desynced_vessels'] ?? 0), 'Vessels whose last_seen_at regressed or out-of-order'],
                ['AIS Ingestion Worker Status', ($worker['worker_alive'] ?? false) ? '<fg=green>ACTIVE DAEMON</>' : '<fg=yellow>STANDBY / IDLE</>', 'stream.aisstream.io real-time connection status'],
            ]
        );

        if ($isApply) {
            $this->newLine();
            $this->info(" Execution Result: {$result['message']}");
            $this->line(" Synchronized Vessels: <fg=green>{$result['summary']['updated_vessels']}</>");
            $this->line(" Quarantined Invalid Positions: <fg=yellow>{$result['summary']['quarantined_invalid_positions']}</>");
            $this->line(" Duration: {$result['execution_duration_sec']}s");
        } else {
            $this->newLine();
            $this->comment(" Preview completed in {$result['execution_duration_sec']}s. 0 database records were deleted or altered.");
        }

        if ($showReport) {
            $this->renderMarkdownReport($result, $isApply, $staleHours);
        }

        $this->newLine();
        return Command::SUCCESS;
    }

    protected function renderMarkdownReport(array $result, bool $isApply, int $staleHours): void
    {
        $this->newLine();
        $this->line('<fg=white;options=bold>--- OPERATIONAL EXECUTION REPORT ---</>');
        $this->line("```markdown");
        $this->line("# Indonesia Vessel Position Synchronization Report");
        $this->line("- Date: " . now()->toIso8601String());
        $this->line("- Mode: " . ($isApply ? "APPLY (EXECUTED)" : "DRY-RUN (PREVIEW)"));
        $this->line("- Target Maritime Area: Indonesian Exclusive Economic Zone (MRGID: 8492)");
        $this->line("- Stale Threshold: {$staleHours} hours");
        $this->line("- Vessels in Scope: " . ($result['inspection']['fleet_summary']['vessels_in_indonesia'] ?? 0));
        $this->line("- Historical Positions Preserved: " . ($result['inspection']['fleet_summary']['total_historical_positions'] ?? 0));
        $this->line("- Invalid Coordinates Quarantined: " . ($result['inspection']['freshness_analysis']['invalid_coordinates'] ?? 0));
        $this->line("- Worker Daemon Status: " . ($result['inspection']['ais_worker']['status'] ?? 'unknown'));
        $this->line("```");
    }
}

