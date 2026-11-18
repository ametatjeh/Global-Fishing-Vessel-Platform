<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\EezZone;
use App\Models\FaoFishingArea;
use App\Models\Investigation;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Sar\Sentinel1aCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class Sentinel1aCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected string $testStorageDir;
    protected string $testEvidenceDir;
    protected string $testBackupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStorageDir = storage_path('app/testing_sar_products');
        $this->testEvidenceDir = storage_path('app/testing_sar_evidence');
        $this->testBackupDir = storage_path('app/testing_backups/sentinel-1a-cleanup');

        File::ensureDirectoryExists($this->testStorageDir);
        File::ensureDirectoryExists($this->testEvidenceDir);

        config(['maritime.providers.copernicus_sar.pipeline.storage_dir' => $this->testStorageDir]);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testStorageDir)) {
            File::deleteDirectory($this->testStorageDir);
        }
        if (File::exists($this->testEvidenceDir)) {
            File::deleteDirectory($this->testEvidenceDir);
        }
        if (File::exists(storage_path('app/testing_backups'))) {
            File::deleteDirectory(storage_path('app/testing_backups'));
        }

        parent::tearDown();
    }

    /**
     * 1. Sentinel-1A identified correctly and distinguished from Sentinel-1B / Sentinel-1C.
     */
    public function test_sentinel_1a_is_identified_correctly_and_distinguished(): void
    {
        $service = app(Sentinel1aCleanupService::class);

        // Sentinel-1A variants
        $s1aScene1 = SatelliteScene::create([
            'scene_id' => 'S1A_IW_GRDH_1SDV_20261019T000000_TEST1',
            'platform_name' => 'Sentinel-1A',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        $s1aScene2 = SatelliteScene::create([
            'scene_id' => 'S1A_EW_GRDM_1SSH_20261019T010000_TEST2',
            'platform_name' => 'Sentinel-1',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'metadata' => ['platform_serial_identifier' => 'A'],
        ]);

        // Sentinel-1B variants (Must be protected)
        $s1bScene = SatelliteScene::create([
            'scene_id' => 'S1B_IW_GRDH_1SDV_20261019T020000_TESTB',
            'platform_name' => 'Sentinel-1B',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'metadata' => ['platform_serial_identifier' => 'B'],
        ]);

        // Sentinel-1C variants (Must be protected)
        $s1cScene = SatelliteScene::create([
            'scene_id' => 'S1C_EW_GRDM_1SSH_20261019T030000_TESTC',
            'platform_name' => 'Sentinel-1C',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'metadata' => ['platform_serial_identifier' => 'C'],
        ]);

        $this->assertTrue($service->isSentinel1A($s1aScene1));
        $this->assertTrue($service->isSentinel1A($s1aScene2));
        $this->assertFalse($service->isSentinel1A($s1bScene));
        $this->assertFalse($service->isSentinel1A($s1cScene));

        $inventory = $service->conductInventory();
        $this->assertEquals(2, $inventory['s1a_scenes_count']);
        $this->assertEquals(2, $inventory['protected_scenes_count']);
    }

    /**
     * 2. Dry run does NOT modify database records or delete any files.
     */
    public function test_dry_run_does_not_modify_database_or_files(): void
    {
        $s1aScene = SatelliteScene::create([
            'scene_id' => 'S1A_IW_GRDH_1SDV_20261019T000000_DRY',
            'platform_name' => 'Sentinel-1A',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        $det = SatelliteDetection::create([
            'satellite_scene_id' => $s1aScene->id,
            'detection_identifier' => 'S1A_DET_DRY_001',
            'latitude' => 5.0,
            'longitude' => 95.0,
            'detected_at' => now()->subDay(),
        ]);

        $dummyFile = $this->testStorageDir . DIRECTORY_SEPARATOR . 'S1A_IW_GRDH_1SDV_20261019T000000_DRY.zip';
        file_put_contents($dummyFile, 'dummy content S1A');

        $this->artisan('sentinel:cleanup-s1a', ['--dry-run' => true])
            ->assertExitCode(0);

        // Verify records and files still exist completely
        $this->assertDatabaseHas('satellite_scenes', ['id' => $s1aScene->id]);
        $this->assertDatabaseHas('satellite_detections', ['id' => $det->id]);
        $this->assertFileExists($dummyFile);
    }

    /**
     * 3. Full cleanup removes Sentinel-1A records, deletes files, preserves Sentinel-1B.
     */
    public function test_full_cleanup_removes_s1a_and_preserves_s1b(): void
    {
        // 1. Create S1A scene, detection, and file
        $s1aScene = SatelliteScene::create([
            'scene_id' => 'S1A_IW_GRDH_1SDV_20261019T000000_DEL',
            'platform_name' => 'Sentinel-1A',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        $s1aDet = SatelliteDetection::create([
            'satellite_scene_id' => $s1aScene->id,
            'detection_identifier' => 'S1A_DET_DEL_001',
            'latitude' => 5.0,
            'longitude' => 95.0,
            'detected_at' => now()->subDay(),
        ]);

        $s1aZip = $this->testStorageDir . DIRECTORY_SEPARATOR . 'S1A_IW_GRDH_1SDV_20261019T000000_DEL.zip';
        file_put_contents($s1aZip, 's1a product bytes');

        // 2. Create S1B scene, detection, and file (Must remain completely untouched)
        $s1bScene = SatelliteScene::create([
            'scene_id' => 'S1B_IW_GRDH_1SDV_20261019T000000_KEEP',
            'platform_name' => 'Sentinel-1B',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        $s1bDet = SatelliteDetection::create([
            'satellite_scene_id' => $s1bScene->id,
            'detection_identifier' => 'S1B_DET_KEEP_001',
            'latitude' => 6.0,
            'longitude' => 96.0,
            'detected_at' => now()->subDay(),
        ]);

        $s1bZip = $this->testStorageDir . DIRECTORY_SEPARATOR . 'S1B_IW_GRDH_1SDV_20261019T000000_KEEP.zip';
        file_put_contents($s1bZip, 's1b product bytes');

        // 3. Create AIS, FAO, and EEZ records to guarantee non-target integrity
        $vessel = Vessel::create(['name' => 'KM SENTOSA', 'mmsi' => '525999001', 'vessel_type' => 'fishing']);
        $pos = VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => 5.5, 'longitude' => 95.5, 'observed_at' => now(), 'received_at' => now()]);
        $fao = FaoFishingArea::create([
            'area_code' => '57',
            'name_en' => 'Indian Ocean, Eastern',
            'geometry_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);
        $eez = EezZone::create([
            'mrgid' => 8444,
            'geoname' => 'Indonesian Exclusive Economic Zone',
            'sovereign_state' => 'Indonesia',
            'sovereign_iso3' => 'IDN',
            'geometry_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        // 4. Create an Investigation referencing S1A detection
        $investigation = Investigation::create([
            'case_number' => 'INV-2026-TEST-S1A',
            'title' => 'Dark Vessel Target Investigation',
            'summary' => 'Initial correlation from S1A detection.',
            'findings' => 'Visual detection correlated with gap.',
            'related_detection_id' => $s1aDet->id,
            'target_vessel_id' => $vessel->id,
        ]);

        // Run actual execution with confirmation and custom backup path
        $this->artisan('sentinel:cleanup-s1a', [
            '--confirm-s1a-only' => true,
            '--backup-path' => $this->testBackupDir,
        ])->assertExitCode(0);

        // 5. Assertions: Sentinel-1A deleted
        $this->assertDatabaseMissing('satellite_scenes', ['id' => $s1aScene->id]);
        $this->assertDatabaseMissing('satellite_detections', ['id' => $s1aDet->id]);
        $this->assertFileDoesNotExist($s1aZip);

        // 6. Assertions: Sentinel-1B preserved
        $this->assertDatabaseHas('satellite_scenes', ['id' => $s1bScene->id]);
        $this->assertDatabaseHas('satellite_detections', ['id' => $s1bDet->id]);
        $this->assertFileExists($s1bZip);

        // 7. Assertions: AIS, FAO, EEZ completely untouched
        $this->assertDatabaseHas('vessels', ['id' => $vessel->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
        $this->assertDatabaseHas('fao_fishing_areas', ['id' => $fao->id]);
        $this->assertDatabaseHas('eez_zones', ['id' => $eez->id]);

        // 8. Assertions: Investigation decoupled safely with audit preserved
        $investigation->refresh();
        $this->assertNull($investigation->related_detection_id);
        $this->assertStringContainsString('didekomisioning dan dihapus', $investigation->findings);

        // 9. Assertions: AuditLog created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cleanup',
            'entity_type' => 'Sentinel-1A',
        ]);

        // 10. Assertions: Backup created successfully
        $this->assertFileExists($this->testBackupDir . DIRECTORY_SEPARATOR . 'inventory.json');
        $this->assertFileExists($this->testBackupDir . DIRECTORY_SEPARATOR . 'db/satellite_scenes_s1a.json');
        $this->assertFileExists($this->testBackupDir . DIRECTORY_SEPARATOR . 'db/satellite_detections_s1a.json');
    }

    /**
     * 4. Idempotency: Re-running cleanup when 0 records exist is safe.
     */
    public function test_cleanup_is_idempotent_when_zero_s1a_assets_exist(): void
    {
        $this->artisan('sentinel:cleanup-s1a', [
            '--confirm-s1a-only' => true,
            '--backup-path' => $this->testBackupDir,
        ])->assertExitCode(0);

        // Run a second time
        $this->artisan('sentinel:cleanup-s1a', [
            '--confirm-s1a-only' => true,
            '--backup-path' => $this->testBackupDir,
        ])->assertExitCode(0);
    }

    /**
     * 5. Execution fails and defaults to dry-run if confirm flag is missing.
     */
    public function test_execution_defaults_to_dry_run_without_confirm_flag(): void
    {
        $s1aScene = SatelliteScene::create([
            'scene_id' => 'S1A_IW_GRDH_1SDV_20261019T000000_UNCONFIRMED',
            'platform_name' => 'Sentinel-1A',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        // Call without flags
        $this->artisan('sentinel:cleanup-s1a')
            ->expectsOutputToContain('PERINGATAN: Penghapusan aktual ditolak karena flag konfirmasi tidak diberikan.')
            ->assertExitCode(0);

        // Database record must NOT be deleted
        $this->assertDatabaseHas('satellite_scenes', ['id' => $s1aScene->id]);
    }

    /**
     * 6. Unknown / non-S1A satellites (RADARSAT, etc.) are protected from deletion.
     */
    public function test_unambiguous_identification_protects_other_satellites(): void
    {
        $otherScene = SatelliteScene::create([
            'scene_id' => 'RS2_OK100000_PK1000000_TEST',
            'platform_name' => 'RADARSAT-2',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        $this->artisan('sentinel:cleanup-s1a', [
            '--confirm-s1a-only' => true,
            '--backup-path' => $this->testBackupDir,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('satellite_scenes', ['id' => $otherScene->id]);
    }

    /**
     * 7. Cache JSON files are pruned of S1A items while S1B/S1C items remain intact.
     */
    public function test_cache_pruning_removes_s1a_while_retaining_s1c(): void
    {
        $s1aScene = SatelliteScene::create([
            'scene_id' => 'S1A_IW_GRDH_CACHE_TEST',
            'platform_name' => 'Sentinel-1A',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        $cacheFile = storage_path('app/sar_candidates_cache.json');
        $initialCache = [
            ['target_id' => 'S1A_TARGET_01', 'detection_identifier' => 'S1A_DET_01'],
            ['target_id' => 'S1C_TARGET_02', 'detection_identifier' => 'S1C_DET_02'],
        ];
        file_put_contents($cacheFile, json_encode($initialCache));

        $this->artisan('sentinel:cleanup-s1a', [
            '--confirm-s1a-only' => true,
            '--backup-path' => $this->testBackupDir,
        ])->assertExitCode(0);

        $this->assertFileExists($cacheFile);
        $pruned = json_decode(file_get_contents($cacheFile), true);
        $this->assertCount(1, $pruned);
        $this->assertEquals('S1C_TARGET_02', $pruned[0]['target_id']);
    }
}
