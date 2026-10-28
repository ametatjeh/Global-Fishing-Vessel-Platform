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

        if (!$this->providerSlug || $this->providerSlug === 'fao') {
            $fao->sync();
        }
        if (!$this->providerSlug || $this->providerSlug === 'eez') {
            $eez->sync();
        }
        if (!$this->providerSlug || $this->providerSlug === 'global-fishing-watch') {
            $gfw->sync(['limit' => $this->limit]);
        }
        if (!$this->providerSlug || $this->providerSlug === 'copernicus-sentinel-1') {
            $copernicus->sync(['limit' => $this->limit]);
        }
        if ($this->providerSlug === 'ais-stream' || $this->providerSlug === 'ais') {
            // Queue jobs consume bounded batch to respect queue timeout limits
            $ais->consumeStream(min(20, $this->limit), 10);
        }

        Log::info("Queued provider sync finished.");
    }
}
