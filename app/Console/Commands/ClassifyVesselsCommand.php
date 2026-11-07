<?php

namespace App\Console\Commands;

use App\Services\Maritime\VesselClassificationService;
use Illuminate\Console\Command;

class ClassifyVesselsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vessels:classify
                            {--vessel= : Target vessel UUID, MMSI, or IMO}
                            {--dry-run : Perform simulation without modifying database (Default)}
                            {--apply : Explicitly apply changes to database}
                            {--query-gfw : Query live GFW API v3 for unclassified vessels}
                            {--limit=100 : Maximum vessels to inspect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit, map, and repair vessel Fleet Type classifications using AIS codes, GFW v3, and authoritative registries';

    public function handle(VesselClassificationService $classificationService): int
    {
        $this->outputBanner();

        $isApply = (bool) $this->option('apply');
        $isDryRun = (bool) $this->option('dry-run') || !$isApply;

        $targetVessel = $this->option('vessel');
        $queryGfw = (bool) $this->option('query-gfw');
        $limit = (int) $this->option('limit');

        $modeLabel = $isDryRun
            ? '<fg=yellow;options=bold>DRY-RUN SIMULATION (NO DATABASE CHANGES)</>'
            : '<fg=bright-red;options=bold>APPLY MODE (PERMANENT DATABASE UPDATE)</>';

        $this->line(" Execution Mode: {$modeLabel}");
        $this->line(" Target Filter: " . ($targetVessel ?: 'All vessels (limit ' . $limit . ')') . "\n");

        $stats = $classificationService->auditAndClassify([
            'apply' => $isApply,
            'vessel' => $targetVessel,
            'query_gfw' => $queryGfw,
            'limit' => $limit,
        ]);

        // 1. Summary Statistics Table
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Vessels Inspected', $stats['total_inspected']],
                ['Total Unknown Vessels', $stats['total_unknown']],
                ['Total NULL / Empty Vessels', $stats['total_null']],
                ['Manually Verified Skipped (Protected)', $stats['total_protected_skipped']],
                ['Source Conflicts Detected', $stats['total_conflicts']],
                ['Eligible Classification Changes', $stats['total_eligible_changes']],
                ['Total Changes Applied', $stats['total_applied']],
            ]
        );

        // 2. Eligible Changes Table
        if (count($stats['changes']) > 0) {
            $this->line("\n <fg=bright-cyan;options=bold>CANDIDATE CLASSIFICATION CHANGES:</>");
            $rows = [];
            foreach ($stats['changes'] as $c) {
                $rows[] = [
                    $c['name'] . ' (' . ($c['mmsi'] ?: '-') . ')',
                    $c['current'],
                    '<info>' . $c['resolved'] . '</info>',
                    $c['source'],
                    $c['reason'],
                ];
            }
            $this->table(['Vessel (MMSI)', 'Current Type', 'Resolved Fleet Type', 'Provenance Source', 'Resolution Reason'], $rows);
        } else {
            $this->line("\n <info>[✓] No classification changes required. All inspected records match current rules or lack evidence.</info>");
        }

        // 3. Conflicts Table
        if (count($stats['conflicts']) > 0) {
            $this->line("\n <fg=bright-red;options=bold>SOURCE CONFLICTS DETECTED (REQUIRING ANALYST ATTENTION):</>");
            $conflictRows = [];
            foreach ($stats['conflicts'] as $conf) {
                $conflictRows[] = [
                    $conf['name'],
                    $conf['current'],
                    $conf['candidate'],
                    $conf['source'],
                    $conf['reason'],
                ];
            }
            $this->table(['Vessel Name', 'Local Type', 'Candidate Type', 'Source', 'Conflict Description'], $conflictRows);
        }

        // 4. Instructions
        $this->line("");
        if ($isDryRun) {
            $this->info(" [DRY-RUN COMPLETE] Evaluated {$stats['total_inspected']} vessel(s). No changes written to database.");
            if ($stats['total_eligible_changes'] > 0) {
                $this->line(" To apply these changes permanently to the database, run:");
                $this->comment(" php artisan vessels:classify --apply\n");
            }
        } else {
            $this->info(" [APPLY COMPLETED] Successfully updated {$stats['total_applied']} vessel record(s).\n");
        }

        return Command::SUCCESS;
    }

    protected function outputBanner(): void
    {
        $this->line("\n<fg=bright-cyan>================================================================================</>");
        $this->line("<fg=bright-cyan;options=bold>  GFVIP — VESSEL FLEET TYPE AUDIT & CLASSIFICATION SERVICE                    </>");
        $this->line("<fg=bright-cyan>================================================================================</>\n");
    }
}

