<?php

namespace App\Console\Commands;

use App\Services\Providers\FaoFishingAreaAdapter;
use Illuminate\Console\Command;

class ImportFaoAreasCommand extends Command
{
    protected $signature = 'fao:import {--file= : Path to custom GeoJSON file} {--dry-run : Perform validation without inserting records}';
    protected $description = 'Import official FAO Major Fishing Areas dataset into spatial database';

    public function handle(FaoFishingAreaAdapter $adapter): int
    {
        $this->info("Memulai proses impor FAO Major Fishing Areas...");
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
            $this->info("✓ Proses impor FAO Major Fishing Areas selesai dengan sukses.");
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
