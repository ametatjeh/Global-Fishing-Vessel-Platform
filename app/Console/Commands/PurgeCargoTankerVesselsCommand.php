<?php

namespace App\Console\Commands;

use App\Services\VesselPurgeService;
use Illuminate\Console\Command;

class PurgeCargoTankerVesselsCommand extends Command
{
    protected $signature = 'vessels:purge-cargo-tanker 
                            {--confirm : Konfirmasi eksplisit untuk menjalankan penghapusan aktual}
                            {--dry-run : Paksa mode simulasi pemeriksaan tanpa menghapus data}';

    protected $description = 'Audit dan pembersihan terkontrol data internal kapal non-fishing (Cargo, Tanker, Pleasure) (GFVIP)';

    public function handle(VesselPurgeService $purgeService): int
    {
        $this->info('====================================================================');
        $this->info(' GFVIP - AUDIT & PEMBERSIHAN DATA KAPAL NON-FISHING (CARGO, TANKER, PLEASURE)');
        $this->info('====================================================================');

        $isDryRun = !$this->option('confirm') || $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[MODE DRY-RUN AKTIF] Pemeriksaan simulasi saja. Database tidak diubah.');
            $result = $purgeService->executePurge(dryRun: true);
            $inspection = $result['inspection'];

            $this->renderReportTable($inspection);

            $this->newLine();
            $this->line('<fg=yellow;options=bold>PERINGATAN ADMINISTRATOR:</>');
            $this->line('Pemeriksaan dry-run selesai. Seluruh referensi historis dan bukti investigasi terlindungi.');
            $this->line('Untuk mengeksekusi pembersihan aktual setelah persetujuan administrator, jalankan:');
            $this->line('<fg=green;options=bold>php artisan vessels:purge-cargo-tanker --confirm</>');
            $this->info('====================================================================');

            return self::SUCCESS;
        }

        $this->alert('PERINGATAN: MENJALANKAN PENGHAPUSAN AKTUAL DATA INTERNAL CARGO, TANKER & PLEASURE');
        $this->line('Membuat backup snapshot sebelum pembersihan...');

        $result = $purgeService->executePurge(dryRun: false);

        if ($result['status'] === 'completed') {
            $this->info('✓ Backup snapshot tersimpan di: ' . ($result['backup_file'] ?? 'manifest generated'));
            $this->info('✓ ' . $result['message']);
            $this->newLine();

            $deleted = $result['deleted'];
            $this->table(
                ['Kategori / Objek Database', 'Jumlah Dihapus'],
                [
                    ['Kapal Cargo (vessels)', $deleted['cargo_vessels']],
                    ['Kapal Tanker (vessels)', $deleted['tanker_vessels']],
                    ['Kapal Pleasure (vessels)', $deleted['pleasure_vessels']],
                    ['Total Kapal Dihapus', $deleted['total_vessels']],
                    ['Kapal Dilindungi / Dipertahankan (Encounter/SAR)', $result['protected_vessels_preserved'] ?? 0],
                    ['Posisi Terkait Dihapus (vessel_positions)', $deleted['positions']],
                    ['Identifier Terkait Dihapus (vessel_identifiers)', $deleted['identifiers']],
                    ['Jalur Track Terkait Dihapus (vessel_tracks)', $deleted['tracks']],
                    ['MMSI Didaftarkan ke Prohibited Registry', $result['prohibited_mmsis_registered']],
                ]
            );

            $remaining = $result['remaining_vessels'];
            $this->info(sprintf(
                'Data kapal yang tetap utuh: %d Kapal Fishing, %d Kapal Lainnya (SAR, Patrol, Support, Research).',
                $remaining['fishing'],
                $remaining['other']
            ));

            $this->info('Audit log penghapusan telah dicatat.');
            $this->info('====================================================================');

            return self::SUCCESS;
        }

        $this->error('Pembersihan gagal: ' . ($result['message'] ?? 'Unknown error'));
        return self::FAILURE;
    }

    protected function renderReportTable(array $inspection): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>1. Ringkasan Kapal Target Ditemukan:</>');
        $this->table(
            ['Kategori', 'Jumlah Kapal', 'MMSI Teridentifikasi'],
            [
                ['Cargo', $inspection['cargo']['count'], implode(', ', array_column($inspection['cargo']['vessels'], 'mmsi')) ?: '-'],
                ['Tanker', $inspection['tanker']['count'], implode(', ', array_column($inspection['tanker']['vessels'], 'mmsi')) ?: '-'],
                ['Pleasure Craft', $inspection['pleasure']['count'], implode(', ', array_column($inspection['pleasure']['vessels'], 'mmsi')) ?: '-'],
                ['Total Target Teridentifikasi', $inspection['total_target_vessels'], implode(', ', $inspection['target_mmsis']) ?: '-'],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan;options=bold>2. Estimasi Objek Database yang Terdampak:</>');
        $this->table(
            ['Tabel Operasional', 'Status & Jumlah Record'],
            [
                ['vessels (Total Ditemukan)', $inspection['impacted_records']['vessels_total']],
                ['vessels (Dapat Dihapus Aman)', $inspection['impacted_records']['vessels_deletable']],
                ['vessels (Dilindungi - Terikat Encounter/SAR)', $inspection['impacted_records']['vessels_protected']],
                ['vessel_positions (Total Ditemukan)', $inspection['impacted_records']['vessel_positions']],
                ['vessel_positions (Dihapus dari Kapal Bebas)', $inspection['impacted_records']['vessel_positions_deletable']],
                ['vessel_identifiers', $inspection['impacted_records']['vessel_identifiers']],
                ['vessel_tracks', $inspection['impacted_records']['vessel_tracks']],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan;options=bold>3. Referensi & Bukti Investigasi yang Dilindungi (TIDAK Dihapus):</>');
        $prot = $inspection['protected_references'];
        $this->table(
            ['Entitas Bukti / Referensi', 'Status Integritas'],
            [
                ['vessel_encounters (Vessel 1)', ($prot['vessel_encounters_v1'] ?? 0) . ' referensi (Dilindungi)'],
                ['vessel_encounters (Vessel 2)', ($prot['vessel_encounters_v2'] ?? 0) . ' referensi (Dilindungi)'],
                ['encounter_vessels', ($prot['encounter_vessels'] ?? 0) . ' referensi (Dilindungi)'],
                ['sar_correlations (satellite_detections)', ($prot['sar_correlations'] ?? 0) . ' korelasi (Dilindungi)'],
                ['investigations', ($prot['investigations'] ?? 0) . ' investigasi aktif (Dilindungi)'],
                ['compliance_events', ($prot['compliance_events'] ?? 0) . ' pelanggaran kepatuhan (Dilindungi)'],
                ['audit_logs', 'Seluruh riwayat audit dipertahankan utuh'],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan;options=bold>4. Data Operasional yang Tetap Utuh:</>');
        $this->line(sprintf(' - Kapal Fishing: <fg=green;options=bold>%d</>', $inspection['fishing_vessels_remaining']));
        $this->line(sprintf(' - Kapal Lainnya (Patrol, Support, Research): <fg=green;options=bold>%d</>', $inspection['other_vessels_remaining']));
    }
}
