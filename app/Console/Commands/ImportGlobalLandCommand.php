<?php

namespace App\Console\Commands;

use App\Services\GlobalLandValidationService;
use Illuminate\Console\Command;

class ImportGlobalLandCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'land:import
                            {--file= : Path to custom GeoJSON file}
                            {--force : Truncate and re-import existing land polygon records}
                            {--download : Download newest Natural Earth 50m land dataset directly from GitHub}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import or update official Natural Earth global land polygon dataset into PostGIS database';

    public function handle(GlobalLandValidationService $service): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->line('<fg=cyan;options=bold>   GLOBAL MARITIME INTELLIGENCE - NATURAL EARTH LAND IMPORTER</>');
        $this->line('<fg=cyan;options=bold>========================================================================</>');
        $this->newLine();

        $filePath = $this->option('file');
        $force = (bool) $this->option('force');
        $download = (bool) $this->option('download');

        $defaultPath = database_path('seeders/data/ne_50m_land.geojson');

        if ($download || (!file_exists($defaultPath) && !$filePath)) {
            $this->info(" Downloading Natural Earth 1:50m Land polygon dataset...");
            $url = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_land.geojson';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $content) {
                file_put_contents($defaultPath, $content);
                $this->info(" Download completed successfully (" . round(strlen($content) / 1024 / 1024, 2) . " MB).");
            } else {
                $this->error(" Failed to download dataset from {$url} (HTTP {$httpCode})");
                return Command::FAILURE;
            }
        }

        $targetFile = $filePath ?: $defaultPath;
        $this->info(" Importing polygons from: {$targetFile}");
        if ($force) {
            $this->warn(" [FORCE MODE] Existing land polygon records will be replaced.");
        }

        try {
            $result = $service->importDataset($targetFile, $force);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Features in File', number_format($result['total_features'])],
                    ['Inserted PostGIS Polygons', number_format($result['inserted'])],
                    ['Execution Duration', "{$result['duration_sec']} seconds"],
                    ['PostGIS GiST Index', '<fg=green>ACTIVE & INDEXED (SRID 4326)</>'],
                ]
            );

            $this->info(" Global land polygon dataset successfully loaded and ready for ST_Covers spatial queries.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(" Import failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

