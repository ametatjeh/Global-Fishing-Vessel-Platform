<?php

namespace App\Console\Commands;

use App\Jobs\EnrichAwaitingVesselJob;
use App\Models\Vessel;
use App\Services\Enrichment\VesselEnrichmentManager;
use Illuminate\Console\Command;

class EnrichAwaitingVesselsCommand extends Command
{
    protected $signature = 'vessels:enrich-awaiting
                            {--vessel= : Target vessel UUID, MMSI, or IMO}
                            {--mmsi= : Target vessel MMSI}
                            {--limit=25 : Batch limit for bulk processing}
                            {--active-only : Only process vessels with active telemetry within last 24h}
                            {--rate-limit=1 : Delay in seconds between requests for rate limiting}
                            {--dry-run : Perform simulation without modifying database}
                            {--force : Force re-enrichment of already processed vessels}
                            {--queue : Dispatch as background queue jobs}';

    protected $description = 'Enrich vessels awaiting AIS static data using multi-source maritime intelligence';

    public function handle(VesselEnrichmentManager $enrichmentManager): int
    {
        $this->outputBanner();

        $vesselTarget = $this->option('vessel') ?? $this->option('mmsi');
        $isDryRun = (bool) $this->option('dry-run');
        $isQueue = (bool) $this->option('queue');
        $limit = max(1, (int) $this->option('limit'));
        $activeOnly = (bool) $this->option('active-only');
        $force = (bool) $this->option('force');
        $rateLimit = max(0, (int) $this->option('rate-limit'));

        // Display provider status
        $this->info(" --- PROVIDER CONFIGURATION STATUS ---");
        $provStatus = $enrichmentManager->getProvidersStatus();
        $provRows = [];
        foreach ($provStatus as $name => $info) {
            $provRows[] = [
                strtoupper($name),
                $info['is_configured'] ? '<info>CONFIGURED / ACTIVE</info>' : '<comment>NOT CONFIGURED</comment>',
            ];
        }
        $this->table(['Provider Source', 'Status'], $provRows);

        if ($vesselTarget) {
            return $this->handleSingleVessel($enrichmentManager, $vesselTarget, $isDryRun);
        }

        return $this->handleBatchEnrichment($enrichmentManager, $limit, $activeOnly, $force, $rateLimit, $isDryRun, $isQueue);
    }

    protected function handleSingleVessel(VesselEnrichmentManager $manager, string $target, bool $isDryRun): int
    {
        $vessel = Vessel::where('id', $target)
            ->orWhere('mmsi', $target)
            ->orWhere('imo', $target)
            ->first();

        if (!$vessel) {
            $this->error(" [ERROR] Target vessel '{$target}' not found in database.");
            return Command::FAILURE;
        }

        $this->info("\n >>> TARGET VESSEL: {$vessel->name} [MMSI: {$vessel->mmsi}, UUID: {$vessel->id}]");
        $this->comment(" Execution Mode: " . ($isDryRun ? 'DRY-RUN (SIMULATION)' : 'LIVE EXECUTION'));

        $result = $manager->enrichVessel($vessel, dryRun: $isDryRun);

        // Display summary
        $this->table(
            ['Property', 'Value'],
            [
                ['Vessel Name', $vessel->name],
                ['MMSI', $vessel->mmsi],
                ['IMO', $vessel->display_imo],
                ['AIS Status', $result['ais_status']],
                ['Enrichment Status', strtoupper($result['enrichment_status'])],
                ['Updates Count', count($result['updates_applied'])],
                ['Conflicts Count', count($result['conflicts'])],
                ['Message', $result['message']],
            ]
        );

        if (!empty($result['updates_applied'])) {
            $this->line("\n <fg=bright-cyan;options=bold>ATTRIBUTES ENRICHED:</>");
            $updateRows = [];
            foreach ($result['updates_applied'] as $attr => $val) {
                $updateRows[] = [$attr, (string) $val, '<info>ENRICHED</info>'];
            }
            $this->table(['Attribute', 'Value', 'Action'], $updateRows);
        }

        if (!empty($result['conflicts'])) {
            $this->line("\n <fg=bright-red;options=bold>CONFLICTING ATTRIBUTES (PRESERVED):</>");
            $conflictRows = [];
            foreach ($result['conflicts'] as $attr => $c) {
                $conflictRows[] = [$attr, (string)$c['profile_value'], (string)$c['incoming_value'], $c['source'], $c['reason']];
            }
            $this->table(['Attribute', 'Profile Value', 'Incoming Value', 'Source', 'Conflict Reason'], $conflictRows);
        }

        return Command::SUCCESS;
    }

