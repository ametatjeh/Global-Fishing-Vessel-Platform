<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempTestDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempTestDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gfvip_test_backup_' . uniqid();
        File::makeDirectory($this->tempTestDir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempTestDir)) {
            File::deleteDirectory($this->tempTestDir);
        }
        parent::tearDown();
    }

    public function test_disk_storage_detects_real_filesystem_without_false_critical_zero(): void
    {
        $service = app(SystemHealthService::class);
        $status = $service->getDiskStorageStatus();

        $this->assertContains($status['status'], ['HEALTHY', 'WARNING', 'CRITICAL', 'UNKNOWN']);
        if ($status['status'] !== 'UNKNOWN') {
            $this->assertNotNull($status['free_bytes']);
            $this->assertNotNull($status['total_bytes']);
            $this->assertGreaterThan(0, $status['total_bytes']);
            $this->assertGreaterThanOrEqual(0, $status['free_percent']);
        }
    }

    public function test_disk_storage_handles_invalid_mount_point_as_unknown_without_zero_percent_critical(): void
    {
        $service = app(SystemHealthService::class);
        $service->setDiskMonitorPath('/invalid/non_existent_mount_point_xyz_123');

        $status = $service->getDiskStorageStatus();
        $this->assertEquals('UNKNOWN', $status['status']);
        $this->assertNull($status['free_percent']);

        $alerts = $service->getSystemAlerts(
            backup: ['status' => 'HEALTHY'],
            dr: ['status' => 'VERIFIED'],
            disk: $status,
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $diskAlerts = collect($alerts)->where('component', 'Storage')->all();
        $this->assertCount(1, $diskAlerts);
        $this->assertEquals('DISK_CHECK_FAILED', $diskAlerts[0]['code']);
        $this->assertEquals('WARNING', $diskAlerts[0]['severity']);
        $this->assertStringNotContainsString('0%', $diskAlerts[0]['message']);
    }

    public function test_disk_alerts_trigger_warning_between_10_and_20_percent(): void
    {
        $service = app(SystemHealthService::class);
        $mockDisk = [
            'status' => 'WARNING',
            'drive' => '/www',
            'free_percent' => 15.5,
        ];

        $alerts = $service->getSystemAlerts(
            backup: ['status' => 'HEALTHY'],
            dr: ['status' => 'VERIFIED'],
            disk: $mockDisk,
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $diskAlerts = collect($alerts)->where('code', 'DISK_SPACE_WARNING')->first();
        $this->assertNotNull($diskAlerts);
        $this->assertEquals('WARNING', $diskAlerts['severity']);
        $this->assertStringContainsString('15.5%', $diskAlerts['message']);
        $this->assertStringContainsString('/www', $diskAlerts['message']);
    }

    public function test_disk_alerts_trigger_critical_under_10_percent(): void
    {
        $service = app(SystemHealthService::class);
        $mockDisk = [
            'status' => 'CRITICAL',
            'drive' => '/www',
            'free_percent' => 4.2,
        ];

        $alerts = $service->getSystemAlerts(
            backup: ['status' => 'HEALTHY'],
            dr: ['status' => 'VERIFIED'],
            disk: $mockDisk,
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $diskAlerts = collect($alerts)->where('code', 'DISK_SPACE_CRITICAL')->first();
        $this->assertNotNull($diskAlerts);
        $this->assertEquals('CRITICAL', $diskAlerts['severity']);
        $this->assertStringContainsString('4.2%', $diskAlerts['message']);
        $this->assertStringContainsString('/www', $diskAlerts['message']);
    }

    public function test_backup_status_distinguishes_directory_missing(): void
    {
        $service = app(SystemHealthService::class);
        $service->setBackupRoot($this->tempTestDir . DIRECTORY_SEPARATOR . 'non_existent_subdir');

        $status = $service->getBackupStatus();
        $this->assertEquals('DIRECTORY_MISSING', $status['status']);

        $alerts = $service->getSystemAlerts(
            backup: $status,
            dr: ['status' => 'VERIFIED'],
            disk: ['status' => 'HEALTHY'],
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $backupAlert = collect($alerts)->where('code', 'BACKUP_DIR_MISSING')->first();
        $this->assertNotNull($backupAlert);
        $this->assertEquals('CRITICAL', $backupAlert['severity']);
    }

    public function test_backup_status_distinguishes_manifest_missing(): void
    {
        $service = app(SystemHealthService::class);
        $service->setBackupRoot($this->tempTestDir);

        $status = $service->getBackupStatus();
        $this->assertEquals('NOT_VERIFIED', $status['status']);

        $alerts = $service->getSystemAlerts(
            backup: $status,
            dr: ['status' => 'VERIFIED'],
            disk: ['status' => 'HEALTHY'],
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $backupAlert = collect($alerts)->where('code', 'BACKUP_MISSING')->first();
        $this->assertNotNull($backupAlert);
        $this->assertEquals('CRITICAL', $backupAlert['severity']);
    }

    public function test_backup_status_distinguishes_corrupted_manifest(): void
    {
        $service = app(SystemHealthService::class);
        $service->setBackupRoot($this->tempTestDir);

        File::put($this->tempTestDir . DIRECTORY_SEPARATOR . 'latest_backup_manifest.json', '{invalid json');

        $status = $service->getBackupStatus();
        $this->assertEquals('CORRUPTED', $status['status']);

        $alerts = $service->getSystemAlerts(
            backup: $status,
            dr: ['status' => 'VERIFIED'],
            disk: ['status' => 'HEALTHY'],
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $backupAlert = collect($alerts)->where('code', 'BACKUP_MANIFEST_CORRUPTED')->first();
        $this->assertNotNull($backupAlert);
        $this->assertEquals('CRITICAL', $backupAlert['severity']);
    }

    public function test_backup_status_distinguishes_missing_physical_dump_file(): void
    {
        $service = app(SystemHealthService::class);
        $service->setBackupRoot($this->tempTestDir);

        $manifestData = [
            'status' => 'SUCCESS',
            'timestamp_utc' => now()->toIso8601String(),
            'database' => [
                'database_name' => 'global_fishing_vessel_intelligence',
                'dump_file' => 'gfvip_db_20261029_000000.dump',
                'dump_path' => $this->tempTestDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'gfvip_db_20261029_000000.dump',
                'size_mb' => 25.4,
            ],
            'retention_days' => 14,
        ];

        File::put(
            $this->tempTestDir . DIRECTORY_SEPARATOR . 'latest_backup_manifest.json',
            json_encode($manifestData, JSON_PRETTY_PRINT)
        );

        $status = $service->getBackupStatus();
        $this->assertEquals('DUMP_MISSING', $status['status']);

        $alerts = $service->getSystemAlerts(
            backup: $status,
            dr: ['status' => 'VERIFIED'],
            disk: ['status' => 'HEALTHY'],
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $backupAlert = collect($alerts)->where('code', 'BACKUP_DUMP_MISSING')->first();
        $this->assertNotNull($backupAlert);
        $this->assertEquals('CRITICAL', $backupAlert['severity']);
    }

    public function test_backup_status_reports_healthy_when_manifest_and_dump_exist(): void
    {
        $service = app(SystemHealthService::class);
        $service->setBackupRoot($this->tempTestDir);

        $dbSubdir = $this->tempTestDir . DIRECTORY_SEPARATOR . 'database';
        File::makeDirectory($dbSubdir, 0755, true, true);

        $dumpFileName = 'gfvip_db_20261029_000000.dump';
        $dumpFilePath = $dbSubdir . DIRECTORY_SEPARATOR . $dumpFileName;
        File::put($dumpFilePath, 'MOCK_PG_DUMP_BINARY_DATA');

        $manifestData = [
            'status' => 'SUCCESS',
            'timestamp_utc' => now()->subHours(2)->toIso8601String(),
            'database' => [
                'database_name' => 'global_fishing_vessel_intelligence',
                'dump_file' => $dumpFileName,
                'dump_path' => $dumpFilePath,
                'size_mb' => 12.3,
            ],
            'retention_days' => 14,
            'duration_seconds' => 4.5,
        ];

        File::put(
            $this->tempTestDir . DIRECTORY_SEPARATOR . 'latest_backup_manifest.json',
            json_encode($manifestData, JSON_PRETTY_PRINT)
        );

        $status = $service->getBackupStatus();
        $this->assertEquals('HEALTHY', $status['status']);
        $this->assertEquals('global_fishing_vessel_intelligence', $status['database_name']);
        $this->assertEquals($dumpFileName, $status['dump_file']);

        $alerts = $service->getSystemAlerts(
            backup: $status,
            dr: ['status' => 'VERIFIED'],
            disk: ['status' => 'HEALTHY', 'drive' => '/www', 'free_percent' => 50],
            queue: ['failed_jobs' => 0],
            ais: ['freshness_rating' => 'LIVE']
        );

        $backupAlert = collect($alerts)->where('component', 'Backup')->first();
        $this->assertNull($backupAlert);
    }
}

