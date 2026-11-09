<?php

namespace App\Jobs;

use App\Models\Vessel;
use App\Services\Enrichment\VesselEnrichmentManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnrichAwaitingVesselJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public string $vesselId,
        public bool $force = false
    ) {
    }

    public function handle(VesselEnrichmentManager $enrichmentManager): void
    {
        $lockKey = 'awaiting_enrich_job_lock_' . $this->vesselId;
        $lock = Cache::lock($lockKey, 3600);

        if (!$lock->get()) {
            Log::info("EnrichAwaitingVesselJob: Job skipped for vessel {$this->vesselId} due to active lock (duplicate prevention).");
            return;
        }

        try {
            $vessel = Vessel::find($this->vesselId);
            if (!$vessel) {
                Log::warning("EnrichAwaitingVesselJob: Vessel ID {$this->vesselId} not found in database.");
                $lock->release();
                return;
            }

            // If already processed recently and not forcing, skip
            if (!$this->force && in_array($vessel->enrichment_status, ['enriched', 'conflicting', 'not_found'], true)) {
                if ($vessel->last_enriched_at && $vessel->last_enriched_at->gt(now()->subDays(7))) {
                    Log::info("EnrichAwaitingVesselJob: Vessel {$vessel->id} already enriched recently ({$vessel->enrichment_status}). Skipping.");
                    $lock->release();
                    return;
                }
            }

            $vessel->update(['enrichment_status' => 'searching']);

            $result = $enrichmentManager->enrichVessel($vessel, dryRun: false);

            Log::info("EnrichAwaitingVesselJob: Successfully processed vessel {$vessel->id} ({$result['message']}).");
        } catch (\Throwable $e) {
            Log::error("EnrichAwaitingVesselJob: Error enriching vessel {$this->vesselId}: " . $e->getMessage());
            $vessel?->update(['enrichment_status' => 'provider_unavailable']);
            throw $e;
        } finally {
            $lock->release();
        }
    }
}

