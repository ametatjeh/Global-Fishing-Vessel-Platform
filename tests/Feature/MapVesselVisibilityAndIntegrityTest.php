<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVesselVisibilityAndIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;
    protected DataProvider $aisProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Maritime Operations Analyst',
            'is_active' => true,
        ]);

        $this->aisProvider = DataProvider::firstOrCreate(
            ['slug' => 'ais-stream'],
            [
                'name' => 'AIS Live Stream Telemetry',
                'type' => 'ais_stream',
                'api_url' => 'wss://stream.aisstream.io/v0/stream',
                'auth_type' => 'bearer_token',
                'is_enabled' => true,
                'health_status' => 'streaming',
            ]
        );

        $this->actingAs($this->analyst);
    }

    public function test_01_vessel_with_valid_ais_position_appears_in_map_api(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM SAMUDERA TEST 01',
            'mmsi' => '525001991',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        $pos = VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => -5.4321,
            'longitude' => 106.1234,
            'speed_knots' => 8.2,
            'course_degrees' => 120.0,
            'heading_degrees' => 121,
            'nav_status' => 'engaged_in_fishing',
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        $response = $this->getJson('/api/layers/vessels');
        $response->assertOk();
        $response->assertJsonStructure([
            'type',
            'features' => [
                '*' => [
                    'type',
                    'id',
                    'geometry' => ['type', 'coordinates'],
                    'properties' => [
                        'vessel_id',
                        'name',
                        'mmsi',
                        'flag_state',
                        'vessel_type',
                        'speed_knots',
                        'course_degrees',
                        'heading_degrees',
                        'orientation_degrees',
                        'orientation_source',
                        'has_orientation',
                        'nav_status',
                        'observed_at',
                        'provider',
                    ]
                ]
            ]
        ]);

        $features = $response->json('features');
        $this->assertNotEmpty($features);

        $matched = collect($features)->firstWhere('properties.mmsi', '525001991');
        $this->assertNotNull($matched);
        $this->assertEquals(106.1234, $matched['geometry']['coordinates'][0]);
        $this->assertEquals(-5.4321, $matched['geometry']['coordinates'][1]);
        $this->assertEquals('KM SAMUDERA TEST 01', $matched['properties']['name']);
        $this->assertEquals('heading', $matched['properties']['orientation_source']);
        $this->assertEquals(121, $matched['properties']['orientation_degrees']);
        $this->assertTrue($matched['properties']['has_orientation']);
    }

    public function test_02_vessel_without_positions_is_not_rendered_on_map(): void
    {
        Vessel::create([
            'name' => 'KM GHOST SHIP',
            'mmsi' => '525001992',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        $response = $this->getJson('/api/layers/vessels');
        $response->assertOk();

        $features = $response->json('features');
        $matched = collect($features)->firstWhere('properties.mmsi', '525001992');
        $this->assertNull($matched);
    }

    public function test_03_latest_position_is_selected_per_vessel_without_duplication(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM MULTI POS',
            'mmsi' => '525001993',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        // Older position
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => -6.0,
            'longitude' => 106.0,
            'observed_at' => now()->subHours(5),
            'received_at' => now()->subHours(5),
        ]);

        // Newer position
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => -5.0,
            'longitude' => 107.0,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
        ]);

        $response = $this->getJson('/api/layers/vessels?q=525001993');
        $response->assertOk();

        $features = $response->json('features');
        $this->assertCount(1, $features);
        $this->assertEquals(107.0, $features[0]['geometry']['coordinates'][0]);
        $this->assertEquals(-5.0, $features[0]['geometry']['coordinates'][1]);
    }

    public function test_04_bounding_box_filter_constrains_features_correctly(): void
    {
        $vessel1 = Vessel::create(['name' => 'INSIDE BBOX', 'mmsi' => '525001994']);
        VesselPosition::create([
            'vessel_id' => $vessel1->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => -5.0,
            'longitude' => 106.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        $vessel2 = Vessel::create(['name' => 'OUTSIDE BBOX', 'mmsi' => '525001995']);
        VesselPosition::create([
            'vessel_id' => $vessel2->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => 50.0,
            'longitude' => 2.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // Bounding box around Java Sea: minLng=100, minLat=-10, maxLng=115, maxLat=0
        $response = $this->getJson('/api/layers/vessels?bbox=100,-10,115,0');
        $response->assertOk();

        $features = $response->json('features');
        $this->assertCount(1, $features);
        $this->assertEquals('525001994', $features[0]['properties']['mmsi']);
    }

    public function test_05_freshness_filter_distinguishes_live_and_stale(): void
    {
        $liveVessel = Vessel::create(['name' => 'LIVE VESSEL', 'mmsi' => '525001996']);
        VesselPosition::create([
            'vessel_id' => $liveVessel->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => -5.0,
            'longitude' => 106.0,
            'observed_at' => now()->subMinutes(15),
            'received_at' => now()->subMinutes(15),
        ]);

        $staleVessel = Vessel::create(['name' => 'STALE VESSEL', 'mmsi' => '525001997']);
        VesselPosition::create([
            'vessel_id' => $staleVessel->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => -5.5,
            'longitude' => 106.5,
            'observed_at' => now()->subHours(6),
            'received_at' => now()->subHours(6),
        ]);

        // Freshness: live
        $resLive = $this->getJson('/api/layers/vessels?freshness=live');
        $resLive->assertOk();
        $liveMmsis = collect($resLive->json('features'))->pluck('properties.mmsi')->all();
        $this->assertContains('525001996', $liveMmsis);
        $this->assertNotContains('525001997', $liveMmsis);

        // Freshness: stale
        $resStale = $this->getJson('/api/layers/vessels?freshness=stale');
        $resStale->assertOk();
        $staleMmsis = collect($resStale->json('features'))->pluck('properties.mmsi')->all();
        $this->assertContains('525001997', $staleMmsis);
        $this->assertNotContains('525001996', $staleMmsis);
    }

    public function test_06_ingested_aisstream_frame_immediately_appears_on_map_api(): void
    {
        $adapter = app(AisStreamAdapter::class);
        $mmsi = '525887766';

        $rawFrame = [
            'MessageType' => 'PositionReport',
            'MetaData' => [
                'MMSI' => (int) $mmsi,
                'ShipName' => 'KM STREAMED DIRECT',
                'Latitude' => -6.2000,
                'Longitude' => 106.8166,
                'time_utc' => now()->toIso8601String(),
            ],
            'Message' => [
                'PositionReport' => [
                    'UserID' => (int) $mmsi,
                    'Latitude' => -6.2000,
                    'Longitude' => 106.8166,
                    'Sog' => 12.3,
                    'Cog' => 180.0,
                    'TrueHeading' => 180,
                    'NavigationalStatus' => 0,
                ],
            ],
        ];

        $ingestRes = $adapter->parseAndIngestRawFrame($rawFrame);
        $this->assertEquals('inserted', $ingestRes['status']);

        $mapResp = $this->getJson('/api/layers/vessels?q=' . $mmsi);
        $mapResp->assertOk();

        $features = $mapResp->json('features');
        $this->assertCount(1, $features);
        $this->assertEquals($mmsi, $features[0]['properties']['mmsi']);
        $this->assertEquals('KM STREAMED DIRECT', $features[0]['properties']['name']);
        $this->assertEquals(12.3, $features[0]['properties']['speed_knots']);
        $this->assertEquals('AIS Live Stream Telemetry', $features[0]['properties']['provider']);
    }
}

