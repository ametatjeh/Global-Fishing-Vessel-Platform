<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\GlobalLandPolygon;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\GlobalLandValidationService;
use App\Services\GlobalVesselPositionResetService;
use App\Services\Providers\AisStreamAdapter;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GlobalVesselPositionResetTest extends TestCase
{
    use RefreshDatabase;

    protected AisStreamAdapter $adapter;
    protected SpatialQueryService $spatial;
    protected GlobalLandValidationService $landValidator;
    protected GlobalVesselPositionResetService $resetService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maritime.providers.ais_stream.api_key' => 'test-api-key',
            'maritime.providers.ais_stream.ingest_token' => 'test-token',
        ]);

        $this->adapter = app(AisStreamAdapter::class);
        $this->spatial = app(SpatialQueryService::class);
        $this->landValidator = app(GlobalLandValidationService::class);
        $this->resetService = app(GlobalVesselPositionResetService::class);

        // Ensure land dataset is imported for the test session
        if (!$this->landValidator->isTablePopulated()) {
            $this->landValidator->importDataset();
        }
    }

    /**
     * Test 1: Coordinate on land is quarantined and excluded from active map markers.
     */
    public function test_01_coordinate_on_land_is_quarantined_and_excluded_from_active_markers(): void
    {
        // Coordinate in Southeast Sulawesi landmass (-3.49, 121.38)
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525001017',
            'vessel_name' => 'KM GELORA SAMUDERA 18',
            'latitude' => -3.4900000,
            'longitude' => 121.3800000,
            'ship_type' => 30, // Fishing
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertNotNull($pos, 'Position record must be preserved for audit history');
        $this->assertTrue((bool) $pos->is_on_land, 'Position must be flagged as is_on_land = true');
        $this->assertEquals(0.0, (float) $pos->data_quality_score, 'Data quality score must be set to 0.0 for land coordinate');

        // Check vessel last_seen_at pointer
        $vessel = Vessel::where('mmsi', '525001017')->first();
        $this->assertNotNull($vessel);
        $this->assertNull($vessel->last_seen_at, 'Vessel last_seen_at pointer must remain null when coordinate is on land');

        // Check map feature collection
        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        $feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525001017');
        $this->assertNull($feature, 'Land coordinate must never appear as an active map marker');
    }

    /**
     * Test 2: Coordinate at sea is accepted for active marker.
     */
    public function test_02_coordinate_at_sea_is_accepted_for_active_marker(): void
    {
        // Java Sea navigable waters (-5.50, 110.50)
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525002001',
            'vessel_name' => 'KM LAUT INDAH',
            'latitude' => -5.5000000,
            'longitude' => 110.5000000,
            'ship_type' => 30,
            'speed_over_ground' => 8.2,
            'course_over_ground' => 120.0,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertNotNull($pos);
        $this->assertFalse((bool) $pos->is_on_land, 'Position must be flagged as is_on_land = false');
        $this->assertEquals(1.0, (float) $pos->data_quality_score);

        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        $feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525002001');

        $this->assertNotNull($feature, 'Sea coordinate must appear as active map marker');
        $this->assertEquals([110.5, -5.5], $feature['geometry']['coordinates']);
        $this->assertTrue($feature['properties']['is_fresh']);
    }

    /**
     * Test 3: Coordinate on high seas outside EEZ is accepted.
     */
    public function test_03_coordinate_on_high_seas_outside_eez_is_accepted(): void
    {
        // Pacific Ocean open waters (0.0, 150.0), far outside any EEZ
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525003001',
            'vessel_name' => 'OCEAN EXPLORER',
            'latitude' => 0.0000000,
            'longitude' => 150.0000000,
            'ship_type' => 70, // Cargo
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertNotNull($pos);
        $this->assertFalse((bool) $pos->is_on_land);

        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        $feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525003001');

        $this->assertNotNull($feature, 'High seas coordinate must not be excluded');
        $this->assertEquals([150.0, 0.0], $feature['geometry']['coordinates']);
    }

    /**
     * Test 4: Coordinate (0, 0) Null Island sentinel is quarantined.
     */
    public function test_04_coordinate_zero_zero_null_island_is_quarantined(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525004001',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertNull($pos, 'Ingestion must reject raw (0, 0) unacquired GPS packet');

        // Check if legacy (0,0) record in database is quarantined
        $v = Vessel::create(['name' => 'ZERO VESSEL', 'mmsi' => '525004002', 'vessel_type' => 'fishing']);
        $legacy = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => 0.0,
            'longitude' => 0.0,
            'observed_at' => now(),
            'received_at' => now(),
            'data_quality_score' => 1.0,
        ]);

        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        $feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525004002');
        $this->assertNull($feature, '(0,0) sentinel must never be rendered on live map');
    }

    /**
     * Test 5: Stale position is flagged stale and not considered real-time.
     */
    public function test_05_stale_position_is_flagged_stale_and_not_realtime(): void
    {
        $v = Vessel::create(['name' => 'STALE VESSEL', 'mmsi' => '525005001', 'vessel_type' => 'fishing']);
        $pos = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -6.0000000,
            'longitude' => 106.5000000,
            'observed_at' => now()->subHours(6), // 6 hours old (> 2h threshold)
            'received_at' => now()->subHours(6),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);
        $v->update(['last_seen_at' => $pos->observed_at]);

        $geojsonAll = $this->spatial->getLiveVesselsFeatureCollection();
        $feature = collect($geojsonAll['features'])->firstWhere('properties.mmsi', '525005001');
        $this->assertNotNull($feature);
        $this->assertFalse($feature['properties']['is_fresh'], 'Position >= 2h must have is_fresh = false');

        // Filter: freshness = live should exclude it
        $geojsonLive = $this->spatial->getLiveVesselsFeatureCollection(null, null, 100, ['freshness' => 'live']);
        $this->assertNull(collect($geojsonLive['features'])->firstWhere('properties.mmsi', '525005001'));

        // Filter: freshness = stale should include it
        $geojsonStale = $this->spatial->getLiveVesselsFeatureCollection(null, null, 100, ['freshness' => 'stale']);
        $this->assertNotNull(collect($geojsonStale['features'])->firstWhere('properties.mmsi', '525005001'));
    }

    /**
     * Test 6: Previous valid sea position is selected when latest position is on land.
     */
    public function test_06_previous_valid_sea_position_is_selected_when_latest_position_is_on_land(): void
    {
        $v = Vessel::create(['name' => 'MULTI POS VESSEL', 'mmsi' => '525006001', 'vessel_type' => 'fishing']);

        // 1. Earlier position at sea (Java Sea -5.5, 110.5), observed 4 hours ago
        $seaPos = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -5.5000000,
            'longitude' => 110.5000000,
            'observed_at' => now()->subHours(4),
            'received_at' => now()->subHours(4),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        // 2. Newer corrupt position on land (-3.49, 121.38), observed 10 minutes ago
        $landPos = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -3.4900000,
            'longitude' => 121.3800000,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
            'is_on_land' => true,
            'data_quality_score' => 0.0,
        ]);

        // Run global resync command in apply mode
        $exitCode = Artisan::call('ais:reset-global-positions', ['--apply' => true]);
        $this->assertEquals(0, $exitCode);

        // Vessel pointer must be reconstructed to the sea position, NOT the land position
        $v->refresh();
        $this->assertEquals($seaPos->observed_at->toIso8601String(), $v->last_seen_at->toIso8601String());

        // Map collection must return the sea coordinates
        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        $feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525006001');

        $this->assertNotNull($feature);
        $this->assertEquals([110.5, -5.5], $feature['geometry']['coordinates'], 'Marker must be located at the earlier sea position');
        $this->assertFalse($feature['properties']['is_fresh'], 'Freshness must reflect the age of the valid sea position (4h old)');
    }

    /**
     * Test 7: Vessel with no valid sea positions produces no marker.
     */
    public function test_07_vessel_with_no_valid_sea_positions_produces_no_marker(): void
    {
        $v = Vessel::create(['name' => 'LAND ONLY VESSEL', 'mmsi' => '525007001', 'vessel_type' => 'fishing']);

        // Only land positions
        VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -3.4900000,
            'longitude' => 121.3800000,
            'observed_at' => now()->subHour(),
            'received_at' => now()->subHour(),
            'is_on_land' => true,
            'data_quality_score' => 0.0,
        ]);

        Artisan::call('ais:reset-global-positions', ['--apply' => true]);

        $v->refresh();
        $this->assertNull($v->last_seen_at, 'Vessel last_seen_at must be cleared to null');

        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        $feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525007001');
        $this->assertNull($feature, 'Vessel without any valid sea position must not produce a map marker');
    }

    /**
     * Test 8: Historical AIS positions are never deleted.
     */
    public function test_08_historical_ais_positions_are_never_deleted(): void
    {
        $user = User::factory()->create();
        $v = Vessel::create(['name' => 'HISTORY VESSEL', 'mmsi' => '525008001', 'vessel_type' => 'fishing']);

        $p1 = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -3.4900000, // Land
            'longitude' => 121.3800000,
            'observed_at' => now()->subHours(5),
            'received_at' => now()->subHours(5),
            'is_on_land' => true,
            'data_quality_score' => 0.0,
        ]);

        $p2 = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -5.5000000, // Sea
            'longitude' => 110.5000000,
            'observed_at' => now()->subHours(3),
            'received_at' => now()->subHours(3),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        Artisan::call('ais:reset-global-positions', ['--apply' => true]);

        // Historical rows in DB must not be deleted
        $this->assertEquals(2, VesselPosition::where('vessel_id', $v->id)->count());

        // Track endpoint must still be accessible
        $response = $this->actingAs($user)->getJson("/api/vessels/{$v->id}/track?range=24h");
        $response->assertStatus(200);
        $this->assertEquals('FeatureCollection', $response->json('type'));
    }

    /**
     * Test 9: No marker duplication - exactly one marker per vessel.
     */
    public function test_09_no_marker_duplication_single_marker_per_vessel(): void
    {
        $v = Vessel::create(['name' => 'SINGLE MARKER VESSEL', 'mmsi' => '525009001', 'vessel_type' => 'fishing']);

        // 5 sea positions over 5 hours
        for ($i = 5; $i >= 1; $i--) {
            VesselPosition::create([
                'vessel_id' => $v->id,
                'latitude' => -5.0 + ($i * 0.1),
                'longitude' => 110.0 + ($i * 0.1),
                'observed_at' => now()->subHours($i),
                'received_at' => now()->subHours($i),
                'is_on_land' => false,
                'data_quality_score' => 1.0,
            ]);
        }

        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        $markers = collect($geojson['features'])->where('properties.mmsi', '525009001');

        $this->assertCount(1, $markers, 'There must be strictly 1 marker per vessel');
    }

    /**
     * Test 10: All vessel types are supported.
     */
    public function test_10_all_vessel_types_are_supported(): void
    {
        $types = [
            ['mmsi' => '525010001', 'ship_type' => 30, 'expected' => 'fishing'],
            ['mmsi' => '525010002', 'ship_type' => 70, 'expected' => 'cargo'],
            ['mmsi' => '525010003', 'ship_type' => 80, 'expected' => 'tanker'],
            ['mmsi' => '525010004', 'ship_type' => 37, 'expected' => 'pleasure'],
            ['mmsi' => '525010005', 'ship_type' => 60, 'expected' => 'passenger'],
            ['mmsi' => '525010006', 'ship_type' => null, 'expected' => 'unknown'],
        ];

        foreach ($types as $idx => $t) {
            $pos = $this->adapter->ingestPositionMessage([
                'mmsi' => $t['mmsi'],
                'vessel_name' => "VESSEL-{$t['expected']}",
                'latitude' => -5.0 - ($idx * 0.2),
                'longitude' => 110.0 + ($idx * 0.2),
                'ship_type' => $t['ship_type'],
                'timestamp' => now()->toIso8601String(),
            ]);

            $this->assertNotNull($pos, "Position for {$t['expected']} must be accepted");
            $vessel = Vessel::where('mmsi', $t['mmsi'])->first();
            $this->assertEquals($t['expected'], $vessel->vessel_type);
        }

        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 2000, ['vessel_type' => 'all']);
        foreach ($types as $t) {
            $feature = collect($geojson['features'])->firstWhere('properties.mmsi', $t['mmsi']);
            $this->assertNotNull($feature, "Map marker for {$t['expected']} must be present");
        }
    }
}

