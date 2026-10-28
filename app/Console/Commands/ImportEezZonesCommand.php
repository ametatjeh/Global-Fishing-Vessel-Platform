<?php

namespace App\Console\Commands;

use App\Services\Providers\MarineRegionsEezAdapter;
use Illuminate\Console\Command;

class ImportEezZonesCommand extends Command
{
    protected $signature = 'eez:import {--file= : Path to custom GeoJSON file} {--dry-run : Perform validation without inserting records}';
    protected $description = 'Import official Marine Regions EEZ dataset into spatial database';

    public function handle(MarineRegionsEezAdapter $adapter): int
    {
        $this->info("Memulai proses impor Marine Regions EEZ Boundaries...");
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn("[DRY-RUN MODE AKTIF] Tidak ada data yang akan disimpan ke database.");
        }

        $options = [
            'dry_run' => $dryRun,
        ];

        if ($this->option('file')) {
            $options['path'] = $this->option('file');
        }

        $result = $adapter->sync($options);

        $this->table(
            ['Status', 'Fetched', 'Inserted / Validated', 'Skipped', 'Errors'],
            [
                [
                    $result['status'],
                    $result['fetched'],
                    $result['inserted'],
                    $result['skipped'],
                    count($result['errors']),
                ]
            ]
        );

        if (!empty($result['errors'])) {
            $this->error("Terdapat beberapa catatan error selama proses:");
            foreach (array_slice($result['errors'], 0, 5) as $err) {
                $this->line("- " . $err);
            }
        }

        if ($result['status'] === 'completed') {
            $this->info("✓ Proses impor Marine Regions EEZ selesai dengan sukses.");
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
