<?php

namespace App\Jobs;

use App\Models\Vessel;
use App\Services\Maritime\GfwVesselEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnrichVesselGfwJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 60;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     * Exponential backoff: 60s (1 min), 300s (5 mins), 900s (15 mins).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $vesselId,
        public bool $force = false
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(GfwVesselEnrichmentService $enrichmentService): void
    {
        // 1. Check if GFW provider is configured
        if (!$enrichmentService->isConfigured()) {
            Log::info("EnrichVesselGfwJob: GFW API is not configured. Skipping vessel {$this->vesselId}.");
            return;
        }

        // 2. Prevent duplicate concurrent or rapid-fire enrichment jobs
        $lockKey = 'gfw_enrich_job_lock_' . $this->vesselId;
        $lock = Cache::lock($lockKey, 3600);

        if (!$lock->get()) {
            Log::info("EnrichVesselGfwJob: Job skipped for vessel {$this->vesselId} due to active lock (duplicate prevention).");
            return;
        }

        try {
            $vessel = Vessel::find($this->vesselId);
            if (!$vessel) {
                Log::warning("EnrichVesselGfwJob: Vessel ID {$this->vesselId} not found in database.");
                $lock->release();
                return;
            }

            // If already enriched and not forcing re-enrichment, skip
            if (!$this->force && in_array($vessel->enrichment_status, ['enriched', 'conflict_detected', 'no_match'], true)) {
                // If it was enriched within the past 7 days, skip
                if ($vessel->last_enriched_at && $vessel->last_enriched_at->gt(now()->subDays(7))) {
                    Log::info("EnrichVesselGfwJob: Vessel {$vessel->id} already enriched recently ({$vessel->enrichment_status}). Skipping.");
                    $lock->release();
                    return;
                }
            }

            Log::info("EnrichVesselGfwJob: Enriching vessel {$vessel->id} ({$vessel->name}, MMSI: {$vessel->mmsi}, Type: {$vessel->vessel_type})...");

            $result = $enrichmentService->enrichVessel($vessel, dryRun: false);

            if (!empty($result['is_rate_limited']) || ($result['status'] ?? '') === 'rate_limited') {
                Log::warning("EnrichVesselGfwJob: Rate limited (429) for vessel {$this->vesselId}. Releasing job with backoff.");
                $lock->release();
                if ($this->job) {
                    $this->release(300);
                }
                return;
            }

            Log::info("EnrichVesselGfwJob: Completed for vessel {$this->vesselId}. Status: {$result['status']}. Message: {$result['message']}");
            $lock->release();
        } catch (\Throwable $e) {
            $lock->release();
            Log::error("EnrichVesselGfwJob: Exception occurred for vessel {$this->vesselId}: " . $enrichmentService->apiService?->sanitizeMessage($e->getMessage()));
            throw $e;
        }
    }
}
