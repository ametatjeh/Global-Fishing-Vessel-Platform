<?php

namespace App\Console\Commands;

use App\Services\Providers\GlobalFishingWatchAdapter;
use App\Services\Providers\CopernicusSarAdapter;
use App\Services\Providers\FaoFishingAreaAdapter;
use App\Services\Providers\MarineRegionsEezAdapter;
use Illuminate\Console\Command;

class SyncProvidersCommand extends Command
{
    protected $signature = 'providers:sync {provider? : gfw, copernicus, ais, fao, eez} {--limit=50}';
    protected $description = 'Run scheduled data synchronization job for configured data providers';

    public function handle(
        GlobalFishingWatchAdapter $gfw,
        CopernicusSarAdapter $copernicus,
        \App\Services\Providers\AisStreamAdapter $ais,
        FaoFishingAreaAdapter $fao,
        MarineRegionsEezAdapter $eez
    ): int {
        $target = $this->argument('provider');
        $limit = (int) $this->option('limit');

        $this->info("Menjalankan sinkronisasi provider: " . ($target ?: 'Semua provider'));

        if (!$target || $target === 'fao') {
            $this->line("Sinkronisasi FAO Areas...");
            $res = $fao->sync();
            $this->info("FAO: {$res['status']} (Inserted: {$res['inserted']})");
        }

        if (!$target || $target === 'eez') {
            $this->line("Sinkronisasi Marine Regions EEZ...");
            $res = $eez->sync();
            $this->info("EEZ: {$res['status']} (Inserted: {$res['inserted']})");
        }

        if (!$target || $target === 'gfw') {
            $this->line("Sinkronisasi GFW Encounters...");
            $res = $gfw->sync(['limit' => $limit]);
            $this->info("GFW: {$res['status']} (Fetched: {$res['fetched']}, Inserted: {$res['inserted']})");
        }

        if (!$target || $target === 'copernicus') {
            $this->line("Sinkronisasi Copernicus Sentinel-1...");
            $res = $copernicus->sync(['limit' => $limit]);
            $this->info("Copernicus: {$res['status']} (Fetched: {$res['fetched']}, Inserted: {$res['inserted']})");
        }

        if ($target === 'ais') {
            $this->line("Mengonsumsi feed telemetri WebSocket live AIS...");
            $res = $ais->sync(['limit' => $limit]);
            $this->info("AIS Stream: {$res['status']} (Fetched: {$res['fetched']}, Inserted: {$res['inserted']})");
        }

        return self::SUCCESS;
    }
}
