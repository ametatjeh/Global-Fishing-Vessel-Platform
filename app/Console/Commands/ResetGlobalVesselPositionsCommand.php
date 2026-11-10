<?php

namespace App\Console\Commands;

use App\Services\GlobalVesselPositionResetService;
use Illuminate\Console\Command;

class ResetGlobalVesselPositionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ais:reset-global-positions
                            {--dry-run : Preview inspection changes without modifying database records}
                            {--apply : Execute fleet-wide position reset and synchronization}
                            {--stale-hours=24 : Freshness threshold in hours (default: 24)}
                            {--batch=1000 : Chunk size for batch processing}
                            {--report : Output a comprehensive Markdown operational report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely reset fleet-wide vessel positions, detect and quarantine land coordinates, reconstruct valid sea positions, and synchronize map state.';

    public function handle(GlobalVesselPositionResetService $service): int
    {
        $dryRunOpt = (bool) $this->option('dry-run');
        $applyOpt = (bool) $this->option('apply');
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $batchSize = max(100, (int) $this->option('batch'));
        $showReport = (bool) $this->option('report');

        // Safe by default: runs dry-run preview unless explicit --apply is provided without --dry-run
        $isApply = $applyOpt && !$dryRunOpt;

        $this->newLine();
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->line('<fg=cyan;options=bold>   GLOBAL FISHING VESSEL TRACKING - GLOBAL POSITION & LAND RESYNC</>');
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->newLine();

        if (!$isApply) {
            $this->warn(' [MODE: DRY-RUN PREVIEW] No operational database records will be modified.');
            $this->line(' To execute approved changes, re-run with: <fg=yellow>php artisan ais:reset-global-positions --apply</>');
        } else {
            $this->alert(' [MODE: LIVE EXECUTION] Updating operational vessel last_seen_at pointers and cache.');
        }
        $this->newLine();

        $this->info(" Inspecting global fleet positions against Natural Earth Land Polygons...");
        $result = $service->execute(
            dryRun: !$isApply,
            staleHours: $staleHours,
            batchSize: $batchSize,
            adminUserId: null
        );

        $inspection = $isApply ? ($result['post_inspection'] ?? []) : ($result['inspection'] ?? []);
        $fleet = $inspection['fleet_summary'] ?? [];
        $coords = $inspection['coordinates_breakdown'] ?? [];

        // Display Operational Summary Table
        $this->table(
            ['Metric Parameter', 'Value / Status', 'Description'],
            [
                ['Global Land Dataset', ($inspection['land_dataset_ready'] ?? false) ? '<fg=green>ACTIVE (Natural Earth 50m)</>' : '<fg=red>NOT POPULATED</>', 'PostGIS MULTIPOLYGON SRID 4326 with GiST index'],
                ['Total Registered Fleet', number_format($fleet['total_vessels'] ?? 0), 'All global vessels across database'],
                ['Historical Positions Preserved', number_format($fleet['total_positions'] ?? 0), '100% position history preserved (0 deletions)'],
                ['Valid Sea Positions', '<fg=green>' . number_format($coords['valid_sea_positions'] ?? 0) . '</>', 'Navigable water coordinates (coastal & high seas)'],
                ['Fresh Sea Positions (< ' . $staleHours . 'h)', '<fg=green>' . number_format($coords['fresh_positions'] ?? 0) . '</>', 'Broadcasting recently within freshness threshold'],
                ['Stale Sea Positions (>= ' . $staleHours . 'h)', '<fg=yellow>' . number_format($coords['stale_positions'] ?? 0) . '</>', 'Historical positions awaiting fresh AIS broadcasts'],
                ['Land Positions Quarantined', '<fg=red>' . number_format($coords['land_positions'] ?? 0) . '</>', 'Detected inside land polygons (quarantined from map)'],
                ['Null Island Sentinels (0, 0)', '<fg=yellow>' . number_format($coords['null_island_positions'] ?? 0) . '</>', 'Unacquired GPS sentinels (quarantined from map)'],
                ['Vessels With Valid Sea Position', '<fg=green>' . number_format($fleet['vessels_with_valid_sea_position'] ?? 0) . '</>', 'Eligible for active map marker rendering'],
                ['Vessels Without Valid Sea Position', '<fg=gray>' . number_format($fleet['vessels_without_valid_sea_position'] ?? 0) . '</>', 'Marker suppressed until valid sea coordinate received'],
            ]
        );

        if ($isApply) {
            $this->newLine();
            $this->info(" Execution Result: {$result['message']}");
            $summary = $result['summary'] ?? [];
            $this->line(" Flagged Land Positions: <fg=yellow>" . number_format($summary['flagged_land_positions'] ?? 0) . "</>");
            $this->line(" Flagged (0,0) / Invalid Positions: <fg=yellow>" . number_format($summary['flagged_invalid_positions'] ?? 0) . "</>");
            $this->line(" Synchronized Vessel Pointers: <fg=green>" . number_format($summary['rebuilt_vessels_pointers'] ?? 0) . "</>");
            $this->line(" Suppressed Markers (No Sea Position): <fg=gray>" . number_format($summary['cleared_vessels_without_sea_position'] ?? 0) . "</>");
            $this->line(" Execution Duration: {$result['execution_duration_sec']}s");
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
        $this->line('<fg=yellow># Markdown Operational Report</>');
        $this->line("```markdown");
        $this->line("# Global Vessel Position Reset & Land Detection Report");
        $this->line("- Date: " . now()->toIso8601String());
        $this->line("- Mode: " . ($isApply ? "APPLY (LIVE EXECUTION)" : "DRY-RUN (PREVIEW)"));
        $this->line("- Stale Threshold: {$staleHours} hours");
        $this->line("- Total Fleet: " . ($result['inspection']['fleet_summary']['total_vessels'] ?? 0));
        $this->line("- Land Positions: " . ($result['inspection']['coordinates_breakdown']['land_positions'] ?? 0));
        $this->line("- Valid Sea Positions: " . ($result['inspection']['coordinates_breakdown']['valid_sea_positions'] ?? 0));
        $this->line("- Null Island (0,0): " . ($result['inspection']['coordinates_breakdown']['null_island_positions'] ?? 0));
        $this->line("- Duration: {$result['execution_duration_sec']} seconds");
        $this->line("```");
    }
}

