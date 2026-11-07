<?php

namespace App\Console\Commands;

use App\Models\Vessel;
use App\Services\Maritime\GfwVesselEnrichmentService;
use Illuminate\Console\Command;

class EnrichVesselsGfwCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gfw:vessels:enrich
                            {--vessel= : Target vessel UUID, MMSI, or IMO}
                            {--dry-run : Perform simulation without modifying database}
                            {--batch= : Run batch enrichment on unenriched vessels}
                            {--limit=25 : Batch limit}
                            {--sleep=1 : Sleep seconds between batch requests for rate limiting}
                            {--force : Re-enrich already enriched vessels}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enrich vessel profile intelligence using official Global Fishing Watch API v3 with multi-source reconciliation';

    public function handle(GfwVesselEnrichmentService $enrichmentService): int
    {
        $this->outputBanner();

        if (!$enrichmentService->isConfigured()) {
            $this->error(' [ERROR] GFW API token is not configured in environment (GFW_API_TOKEN).');
            return Command::FAILURE;
        }

        $vesselTarget = $this->option('vessel');
        $isDryRun = (bool) $this->option('dry-run');
        $isBatch = (bool) $this->option('batch') || $this->option('batch') === 'true';

        if ($vesselTarget) {
            return $this->handleSingleVessel($enrichmentService, $vesselTarget, $isDryRun);
        }

        if ($isBatch) {
            return $this->handleBatchEnrichment($enrichmentService, $isDryRun);
        }

        $this->warn(' [NOTICE] Please specify either --vessel=<UUID|MMSI|IMO> or --batch to proceed.');
        $this->line(' Example: php artisan gfw:vessels:enrich --vessel=4820a2e2-1e1e-49b7-b5ba-a1d3f5cd90f0 --dry-run');
        return Command::INVALID;
    }

    protected function handleSingleVessel(GfwVesselEnrichmentService $service, string $target, bool $isDryRun): int
    {
        $vessel = Vessel::where('id', $target)
            ->orWhere('mmsi', $target)
            ->orWhere('imo', $target)
            ->first();

        if (!$vessel) {
            $this->error(" [ERROR] Target vessel '{$target}' not found in local database.");
            return Command::FAILURE;
        }

        $modeLabel = $isDryRun ? 'DRY-RUN SIMULATION (NO DATABASE MODIFICATIONS)' : 'LIVE EXECUTION (WRITING TO DATABASE)';
        $this->info(" >>> TARGET RESOLVED: {$vessel->name} [UUID: {$vessel->id}]");
        $this->comment(" Execution Mode: {$modeLabel}\n");

        $result = $service->enrichVessel($vessel, dryRun: $isDryRun);

        // 1. Vessel Identity Card
        $this->table(
            ['Property', 'Local Database Value'],
            [
                ['UUID', $vessel->id],
                ['Vessel Name', $vessel->name ?: '<empty>'],
                ['MMSI', $vessel->mmsi ?: '<empty>'],
                ['IMO', $vessel->imo ?: '<empty>'],
                ['Callsign', $vessel->callsign ?: '<empty>'],
                ['Flag State', $vessel->flag_state ?: '<empty>'],
                ['Vessel Type', $vessel->vessel_type],
                ['Length (LOA)', $vessel->length_meters ? "{$vessel->length_meters} m" : '<missing>'],
                ['Beam / Width', $vessel->beam_meters ? "{$vessel->beam_meters} m" : '<missing>'],
                ['Gross Tonnage (GT)', $vessel->tonnage ? "{$vessel->tonnage} GT" : '<missing>'],
                ['Engine Power (kW)', $vessel->engine_power_kw ? "{$vessel->engine_power_kw} kW" : '<missing>'],
                ['Build Year', $vessel->build_year ?: '<missing>'],
                ['Owner / Operator', $vessel->owner ?: ($vessel->operator ?: '<missing>')],
                ['Verification Status', $vessel->verification_status],
                ['Enrichment Status', $vessel->enrichment_status],
            ]
        );

        // 2. Candidate Match Details
        $this->line("\n <fg=bright-cyan;options=bold>GFW SEARCH & IDENTITY MATCHING:</>");
        if (!$result['candidate_found']) {
            $this->warn(" Status: " . ($result['message'] ?? 'No verified GFW candidate found.'));
            return Command::SUCCESS;
        }

        $this->line(" [✓] Candidate Match: <info>MATCHED</info>");
        $this->line(" [✓] GFW Vessel ID: <info>{$result['gfw_vessel_id']}</info>");
        $this->line(" [✓] Match Method: <comment>{$result['match_method']}</comment> (Confidence: <info>{$result['match_confidence']}</info>)");
        $this->line(" [✓] Matching Identifiers: " . implode(', ', $result['matching_identifiers']));

        // 3. Proposed Updates
        $this->line("\n <fg=bright-cyan;options=bold>FIELD RECONCILIATION & UPDATES:</>");
        $updateRows = [];
        foreach ($result['fields_updated'] as $field => $upd) {
            $updateRows[] = [
                $field,
                $upd['current'] !== null ? (string)$upd['current'] : '<null/missing>',
                (string)$upd['new'],
                $upd['source'],
                '<info>ELIGIBLE FOR UPDATE</info>',
            ];
        }
        if (count($updateRows) > 0) {
            $this->table(['Field', 'Current Local', 'GFW Enrich Value', 'Provenance Source', 'Action'], $updateRows);
        } else {
            $this->line(" No new fields to update.");
        }

        // 4. Preserved Fields & Protected Identities
        if (count($result['fields_preserved']) > 0) {
            $this->line("\n <fg=bright-cyan;options=bold>PRESERVED FIELDS (PROTECTED FROM AUTOMATIC OVERWRITE):</>");
            $preservedRows = [];
            foreach ($result['fields_preserved'] as $field => $item) {
                $alt = !empty($item['alternative_names']) ? " (Alt: " . implode(', ', $item['alternative_names']) . ")" : '';
                $preservedRows[] = [
                    $field,
                    (string)$item['value'] . $alt,
                    $item['reason'],
                ];
            }
            $this->table(['Field', 'Preserved Value', 'Protection Reason'], $preservedRows);
        }

        // 5. Conflicts
        if (count($result['conflicts']) > 0) {
            $this->line("\n <fg=bright-red;options=bold>SOURCE CONFLICTS RECORDED FOR ANALYST REVIEW:</>");
            $conflictRows = [];
            foreach ($result['conflicts'] as $field => $conf) {
                $conflictRows[] = [
                    $field,
                    (string)$conf['local'],
                    (string)$conf['gfw'],
                    $conf['message'],
                ];
            }
            $this->table(['Field', 'Local Value', 'GFW Source Value', 'Conflict Analysis'], $conflictRows);
        }

        // 6. Missing Fields
        if (count($result['fields_missing_in_gfw']) > 0) {
            $this->line("\n <fg=yellow;options=bold>FIELDS REMAINING MISSING IN GFW REGISTRY:</>");
            foreach ($result['fields_missing_in_gfw'] as $field => $reason) {
                $this->line(" - <comment>{$field}</comment>: {$reason}");
            }
        }

        // 7. GFW Intelligence Events & Permissions
        if (!empty($result['events_summary'])) {
            $this->line("\n <fg=bright-cyan;options=bold>GFW INTELLIGENCE EVENTS & API PERMISSION STATUS:</>");
            $eventRows = [];
            foreach ($result['events_summary'] as $evKey => $evData) {
                $countStr = $evData['count'] !== null ? (string)$evData['count'] : '-';
                $statusColor = match ($evData['status']) {
                    'available' => '<info>AVAILABLE</info>',
                    'permission_restricted' => '<error>PERMISSION RESTRICTED (HTTP 403)</error>',
                    'rate_limited' => '<comment>RATE LIMITED (HTTP 429)</comment>',
                    default => '<comment>' . strtoupper($evData['status']) . '</comment>',
                };
                $eventRows[] = [
                    ucfirst(str_replace('_', ' ', $evKey)),
                    $countStr,
                    $statusColor,
                    $evData['dataset'] ?? '',
                ];
            }
            $this->table(['Event Category', 'Recorded Count', 'API Authorization Status', 'Dataset ID'], $eventRows);
        }

        // 8. Summary Status
        $this->line("");
        if ($isDryRun) {
            $this->info(" [DRY-RUN COMPLETE] Status: {$result['status']} — {$result['message']}");
            $this->line(" To apply these changes permanently to the database, run:");
            $this->comment(" php artisan gfw:vessels:enrich --vessel={$vessel->id}\n");
        } else {
            $this->info(" [ENRICHMENT APPLIED] Status: {$result['status']} — {$result['message']}\n");
        }

        return Command::SUCCESS;
    }

    protected function handleBatchEnrichment(GfwVesselEnrichmentService $service, bool $isDryRun): int
    {
        $limit = (int) $this->option('limit');
        $sleep = (int) $this->option('sleep');
        $isForce = (bool) $this->option('force');

        $query = Vessel::query();
        if (!$isForce) {
            $query->whereIn('enrichment_status', ['not_enriched', 'no_match']);
        }

        $vessels = $query->orderBy('last_seen_at', 'desc')->limit($limit)->get();
        $total = $vessels->count();

        if ($total === 0) {
            $this->info(' No vessels eligible for batch enrichment.');
            return Command::SUCCESS;
        }

        $this->info(" Starting batch enrichment for {$total} vessel(s)... (Rate limit sleep: {$sleep}s)\n");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $enrichedCount = 0;
        $conflictCount = 0;
        $noMatchCount = 0;

        foreach ($vessels as $vessel) {
            $res = $service->enrichVessel($vessel, dryRun: $isDryRun);
            if ($res['status'] === 'enriched') {
                $enrichedCount++;
            } elseif ($res['status'] === 'conflict_detected') {
                $conflictCount++;
            } else {
                $noMatchCount++;
            }

            $bar->advance();
            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $bar->finish();
        $this->line("\n");
        $this->info(" Batch enrichment completed: Enriched: {$enrichedCount}, Conflicts: {$conflictCount}, No Match: {$noMatchCount}");
        return Command::SUCCESS;
    }

    protected function outputBanner(): void
    {
        $this->line("\n<fg=bright-cyan>================================================================================</>");
        $this->line("<fg=bright-cyan;options=bold>  GFVIP — GLOBAL FISHING WATCH (GFW) VESSEL PROFILE ENRICHMENT SERVICE        </>");
        $this->line("<fg=bright-cyan>================================================================================</>\n");
    }
}

