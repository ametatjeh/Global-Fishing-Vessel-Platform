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
            $this->info("Posisi Fishing Terverifikasi (Permanent): {$simulation['verified_fishing_positions']} (0 dihapus / 100% aman)");
            $this->info("Posisi Fishing Belum Terverifikasi (Protective Hold): {$simulation['unverified_fishing_positions']} (0 dihapus / ditahan)");
            $this->info("Lima Kapal Historis Terlindungi: {$simulation['historical_vessels_protected']} kapal ({$simulation['historical_vessel_positions_protected']} posisi aman)");
            $this->info("Kapal Bukti Operasional Terlindungi (SAR / Encounter / Dark Vessel / Investigasi): {$simulation['protected_evidence_vessels']}");
            $this->info("Posisi Terkait Dark Vessel Gap Indicator Terlindungi: {$simulation['dark_vessel_positions_protected']}");
            $this->newLine();

            $tableRows = [];
            foreach ($simulation['breakdown'] as $type => $data) {
                $statusColor = match ($data['status']) {
                    'PROTECTED_PERMANENT' => '<fg=green>PERMANEN (FISHING)</>',
                    'PROTECTIVE_HOLD' => '<fg=cyan>PROTECTIVE HOLD</>',
                    'ELIGIBLE_FOR_PURGE' => '<fg=yellow>Kandidat Retensi</>',
                    'POLICY_DISABLED' => '<fg=gray>Kebijakan Nonaktif</>',
                    default => '<fg=white>Tidak Ada Data</>',
                };

                $tableRows[] = [
                    $data['label'],
                    $data['retention_days'] ? "{$data['retention_days']} Hari" : 'Permanen',
                    $data['cutoff_date'] ?: '—',
                    $data['candidates'],
                    $data['held'] ?? 0,
                    $statusColor,
                ];
            }

            $this->table(
                ['Tipe Kapal', 'Kebijakan', 'Batas Waktu (Cutoff)', 'Kandidat Purge', 'Ditahan (Hold)', 'Status Keamanan'],
                $tableRows
            );

            $this->newLine();
            $this->info("Total Posisi Memenuhi Syarat Purge: {$simulation['total_candidate_positions']}");
            $this->info("Total Posisi Ditahan (Protective Hold & Evidence): {$simulation['held_positions_count']}");
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
