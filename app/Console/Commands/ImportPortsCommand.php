<?php

namespace App\Console\Commands;

use App\Services\PortGeofenceService;
use Illuminate\Console\Command;

class ImportPortsCommand extends Command
{
    protected $signature = 'ports:import
                            {--file= : Custom JSON file path}
                            {--force : Truncate and re-import existing ports}';

    protected $description = 'Import official NGA World Port Index (WPI) global port dataset and initialize exclusion buffers in PostGIS';

    public function handle(PortGeofenceService $service): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->line('<fg=cyan;options=bold>   GLOBAL MARITIME INTELLIGENCE - WORLD PORT INDEX (WPI) IMPORTER</>');
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->newLine();

        $filePath = $this->option('file');
        $force = (bool) $this->option('force');

        $defaultPath = database_path('seeders/data/world_port_index.json');
        $targetFile = $filePath ?: $defaultPath;

        if (!file_exists($targetFile)) {
            $this->error("Port dataset not found at {$targetFile}.");
            return Command::FAILURE;
        }

        $this->info("Importing official world ports from: {$targetFile}");
        if ($force) {
            $this->warn("[FORCE MODE] Existing port records will be replaced.");
        }

        try {
            $result = $service->importDataset($targetFile, $force);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Ports in File', number_format($result['total_in_file'])],
                    ['Inserted PostGIS Port Records', number_format($result['inserted'])],
                    ['Execution Duration', "{$result['duration_sec']} seconds"],
                    ['Port Buffer Geofence', '<fg=green>ACTIVE & INDEXED (SRID 4326)</>'],
                ]
            );

            $this->info("✓ World port dataset successfully loaded. Open sea encounter geofencing is now fully active.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Import failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
