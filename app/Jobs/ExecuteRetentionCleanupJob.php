<?php

namespace App\Jobs;

use App\Models\AisRetentionRun;
use App\Services\DataRetentionPolicyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExecuteRetentionCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout for the queue worker running this job (30 minutes).
     */
    public int $timeout = 1800;

    /**
     * Only attempt once per dispatch to prevent unexpected cascading deletions.
     */
    public int $tries = 1;

    public function __construct(
        public AisRetentionRun $run
    ) {}

    public function handle(DataRetentionPolicyService $service): void
    {
        // 1. Prevent concurrent runs with atomic cache lock
        $lock = Cache::lock('ais_retention_execution_lock', 1800);

        if (!$lock->get()) {
            $msg = 'Tidak dapat memulai proses retensi: proses retensi lain saat ini sedang berjalan di latar belakang.';
            $this->run->update([
                'status' => 'failed',
                'error_message' => $msg,
                'completed_at' => now(),
            ]);
            Log::warning("[Retention Cleanup] Run #{$this->run->id} skipped: lock is already held.");
            return;
        }

        try {
            Log::info("[Retention Cleanup] Starting background execution for Run #{$this->run->id} ({$this->run->run_type}).");
            $service->executeRetentionRun($this->run);
        } catch (\Throwable $e) {
            Log::error("[Retention Cleanup] Run #{$this->run->id} encountered fatal exception: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            $this->run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        } finally {
            $lock->release();
            // Clear stats cache so updated figures are refreshed
            Cache::forget('ais_retention_storage_stats');
            Cache::forget('ais_retention_fast_metrics');
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->run->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage() ?? 'Job worker failed unexpectedly.',
            'completed_at' => now(),
        ]);
    }
}
