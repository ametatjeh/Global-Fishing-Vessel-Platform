<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\EezZone;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\IndonesiaVesselPositionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Indonesia Vessel Position Reset & Resynchronization Test Suite
 *
 * Verifies safe, repeatable reset, quarantine, latest-position reconstruction,
 * zero-deletion preservation, and MapLibre GeoJSON freshness layer output.
 */
class IndonesiaVesselPositionResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected DataProvider $provider;
    protected IndonesiaVesselPositionSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Maritime Operations Admin',
            'email' => 'ops.admin@maritime.gov.id',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->provider = DataProvider::firstOrCreate(
            ['slug' => 'ais_stream'],
            [
                'name' => 'AISStream Realtime Provider',
                'type' => 'ais_stream',
                'is_enabled' => true,
                'health_status' => 'operational',
            ]
        );

        $this->syncService = app(IndonesiaVesselPositionSyncService::class);

        // Seed Authoritative Indonesian EEZ (MRGID 8492)
        $this->seedIndonesianEez();
    }

    /**
     * Helper to seed Indonesian EEZ polygon and bounding box
     */
    protected function seedIndonesianEez(): void
    {
        $eez = EezZone::updateOrCreate(
            ['mrgid' => IndonesiaVesselPositionSyncService::INDONESIAN_EEZ_MRGID],
            [
                'geoname' => 'Indonesian Exclusive Economic Zone',
                'sovereign_state' => 'Indonesia',
                'sovereign_iso3' => 'IDN',
                'pol_type' => '200NM',
                'version' => 'v12',
                'geometry_geojson' => json_encode([
                    'type' => 'MultiPolygon',
                    'coordinates' => [[[[94.0, -11.5], [141.5, -11.5], [141.5, 6.5], [94.0, 6.5], [94.0, -11.5]]]]
                ]),
                'bbox' => [94.0, -11.5, 141.5, 6.5],
            ]
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::statement("
                    UPDATE eez_zones 
                    SET geom = ST_Multi(ST_GeomFromText('POLYGON((94.0 -11.5, 141.5 -11.5, 141.5 6.5, 94.0 6.5, 94.0 -11.5))', 4326))
                    WHERE mrgid = ?
                ", [IndonesiaVesselPositionSyncService::INDONESIAN_EEZ_MRGID]);
            } catch (\Throwable $e) {
                // Ignore if spatial extensions not ready
            }
        }
    }

    /**
     * Helper to create a valid VesselPosition with required defaults
     */
    protected function createPosition(array $attributes): VesselPosition
    {
        $defaults = [
            'received_at' => now(),
            'source_provider_id' => $this->provider->id,
            'data_quality_score' => 1.0,
        ];

        return VesselPosition::create(array_merge($defaults, $attributes));
    }

    /**
     * TEST 1: Identifies positions geographically located inside the Indonesian EEZ
     */
    public function test_identifies_positions_inside_indonesian_eez(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM NUSANTARA BAHARI 01',
            'mmsi' => '525000101',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => now()->subHours(1),
        ]);

        // Position in Java Sea (within Indonesian EEZ)
        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -5.8500,
            'longitude' => 106.9000,
            'speed_knots' => 8.5,
            'course_degrees' => 140.0,
            'heading_degrees' => 142.0,
            'observed_at' => now()->subHours(1),
        ]);

        $inspection = $this->syncService->inspect(staleHours: 24);

        $this->assertEquals('success', $inspection['status']);
        $this->assertEquals(1, $inspection['fleet_summary']['vessels_in_indonesia']);
        $this->assertEquals(1, $inspection['fleet_summary']['positions_in_indonesia']);
        $this->assertEquals(1, $inspection['freshness_analysis']['fresh_positions']);
        $this->assertEquals(0, $inspection['freshness_analysis']['stale_positions']);
    }

    /**
     * TEST 2: Excludes positions geographically located outside the Indonesian EEZ
     */
    public function test_excludes_positions_outside_indonesian_eez(): void
    {
        $vessel = Vessel::create([
            'name' => 'PACIFIC VOYAGER',
            'mmsi' => '440123456',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => now()->subHours(1),
        ]);

        // Position near Tokyo Bay, Japan (far outside Indonesia)
        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => 35.3000,
            'longitude' => 139.7000,
            'speed_knots' => 12.0,
            'course_degrees' => 80.0,
            'observed_at' => now()->subHours(1),
        ]);

        $inspection = $this->syncService->inspect(staleHours: 24);

        $this->assertEquals(0, $inspection['fleet_summary']['vessels_in_indonesia']);
        $this->assertEquals(0, $inspection['fleet_summary']['positions_in_indonesia']);
    }

    /**
     * TEST 3: Identifies, flags, and quarantines invalid or corrupted coordinates
     */
    public function test_flags_and_quarantines_invalid_coordinates(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM ANOMALOUS',
            'mmsi' => '525000999',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => now()->subHours(2),
        ]);

        // Null-Island coordinate (0.0, 0.0)
        $nullIslandPos = $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => 0.0,
            'longitude' => 0.0,
            'speed_knots' => 0.0,
            'observed_at' => now()->subHours(5),
        ]);

        // Out-of-bounds latitude (> 90 degrees)
        $outOfBoundsPos = $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => 95.5000,
            'longitude' => 105.0000,
            'speed_knots' => 0.0,
            'observed_at' => now()->subHours(4),
        ]);

        $inspection = $this->syncService->inspect(staleHours: 24);
        $this->assertGreaterThanOrEqual(2, $inspection['freshness_analysis']['invalid_coordinates']);

        // Execute in apply mode to quarantine invalid records
        $result = $this->syncService->execute(dryRun: false, staleHours: 24, rebuildLatest: true, adminUserId: $this->adminUser->id);

        $this->assertTrue($result['applied']);
        $this->assertGreaterThanOrEqual(2, $result['actions_taken']['invalid_positions_quarantined']);

        // Quarantined records remain in DB, but with data_quality_score set to 0.0
        $this->assertEquals(0.0, $nullIslandPos->fresh()->data_quality_score);
        $this->assertEquals(0.0, $outOfBoundsPos->fresh()->data_quality_score);
    }

    /**
     * TEST 4: Detects stale positions beyond the configurable threshold (e.g. > 24 hours)
     */
    public function test_detects_stale_positions_beyond_threshold(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM DUA DARA',
            'mmsi' => '525000202',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => now()->subHours(36),
        ]);

        // Position observed 36 hours ago (stale against 24h threshold)
        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -3.0000,
            'longitude' => 110.0000,
            'speed_knots' => 6.0,
            'observed_at' => now()->subHours(36),
        ]);

        $inspection24 = $this->syncService->inspect(staleHours: 24);
        $this->assertEquals(1, $inspection24['freshness_analysis']['stale_positions']);
        $this->assertEquals(0, $inspection24['freshness_analysis']['fresh_positions']);

        // When threshold is increased to 48 hours, it becomes fresh
        $inspection48 = $this->syncService->inspect(staleHours: 48);
        $this->assertEquals(0, $inspection48['freshness_analysis']['stale_positions']);
        $this->assertEquals(1, $inspection48['freshness_analysis']['fresh_positions']);
    }

    /**
     * TEST 5: Reconstructs latest vessel positions correctly from genuine historical data
     */
    public function test_reconstructs_latest_position_from_history(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM CAHAYA LAUT',
            'mmsi' => '525000303',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => null, // Desynced / missing last seen
        ]);

        $oldTime = now()->subHours(10);
        $midTime = now()->subHours(5);
        $newestTime = now()->subHours(1);

        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -6.0000,
            'longitude' => 106.0000,
            'observed_at' => $oldTime,
        ]);

        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -6.1000,
            'longitude' => 106.1000,
            'observed_at' => $midTime,
        ]);

        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -6.2000,
            'longitude' => 106.2000,
            'observed_at' => $newestTime,
        ]);

        $result = $this->syncService->execute(dryRun: false, staleHours: 24, rebuildLatest: true, adminUserId: $this->adminUser->id);

        $this->assertTrue($result['applied']);
        $this->assertGreaterThanOrEqual(1, $result['actions_taken']['vessels_last_seen_updated']);

        // Vessel last_seen_at must now match the newest observed position
        $vessel->refresh();
        $this->assertEquals($newestTime->toIso8601String(), $vessel->last_seen_at->toIso8601String());
    }

    /**
     * TEST 6: Out-of-order AIS messages do not regress latest position pointer
     */
    public function test_out_of_order_messages_do_not_regress_latest_position(): void
    {
        $newestTime = now()->subMinutes(15);

        $vessel = Vessel::create([
            'name' => 'KM SAMUDRA JAYA',
            'mmsi' => '525000404',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => $newestTime,
        ]);

        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -7.0000,
            'longitude' => 112.0000,
            'observed_at' => $newestTime,
        ]);

        // Delayed/out-of-order packet arriving from 6 hours ago
        $delayedTime = now()->subHours(6);
        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -7.5000,
            'longitude' => 112.5000,
            'observed_at' => $delayedTime,
        ]);

        $this->syncService->execute(dryRun: false, staleHours: 24, rebuildLatest: true, adminUserId: $this->adminUser->id);

        $vessel->refresh();
        $this->assertEquals($newestTime->toIso8601String(), $vessel->last_seen_at->toIso8601String());
        $this->assertFalse($vessel->last_seen_at->equalTo($delayedTime));
    }

    /**
     * TEST 7: Duplicate positions are prevented by the unique database constraint
     */
    public function test_duplicate_positions_are_prevented_by_unique_constraint(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM BINTANG TIMUR',
            'mmsi' => '525000505',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
        ]);

        $time = now()->subHours(2);

        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -2.5000,
            'longitude' => 118.0000,
            'observed_at' => $time,
        ]);

        // Attempting to insert identical record with same vessel, timestamp, and coordinates must throw QueryException
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -2.5000,
            'longitude' => 118.0000,
            'observed_at' => $time,
        ]);
    }

    /**
     * TEST 8: Historical positions are 100% preserved (Zero records deleted during reset)
     */
    public function test_historical_positions_are_100_percent_preserved(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM PRESERVATION TEST',
            'mmsi' => '525000606',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $this->createPosition([
                'vessel_id' => $vessel->id,
                'latitude' => -1.0 - ($i * 0.1),
                'longitude' => 105.0 + ($i * 0.1),
                'observed_at' => now()->subHours($i * 4),
            ]);
        }

        $countBefore = VesselPosition::count();
        $this->assertEquals(10, $countBefore);

        // Execute resynchronization in apply mode
        $this->syncService->execute(dryRun: false, staleHours: 24, rebuildLatest: true, adminUserId: $this->adminUser->id);

        $countAfter = VesselPosition::count();
        $this->assertEquals($countBefore, $countAfter, 'Historical positions must never be deleted during position reset.');
    }

    /**
     * TEST 9: Dry-run mode makes zero operational database modifications
     */
    public function test_dry_run_makes_zero_database_modifications(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM DRY RUN TARGET',
            'mmsi' => '525000707',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => null,
        ]);

        $pos = $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => 0.0, // Invalid null-island
            'longitude' => 0.0,
            'observed_at' => now()->subHours(5),
            'data_quality_score' => 1.0,
        ]);

        $this->artisan('ais:reset-indonesia-positions', ['--dry-run' => true])
            ->assertExitCode(0);

        // Verify no changes occurred
        $this->assertNull($vessel->fresh()->last_seen_at);
        $this->assertEquals(1.0, $pos->fresh()->data_quality_score);
        $this->assertEquals(0, AuditLog::where('action', 'ais_indonesia_position_reset')->count());
    }

    /**
     * TEST 10: Apply mode executes synchronization and records an immutable audit log entry
     */
    public function test_apply_mode_executes_synchronization_and_records_audit_log(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM AUDIT TARGET',
            'mmsi' => '525000808',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => null,
        ]);

        $time = now()->subHours(2);
        $this->createPosition([
            'vessel_id' => $vessel->id,
            'latitude' => -4.5000,
            'longitude' => 120.0000,
            'observed_at' => $time,
        ]);

        $this->artisan('ais:reset-indonesia-positions', ['--apply' => true])
            ->assertExitCode(0);

        $vessel->refresh();
        $this->assertNotNull($vessel->last_seen_at);
        $this->assertEquals($time->toIso8601String(), $vessel->last_seen_at->toIso8601String());

        $auditLog = AuditLog::where('action', 'ais_indonesia_position_reset')->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals('vessel_positions', $auditLog->entity_type);
        $this->assertEquals('indonesia_waters_resync', $auditLog->entity_id);
        $this->assertIsArray($auditLog->details);
        $this->assertArrayHasKey('updated_vessels_count', $auditLog->details);
    }

    /**
     * TEST 11: AIS worker status telemetry reporting
     */
    public function test_ais_worker_status_telemetry_reporting(): void
    {
        $workerStatus = $this->syncService->getAisWorkerStatus();

        $this->assertIsArray($workerStatus);
        $this->assertArrayHasKey('status', $workerStatus);
        $this->assertArrayHasKey('provider', $workerStatus);
        $this->assertArrayHasKey('service_provider_id', $workerStatus);
        $this->assertEquals('ais_stream', $workerStatus['provider']);
    }

    /**
     * TEST 12: Map API returns reconstructed vessels and excludes quarantined positions
     */
    public function test_map_api_returns_reconstructed_and_quarantined_vessel_positions(): void
    {
        $validVessel = Vessel::create([
            'name' => 'KM ACTIVE FISHING',
            'mmsi' => '525111111',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $quarantinedVessel = Vessel::create([
            'name' => 'KM QUARANTINED',
            'mmsi' => '525222222',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        // Valid position
        $this->createPosition([
            'vessel_id' => $validVessel->id,
            'latitude' => -5.5000,
            'longitude' => 106.5000,
            'speed_knots' => 7.2,
            'course_degrees' => 120.0,
            'heading_degrees' => 125.0,
            'observed_at' => now()->subMinutes(10),
        ]);

        // Quarantined position (data_quality_score = 0.0)
        $this->createPosition([
            'vessel_id' => $quarantinedVessel->id,
            'latitude' => 0.0,
            'longitude' => 0.0,
            'speed_knots' => 0.0,
            'observed_at' => now()->subMinutes(10),
            'data_quality_score' => 0.0, // Quarantined
        ]);

        $response = $this->actingAs($this->adminUser)->getJson(route('api.layers.vessels'));
        $response->assertStatus(200);

        $geojson = $response->json();
        $this->assertEquals('FeatureCollection', $geojson['type']);

        $mmsiList = collect($geojson['features'])->pluck('properties.mmsi')->all();
        $this->assertContains('525111111', $mmsiList);
        $this->assertNotContains('525222222', $mmsiList, 'Quarantined positions must not be served to the live map layer.');
    }

    /**
     * TEST 13: Stale marker visual attributes in GeoJSON (is_fresh = false, speed, heading)
     */
    public function test_stale_marker_visual_attributes_in_geojson(): void
    {
        $staleVessel = Vessel::create([
            'name' => 'KM STALE MARKER',
            'mmsi' => '525333333',
            'vessel_type' => 'fishing',
            'source_provider_id' => $this->provider->id,
            'last_seen_at' => now()->subHours(12),
        ]);

        $this->createPosition([
            'vessel_id' => $staleVessel->id,
            'latitude' => -6.5000,
            'longitude' => 110.5000,
            'speed_knots' => 4.5,
            'course_degrees' => 200.0,
            'heading_degrees' => 205.0,
            'observed_at' => now()->subHours(12),
        ]);

        $response = $this->actingAs($this->adminUser)->getJson(route('api.layers.vessels'));
        $response->assertStatus(200);

        $geojson = $response->json();
        $feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525333333');

        $this->assertNotNull($feature);
        $this->assertFalse($feature['properties']['is_fresh'], 'Vessels with older positions must have is_fresh = false.');
        $this->assertEquals(4.5, $feature['properties']['speed_knots']);
        $this->assertEquals(200.0, $feature['properties']['course_degrees']);
        $this->assertEquals(205.0, $feature['properties']['heading_degrees']);
        $this->assertEquals('heading', $feature['properties']['orientation_source']);
    }
}
