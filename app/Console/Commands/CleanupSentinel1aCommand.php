<?php

namespace App\Console\Commands;

use App\Services\Sar\Sentinel1aCleanupService;
use Illuminate\Console\Command;

class CleanupSentinel1aCommand extends Command
{
    protected $signature = 'sentinel:cleanup-s1a
                            {--dry-run : Jalankan simulasi pemeriksaan tanpa menghapus data atau file}
                            {--confirm-s1a-only : Konfirmasi eksplisit untuk mengeksekusi penghapusan aktual Sentinel-1A}
                            {--backup-path= : Lokasi direktori backup kustom (opsional)}';

    protected $description = 'Pembersihan menyeluruh dan aman data serta aset Copernicus Sentinel-1A (GFVIP)';

    public function handle(Sentinel1aCleanupService $cleanupService): int
    {
        $this->info('====================================================================');
        $this->info(' GFVIP - COPERNICUS SENTINEL-1A PRODUCTION-SAFE CLEANUP PIPELINE');
        $this->info('====================================================================');

        $isDryRun = (bool) $this->option('dry-run');
        $isConfirmed = (bool) $this->option('confirm-s1a-only');
        $customBackup = $this->option('backup-path');

        // Step 1: Pre-flight Inventory
        $this->line('Menjalankan inventarisasi aset dan verifikasi perlindungan Sentinel-1B...');
        $inventory = $cleanupService->conductInventory();

        $this->renderInventoryTables($inventory);

        // Step 2: Check Mode and Confirmation
        if ($isDryRun || !$isConfirmed) {
            $this->newLine();
            if (!$isConfirmed && !$isDryRun) {
                $this->warn('PERINGATAN: Penghapusan aktual ditolak karena flag konfirmasi tidak diberikan.');
                $this->line('Perintah ini berjalan otomatis dalam mode <fg=yellow;options=bold>SIMULASI (DRY-RUN)</>.');
            } else {
                $this->info('[MODE DRY-RUN AKTIF] Menjalankan simulasi tanpa mengubah database atau file.');
            }

            $result = $cleanupService->executeCleanup($inventory, dryRun: true, customBackupPath: $customBackup);

            $this->newLine();
            $this->line('<fg=cyan;options=bold>Rencana Tindakan Penghapusan:</>');
            $this->table(
                ['Komponen / Aset', 'Jumlah Target', 'Status Eksekusi'],
                [
                    ['Scene Sentinel-1A (satellite_scenes)', $result['planned_scenes_deleted'], 'Akan Dihapus (Transactional)'],
                    ['Deteksi SAR Terkait (satellite_detections)', $result['planned_detections_deleted'], 'Akan Dihapus (Transactional)'],
                    ['Investigasi Terkait (investigations)', $result['planned_investigations_decoupled'], 'Akan Didecouple (Audit Preserved)'],
                    ['File Fisik (SAFE/ZIP/TIFF/Evidence)', $result['planned_files_deleted'], 'Akan Dihapus (Post-DB Verification)'],
                    ['Estimasi Ukuran File', $this->formatBytes($result['planned_file_bytes']), '-'],
                    ['Scene Sentinel-1B Terlindungi', $result['protected_scenes_count'], 'TETAP UTUH & TERLINDUNGI'],
                    ['Deteksi Sentinel-1B Terlindungi', $result['protected_detections_count'], 'TETAP UTUH & TERLINDUNGI'],
                ]
            );

            $this->newLine();
            $this->line('Lokasi rencana backup: <fg=yellow>' . $result['backup_destination'] . '</>');
            $this->newLine();
            $this->line('<fg=yellow;options=bold>PANDUAN EKSEKUSI PRODUKSI:</>');
            $this->line('Untuk mengeksekusi penghapusan aktual setelah inventarisasi dan persetujuan diverifikasi:');
            $this->line('<fg=green;options=bold>php artisan sentinel:cleanup-s1a --confirm-s1a-only</>');
            $this->info('====================================================================');

            return self::SUCCESS;
        }

        // Step 3: Actual Execution (Explicitly Confirmed)
        $this->alert('MENJALANKAN PENGHAPUSAN AKTUAL ASET SENTINEL-1A');
        $this->line('Membuat snapshot backup database dan file fisik...');

        try {
            $result = $cleanupService->executeCleanup($inventory, dryRun: false, customBackupPath: $customBackup);

            $this->info('✓ Backup snapshot berhasil dibuat di: ' . $result['backup_directory']);
            $this->info('✓ ' . $result['message']);
            $this->newLine();

            $this->table(
                ['Komponen / Aset', 'Hasil Pembersihan'],
                [
                    ['Scene Sentinel-1A Dihapus', $result['deleted_scenes_count']],
                    ['Deteksi SAR Sentinel-1A Dihapus', $result['deleted_detections_count']],
                    ['Investigasi Terkait Didecouple & Dicatat', $result['decoupled_investigations_count']],
                    ['File Fisik Sentinel-1A Dihapus', $result['deleted_files_count']],
                    ['Direktori Kosong S1A Dihapus', $result['deleted_dirs_count']],
                    ['Scene Sentinel-1B / Non-S1A Tetap Utuh', $result['protected_scenes_count']],
                    ['Deteksi Sentinel-1B / Non-S1A Tetap Utuh', $result['protected_detections_count']],
                ]
            );

            $this->info('✓ Jejak audit telah dicatat dalam tabel audit_logs.');
            $this->info('✓ Cache dan referensi indeks terkait Sentinel-1A telah diperbarui.');
            $this->info('====================================================================');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Pembersihan gagal dieksekusi: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
    }

    protected function renderInventoryTables(array $inventory): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>1. Inventarisasi Scene Sentinel-1A Teridentifikasi:</>');
        if (empty($inventory['s1a_scenes'])) {
            $this->line('  <fg=green>✓ Tidak ditemukan scene Sentinel-1A dalam database.</>');
        } else {
            $rows = [];
            foreach ($inventory['s1a_scenes'] as $s) {
                $rows[] = [
                    $s->id,
                    $s->scene_id,
                    $s->platform_name,
                    $s->acquisition_start?->toDateTimeString() ?? '-',
                    $inventory['s1a_detections']->where('satellite_scene_id', $s->id)->count(),
                ];
            }
            $this->table(['UUID', 'Scene Identifier', 'Platform', 'Acquisition Time', 'Detections'], $rows);
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>2. Status Perlindungan Aset Sentinel-1B / Non-S1A:</>');
        $this->table(
            ['Kategori Aset', 'Status', 'Keterangan'],
            [
                ['Scene Sentinel-1B / Non-S1A', $inventory['protected_scenes_count'], 'Dilindungi secara ketat, tidak termasuk target'],
                ['Deteksi Sentinel-1B / Non-S1A', $inventory['protected_detections_count'], 'Dilindungi secara ketat, tidak termasuk target'],
                ['File Sentinel-1B / Non-S1A', $inventory['protected_files_count'] . ' items', 'Dilindungi secara ketat, tidak dihapus'],
                ['Integrasi Copernicus OData', 'Aktif', 'Tersedia untuk akuisisi Sentinel-1B / data baru'],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan;options=bold>3. File Fisik Sentinel-1A yang Terdeteksi di Storage:</>');
        if (empty($inventory['files_to_delete'])) {
            $this->line('  <fg=green>✓ Tidak ditemukan file fisik Sentinel-1A di direktori storage.</>');
        } else {
            $sampleFiles = array_slice($inventory['files_to_delete'], 0, 10);
            $fileRows = array_map(fn($f) => [$f['relative'], $this->formatBytes($f['size'])], $sampleFiles);
            $this->table(['Relative File Path', 'Size'], $fileRows);
            if (count($inventory['files_to_delete']) > 10) {
                $this->line(sprintf('  ... dan %d file lainnya (Total: %d file, %s).',
                    count($inventory['files_to_delete']) - 10,
                    count($inventory['files_to_delete']),
                    $this->formatBytes($inventory['total_file_bytes'])
                ));
            }
        }
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
