<?php

namespace App\Console\Commands;

use App\Models\SatelliteScene;
use App\Services\Sar\SarProcessingPipelineService;
use Illuminate\Console\Command;

class ProcessSarSceneCommand extends Command
{
    protected $signature = 'sar:process 
                            {scene? : Specific SatelliteScene ID (defaults to latest cataloged scene)}
                            {--allow-download : Allow live download from Copernicus CDSE}';

    protected $description = 'Execute staged processing pipeline on a single Sentinel-1 SAR scene';

    public function handle(SarProcessingPipelineService $pipelineService): int
    {
        $sceneId = $this->argument('scene');
        $allowDownload = (bool) $this->option('allow-download');

        if (!$sceneId) {
            $scene = SatelliteScene::latest('acquisition_start')->first();
            if (!$scene) {
                $this->error('Tidak ada scene Sentinel-1 di katalog database.');
                return self::FAILURE;
            }
            $sceneId = $scene->scene_id;
        }

        $this->info("=== GFVIP SENTINEL-1 SAR STAGED PROCESSING PIPELINE ===");
        $this->line("Target Scene ID : {$sceneId}");
        $this->line("Mode Download   : " . ($allowDownload ? 'Diizinkan (--allow-download)' : 'Pemeriksaan Integritas & Validasi Katalog'));

        $result = $pipelineService->processScene($sceneId, [
            'allow_download' => $allowDownload,
        ]);

        $this->line('');
        $this->info("Hasil Tahap Akhir: {$result['status']} (Tahap: {$result['stage']})");
        $this->line("Pesan: {$result['message']}");

        if (isset($result['candidates_count'])) {
            $this->info("Kandidat Target Terdeteksi : {$result['candidates_count']} objek");
        }

        if (!empty($result['correlation_summary'])) {
            $this->line("Ringkasan Korelasi AIS   : " . json_encode($result['correlation_summary']));
        }

        if (isset($result['duration_seconds'])) {
            $this->line("Total Durasi Eksekusi    : {$result['duration_seconds']} detik");
        }

        if (!empty($result['dependencies_required'])) {
            $this->warn("\nDependensi yang Diperlukan untuk Pemrosesan Citra:");
            foreach ($result['dependencies_required'] as $dep => $info) {
                $this->line("- {$dep}: {$info}");
            }
        }

        if (!empty($result['stages_executed'])) {
            $this->line("\nJejak Tahapan Eksekusi:");
            foreach ($result['stages_executed'] as $stageName => $meta) {
                $status = $meta['status'] ?? 'unknown';
                $extra = '';
                if ($stageName === 'downloaded' && isset($meta['file_size_mb'])) {
                    $extra = " ({$meta['file_size_mb']} MB)";
                } elseif ($stageName === 'integrity_verified' && isset($meta['md5'])) {
                    $extra = " (MD5: {$meta['md5']})";
                } elseif ($stageName === 'preprocessed' && isset($meta['estimated_mean_backscatter_db'])) {
                    $extra = " (Backscatter: {$meta['estimated_mean_backscatter_db']} dB)";
                } elseif ($stageName === 'detection_completed' && isset($meta['candidates_count'])) {
                    $extra = " ({$meta['candidates_count']} kandidat tervalidasi)";
                }
                $this->line("  [✓] {$stageName}: {$status}{$extra}");
            }
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
