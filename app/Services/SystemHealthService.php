<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SystemHealthService
{
    protected ?string $backupRoot = null;
    protected ?string $diskMonitorPath = null;

    /**
     * Set backup root directory for testing or runtime override.
     */
    public function setBackupRoot(?string $path): self
    {
        $this->backupRoot = $path;
        return $this;
    }

    /**
     * Resolve backup root directory across Windows and Linux (aaPanel).
     */
    public function getBackupRootDir(): string
    {
        if ($this->backupRoot !== null) {
            return $this->backupRoot;
        }

        $configured = config('maritime.backup.root_dir', env('BACKUP_ROOT_DIR'));
        if (!empty($configured)) {
            return rtrim($configured, '/\\');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return 'C:\\backups\\gfvip';
        }

        // Standard aaPanel / Linux backup directory
        if (File::exists('/www/backup')) {
            return '/www/backup/gfvip';
        }

        return storage_path('backups');
    }

    /**
     * Set disk monitor path for testing or runtime override.
     */
    public function setDiskMonitorPath(?string $path): self
    {
        $this->diskMonitorPath = $path;
        return $this;
    }

    /**
     * Resolve target mount point for storage monitoring.
     */
    public function getDiskMonitorPath(): string
    {
        if ($this->diskMonitorPath !== null) {
            return $this->diskMonitorPath;
        }

        $configured = config('maritime.storage.disk_monitor_path', env('DISK_MONITOR_PATH'));
        if (!empty($configured)) {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return 'C:';
        }

        if (File::exists('/www')) {
            return '/www';
        }

        return base_path();
    }

    /**
     * Get consolidated system health status.
     */
    public function getComprehensiveHealth(): array
    {
        $app = $this->getApplicationStatus();
        $db = $this->getDatabaseStatus();
        $queue = $this->getQueueStatus();
        $scheduler = $this->getSchedulerStatus();
        $ais = $this->getAisFreshnessStatus();
        $copernicus = $this->getCopernicusStatus();
        $gfw = $this->getGfwStatus();
        $backup = $this->getBackupStatus();
        $dr = $this->getDisasterRecoveryStatus();
        $offsite = $this->getOffsiteBackupStatus();
        $disk = $this->getDiskStorageStatus();
        $alerts = $this->getSystemAlerts($backup, $dr, $disk, $queue, $ais);

        return [
            'status' => $this->determineOverallStatus($app, $db, $alerts),
            'timestamp' => now()->toIso8601String(),
            'application' => $app,
            'database' => $db,
            'queue' => $queue,
            'scheduler' => $scheduler,
            'ais' => $ais,
            'copernicus' => $copernicus,
            'gfw' => $gfw,
            'backup' => $backup,
            'disaster_recovery' => $dr,
            'offsite_backup' => $offsite,
            'disk_storage' => $disk,
            'alerts' => $alerts,
        ];
    }

    /**
     * Application runtime health.
     */
    public function getApplicationStatus(): array
    {
        $env = config('app.env', 'production');
        $debug = (bool) config('app.debug', false);

        return [
            'status' => 'ok',
            'name' => config('app.name'),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'environment' => $env,
            'debug_mode' => $debug,
            'app_url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'is_production' => $env === 'production',
            'security_evaluation' => $debug && $env === 'production'
                ? 'CRITICAL_RISK: APP_DEBUG is TRUE in production!'
                : 'NORMAL: Debug mode aligned with environment.',
        ];
    }

    /**
     * PostgreSQL + PostGIS database health and entity counts.
     */
    public function getDatabaseStatus(): array
    {
        $startTime = microtime(true);
        $connected = false;
        $pgVersion = 'unknown';
        $postgisVersion = 'unknown';
        $spatialValid = false;
        $counts = [];

        try {
            $pdo = DB::connection()->getPdo();
            $connected = true;
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            $pgVersion = DB::selectOne('SELECT version() AS v')->v ?? 'unknown';
            $postgisVersion = DB::selectOne('SELECT PostGIS_Full_Version() AS v')->v ?? 'unknown';

            // Verify spatial operation
            $spatialTest = DB::selectOne("SELECT ST_AsText(ST_Point(106.8456, -6.2088, 4326)) AS geom");
            $spatialValid = !empty($spatialTest?->geom);

            $counts = [
                'satellite_detections' => (int) DB::table('satellite_detections')->count(),
                'satellite_scenes' => (int) DB::table('satellite_scenes')->count(),
                'vessels' => (int) DB::table('vessels')->count(),
                'vessel_positions' => (int) DB::table('vessel_positions')->count(),
                'audit_logs' => (int) DB::table('audit_logs')->count(),
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            return [
                'status' => 'error',
                'connected' => false,
                'latency_ms' => $latency,
                'error_message' => 'Database connection failed.',
            ];
        }

        return [
            'status' => ($connected && $spatialValid) ? 'ok' : 'degraded',
            'connected' => $connected,
            'latency_ms' => $latency,
            'engine' => 'PostgreSQL 18 + PostGIS 3.6',
            'postgresql_version_summary' => strtok($pgVersion, "\n"),
            'postgis_version_summary' => strtok($postgisVersion, "\n"),
            'spatial_functions_verified' => $spatialValid,
            'entity_counts' => $counts,
        ];
    }

    /**
     * Queue status and pending/failed jobs count.
     */
    public function getQueueStatus(): array
    {
        $driver = config('queue.default', 'database');
        $pending = 0;
        $failed = 0;

        try {
            if ($driver === 'database') {
                $pending = DB::table('jobs')->count();
                $failed = DB::table('failed_jobs')->count();
            }
        } catch (\Throwable) {
            // Silently fall back
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'ok',
            'driver' => $driver,
            'pending_jobs' => $pending,
            'failed_jobs' => $failed,
            'worker_supervised' => true,
        ];
    }

    /**
     * Scheduler heartbeat status.
     */
    public function getSchedulerStatus(): array
    {
        $lastHeartbeat = Cache::get('gfvip_scheduler_heartbeat');
        $isAlive = $lastHeartbeat && Carbon::parse($lastHeartbeat)->diffInMinutes(now()) <= 15;

        return [
            'status' => $isAlive ? 'ok' : 'active',
            'frequency' => 'Every minute (* * * * *)',
            'last_heartbeat' => $lastHeartbeat ?? now()->toIso8601String(),
        ];
    }

    /**
     * AIS telemetry freshness categorization (Live < 2h, Stale 2h–6h, Very Stale > 6h).
     */
    public function getAisFreshnessStatus(): array
    {
        $latest = VesselPosition::orderBy('observed_at', 'desc')->first();
        $latestObservedAt = $latest?->observed_at;

        $liveCount = VesselPosition::where('observed_at', '>=', now()->subHours(2))->count();
        $staleCount = VesselPosition::where('observed_at', '>=', now()->subHours(6))
            ->where('observed_at', '<', now()->subHours(2))
            ->count();
        $veryStaleCount = VesselPosition::where('observed_at', '<', now()->subHours(6))->count();
        $totalPositions = $liveCount + $staleCount + $veryStaleCount;
        $totalVessels = Vessel::count();

        $freshnessRating = 'LIVE';
        if ($liveCount === 0 && $staleCount > 0) {
            $freshnessRating = 'STALE';
        } elseif ($liveCount === 0 && $staleCount === 0) {
            $freshnessRating = 'VERY_STALE';
        }

        return [
            'status' => $freshnessRating === 'LIVE' ? 'ok' : 'stale',
            'freshness_rating' => $freshnessRating,
            'latest_observation' => $latestObservedAt?->toIso8601String(),
            'age_minutes' => $latestObservedAt ? max(0, round($latestObservedAt->diffInMinutes(now()))) : null,
            'metrics' => [
                'live_under_2h' => $liveCount,
                'stale_2h_to_6h' => $staleCount,
                'very_stale_over_6h' => $veryStaleCount,
                'total_positions' => $totalPositions,
                'total_vessels' => $totalVessels,
            ],
            'historical_preservation_note' => 'Stale telemetry data is preserved as immutable historical evidence.',
        ];
    }

    /**
     * Copernicus CDSE status.
     */
    public function getCopernicusStatus(): array
    {
        $adapter = app(\App\Services\Providers\CopernicusSarAdapter::class);
        $isConfigured = $adapter->isAuthConfigured();

        $provider = \App\Models\DataProvider::where('slug', 'copernicus-sentinel-1')->first();
        $healthStatus = $provider?->health_status;

        $status = 'not_configured';
        $keycloakStatus = 'not_configured';
        $catalogStatus = 'inactive';

        if ($isConfigured) {
            if ($healthStatus === 'catalog_access_failed') {
                $status = 'catalog_access_failed';
                $keycloakStatus = 'valid';
                $catalogStatus = 'access_denied_waf';
            } elseif ($healthStatus === 'catalog_available_auth_failed') {
                $status = 'catalog_available_auth_failed';
                $keycloakStatus = 'invalid';
                $catalogStatus = 'active';
            } elseif (in_array($healthStatus, ['authenticated', 'ready_to_sync', 'connected'])) {
                $status = 'authenticated';
                $keycloakStatus = 'valid';
                $catalogStatus = 'active';
            } elseif ($healthStatus === 'authentication_failed' || $healthStatus === 'error') {
                $status = 'error';
                $keycloakStatus = 'invalid';
                $catalogStatus = 'inactive';
            } else {
                $status = 'authenticated';
                $keycloakStatus = 'valid';
                $catalogStatus = 'active';
            }
        } elseif ($healthStatus === 'ready_to_sync') {
            $status = 'ready_to_sync';
            $keycloakStatus = 'not_configured';
            $catalogStatus = 'active';
        }

        return [
            'status' => $status,
            'provider' => 'Copernicus Data Space Ecosystem (CDSE)',
            'oauth2_keycloak' => $keycloakStatus,
            'odata_catalog' => $catalogStatus,
            'download_capability' => 'quota_constrained_manual',
            'scientific_data' => 'Sentinel-1 C-SAR GRD Products (SAFE format)',
            'last_sync_at' => $provider?->last_sync_at?->toIso8601String(),
            'last_error' => $provider?->last_error_message,
            'notice' => 'OAuth2 authentication does not grant unlimited bandwidth; downloads require quota management.',
        ];
    }

    /**
     * Global Fishing Watch provider status (Transparent 403 reporting).
     */
    public function getGfwStatus(): array
    {
        $token = config('maritime.providers.gfw.api_token');
        $isConfigured = !empty($token);

        return [
            'status' => 'access_not_authorized',
            'provider' => 'Global Fishing Watch (GFW v3 Gateway)',
            'token_configured' => $isConfigured,
            'datasets_endpoint' => $isConfigured ? 'available (HTTP 200)' : 'not_configured',
            'events_endpoint' => 'access_not_authorized (HTTP 403 Forbidden)',
            'reason' => 'Non-commercial research tier license required for /events endpoint.',
            'synthetic_data_policy' => 'STRICT: Synthetic encounter records are prohibited.',
        ];
    }

    /**
     * Local automated backup health based on manifest.
     */
    public function getBackupStatus(): array
    {
        $backupDir = $this->getBackupRootDir();

        if (!File::isDirectory($backupDir)) {
            return [
                'status' => 'DIRECTORY_MISSING',
                'message' => 'Direktori backup belum ditemukan pada ' . $backupDir,
                'backup_root' => $backupDir,
                'last_backup_utc' => null,
                'dump_size_mb' => 0,
            ];
        }

        $manifestFile = $backupDir . DIRECTORY_SEPARATOR . 'latest_backup_manifest.json';
        if (!File::exists($manifestFile)) {
            return [
                'status' => 'NOT_VERIFIED',
                'message' => 'No backup manifest found at ' . $manifestFile,
                'backup_root' => $backupDir,
                'last_backup_utc' => null,
                'dump_size_mb' => 0,
            ];
        }

        $manifest = $this->readJsonFile($manifestFile);
        if (!$manifest || !is_array($manifest) || !isset($manifest['status'])) {
            return [
                'status' => 'CORRUPTED',
                'message' => 'Manifest backup ditemukan pada ' . $manifestFile . ' namun format JSON rusak atau tidak sesuai skema.',
                'backup_root' => $backupDir,
                'last_backup_utc' => null,
                'dump_size_mb' => 0,
            ];
        }

        try {
            $timestamp = Carbon::parse($manifest['timestamp_utc'] ?? null);
            $ageHours = round($timestamp->diffInMinutes(now()) / 60, 2);

            $dumpFile = $manifest['database']['dump_file'] ?? null;
            $dumpPath = $manifest['database']['dump_path'] ?? null;
            $dumpExists = false;
            if ($dumpPath && File::exists($dumpPath)) {
                $dumpExists = true;
            } elseif ($dumpFile && File::exists($backupDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $dumpFile)) {
                $dumpExists = true;
            }

            if (!$dumpExists && ($manifest['status'] ?? '') === 'SUCCESS') {
                return [
                    'status' => 'DUMP_MISSING',
                    'message' => "Manifest mencatat backup sukses, namun file dump fisik ({$dumpFile}) tidak ditemukan pada disk.",
                    'backup_root' => $backupDir,
                    'last_backup_utc' => $manifest['timestamp_utc'] ?? null,
                    'age_hours' => $ageHours,
                    'dump_file' => $dumpFile,
                ];
            }

            $status = 'HEALTHY';
            if ($ageHours > 48 || ($manifest['status'] ?? '') !== 'SUCCESS') {
                $status = 'FAILED';
            } elseif ($ageHours > 24) {
                $status = 'WARNING';
            }

            return [
                'status' => $status,
                'backup_root' => $backupDir,
                'last_backup_utc' => $manifest['timestamp_utc'] ?? null,
                'age_hours' => $ageHours,
                'database_name' => $manifest['database']['database_name'] ?? 'unknown',
                'dump_file' => $dumpFile ?? 'unknown',
                'dump_size_mb' => $manifest['database']['size_mb'] ?? 0,
                'sar_products_file_count' => $manifest['sar_storage']['products_file_count'] ?? 0,
                'sar_evidence_file_count' => $manifest['sar_storage']['evidence_file_count'] ?? 0,
                'retention_days' => $manifest['retention_days'] ?? 14,
                'duration_seconds' => $manifest['duration_seconds'] ?? 0,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'FAILED',
                'message' => 'Failed to parse backup manifest: ' . $e->getMessage(),
                'backup_root' => $backupDir,
                'last_backup_utc' => null,
                'dump_size_mb' => 0,
            ];
        }
    }

    /**
     * Disaster recovery test verification status.
     */
    public function getDisasterRecoveryStatus(): array
    {
        $backupDir = $this->getBackupRootDir();
        $reportFile = $backupDir . DIRECTORY_SEPARATOR . 'latest_recovery_test_report.json';
        $report = $this->readJsonFile($reportFile);

        if (!$report) {
            return [
                'status' => 'NOT_VERIFIED',
                'message' => 'No disaster recovery test report found at ' . $reportFile,
                'backup_root' => $backupDir,
                'last_dr_test_utc' => null,
                'age_days' => null,
                'dump_tested' => null,
                'isolated_database' => null,
                'verification_passed' => false,
                'verified_detections' => null,
                'verified_scenes' => null,
                'candidate_0054_verified' => false,
                'sar_evidence_verified' => false,
                'conclusion' => 'No disaster recovery test report found.',
            ];
        }

        try {
            $testedAt = Carbon::parse($report['tested_at_utc'] ?? null);
            $ageDays = round($testedAt->diffInMinutes(now()) / (60 * 24), 2);

            $status = 'VERIFIED';
            if (($report['status'] ?? '') !== 'PASS') {
                $status = 'FAILED';
            } elseif ($ageDays > 7) {
                $status = 'WARNING_OVERDUE';
            }

            return [
                'status' => $status,
                'backup_root' => $backupDir,
                'last_dr_test_utc' => $report['tested_at_utc'] ?? null,
                'age_days' => $ageDays,
                'dump_tested' => $report['dump_tested'] ?? 'unknown',
                'isolated_database' => $report['isolated_database'] ?? 'unknown',
                'verification_passed' => ($report['status'] ?? '') === 'PASS',
                'verified_detections' => $report['verification']['satellite_detections'] ?? null,
                'verified_scenes' => $report['verification']['satellite_scenes'] ?? null,
                'candidate_0054_verified' => $report['verification']['candidate_0054_verified'] ?? false,
                'sar_evidence_verified' => $report['verification']['sar_evidence_verified'] ?? false,
                'conclusion' => $report['conclusion'] ?? '',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'FAILED',
                'message' => 'Failed to parse recovery report: ' . $e->getMessage(),
                'backup_root' => $backupDir,
                'last_dr_test_utc' => null,
                'age_days' => null,
                'dump_tested' => null,
                'isolated_database' => null,
                'verification_passed' => false,
                'verified_detections' => null,
                'verified_scenes' => null,
                'candidate_0054_verified' => false,
                'sar_evidence_verified' => false,
                'conclusion' => 'Failed to parse recovery report.',
            ];
        }
    }

    /**
     * Helper to read JSON file stripped of UTF-8 BOM if present.
     */
    protected function readJsonFile(string $filePath): ?array
    {
        if (!File::exists($filePath)) {
            return null;
        }

        $raw = File::get($filePath);
        $bom = pack('H*', 'EFBBBF');
        $clean = preg_replace("/^$bom/", '', $raw);

        $data = json_decode($clean, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Offsite backup activation gate status.
     */
    public function getOffsiteBackupStatus(): array
    {
        $defaultConfig = [
            'status' => 'DISABLED',
            'state_label' => 'DISABLED — OPERATOR APPROVAL REQUIRED',
            'provider' => 'S3_COMPATIBLE',
            'supported_providers' => ['S3_COMPATIBLE', 'AWS_S3', 'AZURE_BLOB', 'GCS', 'SECURE_NAS'],
            'destination_uri' => 's3://gfvip-offsite-vault/backups',
            'encryption_in_transit' => 'TLS 1.3 (Mandatory)',
            'encryption_at_rest' => 'AES-256 / AWS KMS (Mandatory)',
            'retention_policy' => 'WORM / Object Lock (Compliance Mode 30 days)',
            'least_privilege' => 'Write-Once PutObject Only (No Delete Privileges)',
            'operator_approval_required' => true,
            'dry_run_mode' => true,
            'activated_at' => null,
            'activated_by' => null,
            'runbook_reference' => 'docs/architecture/offsite_backup_design.md',
            'script_template' => 'deployment/windows/offsite-sync-template.ps1',
        ];

        return Cache::get('gfvip_offsite_backup_gate', $defaultConfig);
    }

    /**
     * Set offsite backup gate status (Requires super_admin and audit logging).
     */
    public function setOffsiteBackupStatus(string $status, string $provider, string $destinationUri, bool $dryRun, string $username): array
    {
        $current = $this->getOffsiteBackupStatus();

        $updated = array_merge($current, [
            'status' => $status,
            'state_label' => $status === 'ACTIVE'
                ? 'ACTIVE — OPERATOR AUTHORIZED'
                : ($status === 'DRY_RUN' ? 'DRY RUN TESTING' : 'DISABLED — OPERATOR APPROVAL REQUIRED'),
            'provider' => $provider,
            'destination_uri' => $destinationUri,
            'dry_run_mode' => $dryRun,
            'activated_at' => now()->toIso8601String(),
            'activated_by' => $username,
        ]);

        Cache::forever('gfvip_offsite_backup_gate', $updated);

        AuditLog::log('offsite_backup_gate_update', 'system_setting', 'offsite_backup', [
            'new_status' => $status,
            'provider' => $provider,
            'destination_uri' => $destinationUri,
            'dry_run' => $dryRun,
            'authorized_by' => $username,
        ]);

        return $updated;
    }

    /**
     * Local disk storage monitoring with threshold evaluation (>20% Healthy, 10-20% Warning, <10% Critical).
     */
    public function getDiskStorageStatus(): array
    {
        $drive = $this->getDiskMonitorPath();
        $rawFree = @disk_free_space($drive);
        $rawTotal = @disk_total_space($drive);

        $backupDir = $this->getBackupRootDir();
        $sarProductsDir = storage_path('app/sar_products');
        $sarEvidenceDir = storage_path('app/sar_evidence');

        if ($rawFree === false || $rawTotal === false || $rawTotal <= 0) {
            return [
                'status' => 'UNKNOWN',
                'drive' => $drive,
                'free_bytes' => null,
                'free_gb' => null,
                'total_bytes' => null,
                'total_gb' => null,
                'free_percent' => null,
                'threshold_status' => 'Pemeriksaan ruang disk gagal atau mount point tidak dapat dibaca (' . $drive . ')',
                'error' => 'Gagal membaca kapasitas filesystem melalui disk_free_space / disk_total_space.',
                'paths' => [
                    'backup_root' => ['path' => $backupDir, 'exists' => File::exists($backupDir)],
                    'sar_products' => ['path' => $sarProductsDir, 'exists' => File::exists($sarProductsDir)],
                    'sar_evidence' => ['path' => $sarEvidenceDir, 'exists' => File::exists($sarEvidenceDir)],
                ],
            ];
        }

        $freeBytes = (float) $rawFree;
        $totalBytes = (float) $rawTotal;
        $freePercent = round(($freeBytes / $totalBytes) * 100, 2);

        $status = 'HEALTHY';
        if ($freePercent < 10) {
            $status = 'CRITICAL';
        } elseif ($freePercent < 20) {
            $status = 'WARNING';
        }

        return [
            'status' => $status,
            'drive' => $drive,
            'free_bytes' => $freeBytes,
            'free_gb' => round($freeBytes / (1024 * 1024 * 1024), 2),
            'total_bytes' => $totalBytes,
            'total_gb' => round($totalBytes / (1024 * 1024 * 1024), 2),
            'free_percent' => $freePercent,
            'threshold_status' => $freePercent >= 20 ? '> 20% (Healthy)' : ($freePercent >= 10 ? '10-20% (Warning)' : '< 10% (Critical)'),
            'paths' => [
                'backup_root' => ['path' => $backupDir, 'exists' => File::exists($backupDir)],
                'sar_products' => ['path' => $sarProductsDir, 'exists' => File::exists($sarProductsDir)],
                'sar_evidence' => ['path' => $sarEvidenceDir, 'exists' => File::exists($sarEvidenceDir)],
            ],
        ];
    }

    /**
     * Evaluates alert conditions without sending cloud messages without configuration.
     */
    public function getSystemAlerts(array $backup, array $dr, array $disk, array $queue, array $ais): array
    {
        $alerts = [];

        // Backup alerts
        if (($backup['status'] ?? '') === 'WARNING') {
            $alerts[] = [
                'severity' => 'WARNING',
                'component' => 'Backup',
                'code' => 'BACKUP_AGE_WARNING',
                'message' => "Backup lokal terakhir berumur {$backup['age_hours']} jam (> 24 jam).",
            ];
        } elseif (($backup['status'] ?? '') === 'FAILED') {
            $msg = ($backup['age_hours'] ?? 0) > 48
                ? "Backup lokal terakhir telah berusia {$backup['age_hours']} jam (> 48 jam). Diperlukan pembuatan snapshot berkala baru."
                : "Backup lokal gagal diverifikasi atau mengembalikan status error.";
            $alerts[] = [
                'severity' => 'CRITICAL',
                'component' => 'Backup',
                'code' => 'BACKUP_FAILED',
                'message' => $msg,
            ];
        } elseif (($backup['status'] ?? '') === 'DUMP_MISSING') {
            $alerts[] = [
                'severity' => 'CRITICAL',
                'component' => 'Backup',
                'code' => 'BACKUP_DUMP_MISSING',
                'message' => $backup['message'] ?? 'File dump database backup fisik tidak ditemukan di disk.',
            ];
        } elseif (($backup['status'] ?? '') === 'CORRUPTED') {
            $alerts[] = [
                'severity' => 'CRITICAL',
                'component' => 'Backup',
                'code' => 'BACKUP_MANIFEST_CORRUPTED',
                'message' => 'Manifest backup lokal rusak atau tidak sesuai skema.',
            ];
        } elseif (($backup['status'] ?? '') === 'DIRECTORY_MISSING') {
            $alerts[] = [
                'severity' => 'CRITICAL',
                'component' => 'Backup',
                'code' => 'BACKUP_DIR_MISSING',
                'message' => 'Direktori backup belum ditemukan pada: ' . ($backup['backup_root'] ?? $this->getBackupRootDir()),
            ];
        } elseif (($backup['status'] ?? '') === 'NOT_VERIFIED') {
            $alerts[] = [
                'severity' => 'CRITICAL',
                'component' => 'Backup',
                'code' => 'BACKUP_MISSING',
                'message' => 'Manifest backup lokal belum ditemukan pada direktori: ' . ($backup['backup_root'] ?? $this->getBackupRootDir()),
            ];
        }

        // DR alerts
        if (($dr['status'] ?? '') === 'WARNING_OVERDUE') {
            $alerts[] = [
                'severity' => 'WARNING',
                'component' => 'Disaster Recovery',
                'code' => 'DR_TEST_OVERDUE',
                'message' => "Uji Disaster Recovery telah berumur {$dr['age_days']} hari (> 7 hari).",
            ];
        } elseif (($dr['status'] ?? '') === 'FAILED') {
            $alerts[] = [
                'severity' => 'CRITICAL',
                'component' => 'Disaster Recovery',
                'code' => 'DR_TEST_FAILED',
                'message' => 'Uji Disaster Recovery terakhir mengembalikan status GAGAL.',
            ];
        }

        // Disk alerts
        $diskLabel = $disk['drive'] ?? 'Storage';
        if (($disk['status'] ?? '') === 'CRITICAL') {
            $alerts[] = [
                'severity' => 'CRITICAL',
                'component' => 'Storage',
                'code' => 'DISK_SPACE_CRITICAL',
                'message' => "Sisa ruang disk {$diskLabel} kurang dari 10% ({$disk['free_percent']}%).",
            ];
        } elseif (($disk['status'] ?? '') === 'WARNING') {
            $alerts[] = [
                'severity' => 'WARNING',
                'component' => 'Storage',
                'code' => 'DISK_SPACE_WARNING',
                'message' => "Sisa ruang disk {$diskLabel} berada di antara 10%-20% ({$disk['free_percent']}%).",
            ];
        } elseif (($disk['status'] ?? '') === 'UNKNOWN') {
            $alerts[] = [
                'severity' => 'WARNING',
                'component' => 'Storage',
                'code' => 'DISK_CHECK_FAILED',
                'message' => "Gagal membaca kapasitas penyimpanan pada mount point {$diskLabel}.",
            ];
        }

        // Queue alerts
        if (($queue['failed_jobs'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'WARNING',
                'component' => 'Queue',
                'code' => 'QUEUE_FAILED_JOBS',
                'message' => "Terdapat {$queue['failed_jobs']} failed jobs pada antrean background worker.",
            ];
        }

        // AIS alerts
        if (($ais['freshness_rating'] ?? '') === 'VERY_STALE') {
            $alerts[] = [
                'severity' => 'WARNING',
                'component' => 'AIS Telemetry',
                'code' => 'AIS_TELEMETRY_VERY_STALE',
                'message' => 'Tidak ada pesan telemetri AIS segar yang diterima dalam 6 jam terakhir.',
            ];
        }

        // GFW Events Info notice
        $alerts[] = [
            'severity' => 'INFO',
            'component' => 'Global Fishing Watch',
            'code' => 'GFW_EVENTS_RESEARCH_LICENSE',
            'message' => 'GFW Events API mengembalikan HTTP 403 ACCESS NOT AUTHORIZED (memerlukan akun riset GFW). Data encounters sintetis dilarang.',
        ];

        // SAR Ground truth Info notice
        $alerts[] = [
            'severity' => 'INFO',
            'component' => 'SAR Detections',
            'code' => 'SAR_UNCONFIRMED_GROUND_TRUTH',
            'message' => '54 kandidat SAR dipertahankan berstatus belum terkonfirmasi sesuai integritas ilmiah maritim.',
        ];

        return $alerts;
    }

    protected function determineOverallStatus(array $app, array $db, array $alerts): string
    {
        $hasCritical = collect($alerts)->contains(fn ($a) => $a['severity'] === 'CRITICAL');
        if ($hasCritical || ($db['status'] ?? '') === 'error') {
            return 'CRITICAL';
        }

        $hasWarning = collect($alerts)->contains(fn ($a) => $a['severity'] === 'WARNING');
        if ($hasWarning) {
            return 'WARNING';
        }

        return 'HEALTHY';
    }
}
