<?php

namespace App\Jobs;

use App\Models\EnrichmentJob;
use App\Models\Vessel;
use App\Services\Enrichment\VesselEnrichmentManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnrichAwaitingVesselJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->vesselId))->releaseAfter(60)->expireAfter(300),
        ];
    }

    public function __construct(
        public string $vesselId,
        public bool $force = false
    ) {
    }

    public function handle(VesselEnrichmentManager $enrichmentManager): void
    {
        $startTime = microtime(true);
        $vessel = null;

        try {
            $vessel = Vessel::find($this->vesselId);
            if (!$vessel) {
                Log::warning("EnrichAwaitingVesselJob: Vessel ID {$this->vesselId} not found in database.");
                return;
            }

            // If already processed recently and not forcing, skip
            if (!$this->force && in_array($vessel->enrichment_status, ['enriched', 'conflicting', 'not_found'], true)) {
                if ($vessel->last_enriched_at && $vessel->last_enriched_at->gt(now()->subDays(7))) {
                    Log::info("EnrichAwaitingVesselJob: Vessel {$vessel->id} already enriched recently ({$vessel->enrichment_status}). Skipping.");
                    return;
                }
            }

            if ($vessel->enrichment_status !== 'searching') {
                $vessel->update(['enrichment_status' => 'searching']);
            }

            $result = $enrichmentManager->enrichVessel($vessel, dryRun: false);
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            Log::info("EnrichAwaitingVesselJob: Processed vessel {$vessel->id} [MMSI: {$vessel->mmsi}] in {$durationMs}ms - Status: {$result['enrichment_status']}.", [
                'vessel_id' => $vessel->id,
                'mmsi' => $vessel->mmsi,
                'status' => $result['enrichment_status'],
                'updates_applied' => count($result['updates_applied'] ?? []),
                'conflicts' => count($result['conflicts'] ?? []),
                'duration_ms' => $durationMs,
            ]);

            // If transient provider failure occurred and attempts remain, release back to queue for backoff retry
            if ($result['enrichment_status'] === 'provider_unavailable' && $this->attempts() < $this->tries) {
                Log::warning("EnrichAwaitingVesselJob: Provider unavailable for vessel {$vessel->id}. Releasing back to queue with backoff.");
                $this->release(60 * $this->attempts());
                return;
            }
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            Log::error("EnrichAwaitingVesselJob: Error enriching vessel {$this->vesselId} after {$durationMs}ms: " . $e->getMessage(), [
                'vessel_id' => $this->vesselId,
                'attempt' => $this->attempts(),
                'duration_ms' => $durationMs,
                'exception' => $e->getMessage(),
            ]);

            EnrichmentJob::create([
                'vessel_id' => $this->vesselId,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'scheduled_at' => now(),
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $vessel?->update(['enrichment_status' => 'provider_unavailable']);
            throw $e;
        }
    }

    /**
     * Handle permanent job failure when all retries are exhausted.
     */
    public function failed(?\Throwable $exception): void
    {
        $vessel = Vessel::find($this->vesselId);
        $vesselStatus = $vessel?->enrichment_status;

        Log::error("EnrichAwaitingVesselJob: Job permanently failed / retry exhausted for vessel {$this->vesselId} [Current Status: {$vesselStatus}]: " . ($exception?->getMessage() ?? 'Unknown error'));

        if ($vessel && $vessel->enrichment_status === 'searching') {
            $vessel->update(['enrichment_status' => 'provider_unavailable']);
        }

        EnrichmentJob::create([
            'vessel_id' => $this->vesselId,
            'status' => 'failed',
            'error_message' => 'Job permanently failed after retry exhaustion: ' . ($exception?->getMessage() ?? 'Max attempts exceeded'),
            'scheduled_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}

