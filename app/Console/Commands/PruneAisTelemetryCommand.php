<?php

namespace App\Console\Commands;

use App\Services\DataRetentionPolicyService;
use Illuminate\Console\Command;

class PruneAisTelemetryCommand extends Command
{
    protected $signature = 'ais:prune-telemetry
                            {--dry-run : Jalankan simulasi pemeriksaan tanpa menghapus data apapun}
                            {--force : Paksa eksekusi meskipun automatic cleanup dinonaktifkan di pengaturan}';

    protected $description = 'Pembersihan telemetri AIS historis berdasarkan kebijakan retensi per tipe kapal (Fishing terlindungi permanen)';

    public function handle(DataRetentionPolicyService $retentionService): int
    {
        $this->info('====================================================================');
        $this->info(' GFVIP - AIS HISTORICAL TELEMETRY RETENTION ENGINE');
        $this->info(' Perlindungan Permanen Kapal Penangkap Ikan (Fishing 100% Protected)');
        $this->info('====================================================================');

        $isDryRun = $this->option('dry-run');
        $isForce = $this->option('force');

        if ($isDryRun) {
            $this->warn('[MODE SIMULASI / DRY-RUN] Menghitung kandidat retensi tanpa mengubah database...');
            $simulation = $retentionService->simulateRetentionPurge();

            $this->newLine();
            $this->info("Total Posisi AIS Tersimpan: {$simulation['total_positions']}");
            $this->info("Posisi Fishing Terlindungi Permanen: {$simulation['fishing_positions_protected']} (0 dihapus / 100% aman)");
            $this->info("Kapal Bukti Operasional Terlindungi (SAR / Encounter / Investigasi): {$simulation['protected_evidence_vessels']}");
            $this->newLine();

            $tableRows = [];
            foreach ($simulation['breakdown'] as $type => $data) {
                $tableRows[] = [
                    $data['label'],
                    $data['retention_days'] ? "{$data['retention_days']} Hari" : 'Permanen (Protected)',
                    $data['cutoff_date'] ?: '— (Tidak Ada Cutoff)',
                    $data['candidates'],
                    $data['status'] === 'PROTECTED_PERMANENT' ? '<fg=green>TERLINDUNGI PERMANEN</>' : '<fg=yellow>Kandidat Retensi</>',
                ];
            }

            $this->table(
                ['Tipe Kapal', 'Kebijakan Retensi', 'Batas Waktu (Cutoff)', 'Kandidat Posisi Dihapus', 'Status Keamanan'],
                $tableRows
            );

            $this->newLine();
            $this->info("Total Posisi Memenuhi Syarat Purge: {$simulation['total_candidate_positions']}");
            $this->info("Estimasi Kapasitas Disk Dibebaskan: {$simulation['estimated_freed_size']}");
            $this->newLine();
            $this->line('<fg=cyan>Simulasi selesai. Tidak ada data yang dihapus dari database.</>');
            $this->info('====================================================================');

            return self::SUCCESS;
        }

        $settings = $retentionService->getSettings();

        if (!$settings->auto_cleanup_enabled && !$isForce) {
            $this->warn('[PERINGATAN] Automatic Cleanup saat ini dinonaktifkan (DISABLED) pada System Settings.');
            $this->line('Untuk menjalankan pembersihan manual, gunakan:');
            $this->line('<fg=green;options=bold>php artisan ais:prune-telemetry --force</>');
            $this->line('Atau jalankan simulasi aman terlebih dahulu:');
            $this->line('<fg=yellow;options=bold>php artisan ais:prune-telemetry --dry-run</>');
            $this->info('====================================================================');

            return self::SUCCESS;
        }

        $this->alert('MENJALANKAN PEMBERSIHAN RETENSI AIS SECARA AKTUAL');
        $this->line('Kapal Fishing dan referensi bukti operasional dikecualikan secara mutlak.');

        $result = $retentionService->executeRetentionPurge(force: $isForce, userId: null);

        if (!$result['success']) {
            $this->error('Gagal menjalankan pembersihan: ' . ($result['message'] ?? 'Unknown error'));
            return self::FAILURE;
        }

        $this->info('✓ ' . $result['message']);
        $this->newLine();

        $summary = $result['summary'] ?? [];
        $purgedByType = $summary['purged_by_type'] ?? [];

        $tableRows = [];
        foreach ($purgedByType as $type => $count) {
            $tableRows[] = [ucfirst($type), $count];
        }

        $this->table(['Tipe Kapal', 'Jumlah Posisi Dihapus'], $tableRows);
        $this->info("Posisi Fishing Dihapus: 0 (Terlindungi Permanen)");
        $this->info("Total Posisi Dihapus: {$result['records_purged']}");
        $this->info('====================================================================');

        return self::SUCCESS;
    }
}
