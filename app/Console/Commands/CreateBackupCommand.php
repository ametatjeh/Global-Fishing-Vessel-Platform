<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class CreateBackupCommand extends Command
{
    protected $signature = 'backup:create 
                            {--path= : Target backup directory (defaults to system backup root)}
                            {--retention=14 : Number of days to retain older dumps}';

    protected $description = 'Create an automated PostgreSQL database dump, archive SAR storage, and generate health manifest';

    public function handle(SystemHealthService $healthService): int
    {
        $backupRoot = $this->option('path') ?: $healthService->getBackupRootDir();
        $retentionDays = (int) $this->option('retention');

        $this->info("=== GFVIP AUTOMATED BACKUP OPERATION ===");
        $this->line("Target Directory : {$backupRoot}");
        $this->line("Retention Period : {$retentionDays} days");

        $dbBackupDir = $backupRoot . DIRECTORY_SEPARATOR . 'database';
        $sarBackupDir = $backupRoot . DIRECTORY_SEPARATOR . 'sar_storage';
        $logDir = $backupRoot . DIRECTORY_SEPARATOR . 'logs';

        foreach ([$backupRoot, $dbBackupDir, $sarBackupDir, $logDir] as $dir) {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true, true);
            }
        }

        $pgDumpBin = $this->resolvePgDumpBinary();
        if (!$pgDumpBin) {
            $this->error("Binary 'pg_dump' tidak ditemukan di PATH sistem atau lokasi standar.");
            $this->warn("Pada Ubuntu Linux, instal dengan perintah: sudo apt-get install -y postgresql-client");
            return self::FAILURE;
        }

        $this->line("Menggunakan binary: {$pgDumpBin}");

        // 1. Get database credentials
        $dbConn = config('database.default', 'pgsql');
        $dbHost = config("database.connections.{$dbConn}.host", '127.0.0.1');
        $dbPort = (string) config("database.connections.{$dbConn}.port", '5432');
        $dbName = config("database.connections.{$dbConn}.database", 'global_fishing_vessel_intelligence');
        $dbUser = config("database.connections.{$dbConn}.username", 'postgres');
        $dbPass = config("database.connections.{$dbConn}.password", '');

        $timestamp = now()->format('Ymd_His');
        $dumpFileName = "gfvip_db_{$timestamp}.dump";
        $dumpFilePath = $dbBackupDir . DIRECTORY_SEPARATOR . $dumpFileName;

        $this->line("Membuat snapshot PostgreSQL untuk database [{$dbName}]...");
        $startTime = microtime(true);

        $cmd = [
            $pgDumpBin,
            '-h', $dbHost,
            '-p', $dbPort,
            '-U', $dbUser,
            '-d', $dbName,
            '-F', 'c',
            '-b',
            '-f', $dumpFilePath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(600);
        $process->setEnv(['PGPASSWORD' => $dbPass]);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->error("Gagal menjalankan pg_dump: " . $e->getMessage());
            return self::FAILURE;
        }

        if (!$process->isSuccessful() || !File::exists($dumpFilePath) || File::size($dumpFilePath) === 0) {
            $this->error("Eksekusi pg_dump gagal: " . $process->getErrorOutput());
            return self::FAILURE;
        }

        $dumpSize = File::size($dumpFilePath);
        $dumpSizeMb = round($dumpSize / 1048576, 2);
        $this->info("✓ PostgreSQL dump berhasil: {$dumpFileName} ({$dumpSizeMb} MB)");

        // 2. Synchronize SAR Storage Evidence
        $sarProductsDir = storage_path('app/sar_products');
        $sarEvidenceDir = storage_path('app/sar_evidence');
        $sarProductsCount = 0;
        $sarEvidenceCount = 0;

        if (File::isDirectory($sarProductsDir)) {
            $files = File::allFiles($sarProductsDir);
            $sarProductsCount = count($files);
            File::copyDirectory($sarProductsDir, $sarBackupDir . DIRECTORY_SEPARATOR . 'products');
        }

        if (File::isDirectory($sarEvidenceDir)) {
            $files = File::allFiles($sarEvidenceDir);
            $sarEvidenceCount = count($files);
            File::copyDirectory($sarEvidenceDir, $sarBackupDir . DIRECTORY_SEPARATOR . 'evidence');
        }

        $this->line("✓ Bukti SAR disinkronkan: {$sarProductsCount} products, {$sarEvidenceCount} evidence crops.");

        // 3. Prune old dumps according to retention days safely
        $this->pruneExpiredDumps($dbBackupDir, $retentionDays);

        // 4. Capture database snapshot metrics
        $snapshotCounts = [];
        try {
            $snapshotCounts = [
                'satellite_detections' => DB::table('satellite_detections')->count(),
                'satellite_scenes' => DB::table('satellite_scenes')->count(),
                'vessels' => DB::table('vessels')->count(),
                'vessel_positions' => DB::table('vessel_positions')->count(),
                'audit_logs' => DB::table('audit_logs')->count(),
            ];
        } catch (\Throwable $e) {
            $this->warn("Peringatan: Gagal menghitung snapshot rekor: " . $e->getMessage());
        }

        $totalDuration = round(microtime(true) - $startTime, 2);

        // 5. Generate latest_backup_manifest.json
        $manifest = [
            'status' => 'SUCCESS',
            'timestamp_utc' => now()->toIso8601String(),
            'database' => [
                'database_name' => $dbName,
                'dump_file' => $dumpFileName,
                'dump_path' => $dumpFilePath,
                'size_bytes' => $dumpSize,
                'size_mb' => $dumpSizeMb,
            ],
            'snapshot_records' => $snapshotCounts,
            'sar_storage' => [
                'products_file_count' => $sarProductsCount,
                'evidence_file_count' => $sarEvidenceCount,
            ],
            'retention_days' => $retentionDays,
            'duration_seconds' => $totalDuration,
            'gfvip_version' => '2.0-GFVIP',
            'created_via' => 'artisan backup:create',
        ];

        $manifestFile = $backupRoot . DIRECTORY_SEPARATOR . 'latest_backup_manifest.json';
        File::put($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info("✓ Manifest backup berhasil dibuat di: {$manifestFile}");
        $this->info("=== OPERASI BACKUP SELESAI DALAM {$totalDuration} DETIK ===");

        return self::SUCCESS;
    }

    protected function resolvePgDumpBinary(): ?string
    {
        // 1. Check if configured in environment
        $configured = env('PG_DUMP_PATH');
        if ($configured && (File::exists($configured) || is_executable($configured))) {
            return $configured;
        }

        // 2. Standard Linux candidates
        $linuxCandidates = [
            '/usr/bin/pg_dump',
            '/usr/lib/postgresql/18/bin/pg_dump',
            '/usr/lib/postgresql/17/bin/pg_dump',
            '/usr/lib/postgresql/16/bin/pg_dump',
            '/usr/local/bin/pg_dump',
        ];

        foreach ($linuxCandidates as $cand) {
            if (File::exists($cand) && is_executable($cand)) {
                return $cand;
            }
        }

        // 3. Standard Windows candidates
        $winCandidates = [
            'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe',
            'C:\\Program Files\\PostgreSQL\\17\\bin\\pg_dump.exe',
            'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe',
        ];

        foreach ($winCandidates as $cand) {
            if (File::exists($cand)) {
                return $cand;
            }
        }

        // 4. Check system PATH via 'which' or 'where'
        $checkCmd = PHP_OS_FAMILY === 'Windows' ? 'where pg_dump' : 'which pg_dump';
        $output = @shell_exec($checkCmd);
        if ($output) {
            $lines = explode("\n", trim($output));
            $found = trim($lines[0]);
            if (!empty($found) && (File::exists($found) || is_executable($found))) {
                return $found;
            }
        }

        return null;
    }

    protected function pruneExpiredDumps(string $dbBackupDir, int $retentionDays): void
    {
        $allDumps = collect(File::files($dbBackupDir))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'gfvip_db_') && str_ends_with($f->getFilename(), '.dump'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        // Never prune if only 1 dump exists; always preserve at least the newest dump
        if ($allDumps->count() <= 1) {
            return;
        }

        $cutoffTimestamp = Carbon::now()->subDays($retentionDays)->getTimestamp();

        // Skip the newest dump (index 0)
        $candidates = $allDumps->slice(1);
        foreach ($candidates as $oldDump) {
            if ($oldDump->getMTime() < $cutoffTimestamp) {
                File::delete($oldDump->getRealPath());
                $this->line("Rotasi: Menghapus dump kedaluwarsa {$oldDump->getFilename()}");
            }
        }
    }
}
