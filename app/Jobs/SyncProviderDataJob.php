<?php

namespace App\Jobs;

use App\Services\Providers\GlobalFishingWatchAdapter;
use App\Services\Providers\CopernicusSarAdapter;
use App\Services\Providers\FaoFishingAreaAdapter;
use App\Services\Providers\MarineRegionsEezAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProviderDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?string $providerSlug = null,
        public int $limit = 50
    ) {
    }

    public function handle(
        GlobalFishingWatchAdapter $gfw,
        CopernicusSarAdapter $copernicus,
        \App\Services\Providers\AisStreamAdapter $ais,
        FaoFishingAreaAdapter $fao,
        MarineRegionsEezAdapter $eez
    ): void {
        Log::info("Starting queued provider sync for: " . ($this->providerSlug ?: 'all'));

        // Each provider sync is wrapped in an isolated try/catch block so failure in one provider
        // (such as GFW Research Access 403) never aborts or impacts AIS, EEZ, SAR, or FAO modules.

        if (!$this->providerSlug || $this->providerSlug === 'fao') {
            try {
                $fao->sync();
            } catch (\Throwable $e) {
                Log::error("FAO sync failed: " . $e->getMessage());
            }
        }

        if (!$this->providerSlug || $this->providerSlug === 'eez') {
            try {
                $eez->sync();
            } catch (\Throwable $e) {
                Log::error("EEZ sync failed: " . $e->getMessage());
            }
        }

        if (!$this->providerSlug || $this->providerSlug === 'global-fishing-watch') {
            try {
                $gfw->sync(['limit' => $this->limit]);
            } catch (\Throwable $e) {
                Log::error("GFW sync failed: " . $gfw->sanitizeErrorMessage($e->getMessage()));
            }
        }

        if (!$this->providerSlug || $this->providerSlug === 'copernicus-sentinel-1') {
            try {
                $copernicus->sync(['limit' => $this->limit]);
            } catch (\Throwable $e) {
                Log::error("Copernicus sync failed: " . $e->getMessage());
            }
        }

        if ($this->providerSlug === 'ais-stream' || $this->providerSlug === 'ais') {
            try {
                // Queue jobs consume bounded batch to respect queue timeout limits
                $ais->consumeStream(min(20, $this->limit), 10);
            } catch (\Throwable $e) {
                Log::error("AIS sync failed: " . $e->getMessage());
            }
        }

        Log::info("Queued provider sync finished.");
    }
}
