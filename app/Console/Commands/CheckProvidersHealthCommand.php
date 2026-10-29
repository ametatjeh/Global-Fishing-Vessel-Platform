<?php

namespace App\Console\Commands;

use App\Services\Providers\GlobalFishingWatchAdapter;
use App\Services\Providers\CopernicusSarAdapter;
use App\Services\Providers\AisStreamAdapter;
use App\Services\Providers\FaoFishingAreaAdapter;
use App\Services\Providers\MarineRegionsEezAdapter;
use Illuminate\Console\Command;

class CheckProvidersHealthCommand extends Command
{
    protected $signature = 'providers:health';
    protected $description = 'Perform live health check and latency diagnostics across all data providers';

    public function handle(
        GlobalFishingWatchAdapter $gfw,
        CopernicusSarAdapter $copernicus,
        AisStreamAdapter $ais,
        FaoFishingAreaAdapter $fao,
        MarineRegionsEezAdapter $eez
    ): int {
        $this->info("=== DATA PROVIDER HEALTH & LATENCY AUDIT ===");
        $this->newLine();

        $providers = [
            'Global Fishing Watch (Events API)' => $gfw,
            'Copernicus Data Space (Sentinel-1 SAR)' => $copernicus,
            'AIS Live Stream / Ingestion' => $ais,
            'FAO Major Fishing Areas' => $fao,
            'Marine Regions EEZ' => $eez,
        ];

        $rows = [];
        foreach ($providers as $name => $adapter) {
            $check = $adapter->healthCheck();
            $statusColor = match ($check['status']) {
                'connected', 'receiving_data', 'streaming', 'authenticated' => 'green',
                'research_access_required', 'not_configured', 'reachable', 'awaiting_telemetry', 'stale', 'delayed' => 'yellow',
                default => 'red',
            };

            $statusLabel = match ($check['status']) {
                'research_access_required' => 'ACCESS DENIED (RESEARCH REQ)',
                default => strtoupper($check['status']),
            };

            $rows[] = [
                $name,
                "<fg={$statusColor}>" . $statusLabel . "</>",
                $check['latency_ms'] !== null ? "{$check['latency_ms']} ms" : 'N/A',
                $check['message'],
            ];
        }

        $this->table(['Provider', 'Status', 'Latency', 'Diagnostic Message'], $rows);
        $this->newLine();

        return self::SUCCESS;
    }
}
