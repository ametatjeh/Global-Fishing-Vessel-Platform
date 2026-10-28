<?php

namespace App\Console\Commands;

use App\Services\Sar\SarProcessingPipelineService;
use Illuminate\Console\Command;

class AuditSarPipelineCommand extends Command
{
    protected $signature = 'sar:audit-pipeline {scene? : Specific SatelliteScene ID to inspect}';
    protected $description = 'Audit the 10-stage Sentinel-1 SAR imagery ingestion and vessel detection pipeline readiness';

    public function handle(SarProcessingPipelineService $sarService): int
    {
        $sceneId = $this->argument('scene');

        $this->info('=== SENTINEL-1 SAR PIPELINE READINESS AUDIT (10 STAGES) ===');

        $audit = $sarService->auditPipelineReadiness($sceneId);

        $rows = [];
        foreach ($audit['stages'] as $stage) {
            $rows[] = [
                'Tahap ' . $stage['stage'],
                $stage['name'],
                $stage['status'],
                wordwrap($stage['details'], 60, "\n"),
            ];
        }

        $this->table(['Tahap', 'Nama Komponen', 'Status Kesiapan', 'Detail / Rasionil'], $rows);

        $this->line('');
        $this->info("Status Kesiapan Keseluruhan: {$audit['overall_status']}");
        $this->line("Kapasitas Disk: {$audit['system_capacity']['free_disk_gb']} GB tersedia (min: {$audit['system_capacity']['min_required_disk_gb']} GB)");
        $this->line("Rekomendasi RAM: {$audit['system_capacity']['recommended_ram_gb']} GB per worker ESA SNAP/GDAL");

        if ($audit['selected_test_scene']) {
            $this->line('');
            $this->info('Scene Uji Terpilih: ' . $audit['selected_test_scene']['scene_id']);
            $this->line("Platform: {$audit['selected_test_scene']['platform']} | Mode: {$audit['selected_test_scene']['mode']} | Polarisasi: {$audit['selected_test_scene']['polarization']}");
            $this->line("Waktu Akuisisi: {$audit['selected_test_scene']['acquisition_start']} | Status: {$audit['selected_test_scene']['processing_status']}");
        }

        return self::SUCCESS;
    }
}