    protected function handleBatchEnrichment(
        VesselEnrichmentManager $manager,
        int $limit,
        bool $activeOnly,
        bool $force,
        int $rateLimit,
        bool $isDryRun,
        bool $isQueue
    ): int {
        $query = Vessel::query();

        if (!$force) {
            $query->awaitingStatic();
            $query->where(function ($q) {
                $q->whereNull('enrichment_status')
                  ->orWhereIn('enrichment_status', ['not_started', 'not_enriched', 'awaiting']);
            });
        }

        if ($activeOnly) {
            $query->whereNotNull('last_seen_at')
                  ->where('last_seen_at', '>=', now()->subHours(24));
        }

        $totalEligible = (clone $query)->count();
        $vessels = $query->orderByDesc('last_seen_at')->limit($limit)->get();

        $this->info("\n [BATCH SUMMARY]");
        $this->line(" Total Eligible Awaiting Vessels: <info>{$totalEligible}</info>");
        $this->line(" Batch Target Limit: <comment>{$limit}</comment>");
        $this->line(" Active Only Filter: " . ($activeOnly ? '<info>YES (Last 24h)</info>' : 'NO (All Awaiting)'));
        $this->line(" Execution Mode: " . ($isDryRun ? '<comment>DRY-RUN</comment>' : ($isQueue ? '<info>QUEUED</info>' : '<info>SYNCHRONOUS</info>')));

        if ($vessels->isEmpty()) {
            $this->info(" No eligible vessels found matching criteria.");
            \Illuminate\Support\Facades\Log::info("EnrichAwaitingVesselsCommand: No eligible awaiting vessels found matching batch criteria.", [
                'limit' => $limit,
                'active_only' => $activeOnly,
            ]);
            return Command::SUCCESS;
        }

        if ($isQueue) {
            $startTime = microtime(true);
            $dispatched = 0;
            foreach ($vessels as $v) {
                // Mark immediately as searching to prevent re-dispatch on subsequent scheduler runs
                $v->update(['enrichment_status' => 'searching']);
                EnrichAwaitingVesselJob::dispatch($v->id, force: $force);
                $dispatched++;
            }
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->info(" Successfully dispatched {$dispatched} background enrichment jobs to queue (dispatch took {$durationMs}ms).");

            \Illuminate\Support\Facades\Log::info("EnrichAwaitingVesselsCommand: Batch queue dispatch completed.", [
                'scheduled' => true,
                'dispatched_count' => $dispatched,
                'batch_limit' => $limit,
                'active_only' => $activeOnly,
                'total_eligible' => $totalEligible,
                'dispatch_duration_ms' => $durationMs,
            ]);

            return Command::SUCCESS;
        }

        $startTime = microtime(true);
        $counts = [
            'total' => $vessels->count(),
            'enriched' => 0,
            'partially_enriched' => 0,
            'conflicting' => 0,
            'not_found' => 0,
            'provider_unavailable' => 0,
        ];

        $progressBar = $this->output->createProgressBar($vessels->count());
        $progressBar->start();

        foreach ($vessels as $vessel) {
            $res = $manager->enrichVessel($vessel, dryRun: $isDryRun);
            $st = strtolower($res['enrichment_status']);

            if (isset($counts[$st])) {
                $counts[$st]++;
            }

            if ($rateLimit > 0) {
                sleep($rateLimit);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $this->line("\n");

        $this->info(" --- BATCH EXECUTION RESULTS ---");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $counts['total']],
                ['Fully Enriched', $counts['enriched']],
                ['Partially Enriched', $counts['partially_enriched']],
                ['Conflicting (Review Required)', $counts['conflicting']],
                ['Not Found', $counts['not_found']],
                ['Provider Unavailable / Rate Limited', $counts['provider_unavailable']],
                ['Execution Duration', "{$durationMs}ms"],
            ]
        );

        \Illuminate\Support\Facades\Log::info("EnrichAwaitingVesselsCommand: Synchronous batch execution completed.", [
            'total_processed' => $counts['total'],
            'enriched' => $counts['enriched'],
            'partially_enriched' => $counts['partially_enriched'],
            'conflicting' => $counts['conflicting'],
            'not_found' => $counts['not_found'],
            'provider_unavailable' => $counts['provider_unavailable'],
            'duration_ms' => $durationMs,
            'dry_run' => $isDryRun,
        ]);

        return Command::SUCCESS;
    }

    protected function outputBanner(): void
    {
        $this->line("\n<fg=bright-cyan;options=bold>===============================================================</>");
        $this->line("<fg=bright-cyan;options=bold> GFVIP — AWAITING STATIC DATA MULTI-SOURCE ENRICHMENT ENGINE  </>");
        $this->line("<fg=bright-cyan;options=bold>===============================================================</>\n");
    }
}

