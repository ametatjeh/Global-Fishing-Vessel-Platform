<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AisCoordinatesIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected AisStreamAdapter $adapter;
    protected SpatialQueryService $spatial;
    protected string $token = 'test-coord-token';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'maritime.providers.ais_stream.api_key' => 'test-api-key',
            'maritime.providers.ais_stream.ingest_token' => $this->token,
        ]);
        $this->adapter = app(AisStreamAdapter::class);
        $this->spatial = app(SpatialQueryService::class);
    }

    /**
     * Test 1: Ingestion of (0, 0) unacquired GPS sentinel is strictly rejected.
     */
    public function test_01_ingestion_of_zero_zero_sentinel_is_rejected(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525000001',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertNull($pos, 'Coordinates (0, 0) must be rejected as unacquired GPS sentinel');
        $this->assertEquals(0, VesselPosition::where('latitude', 0.0)->where('longitude', 0.0)->count());
    }

    /**
     * Test 2: Ingestion of out-of-bounds coordinates is strictly rejected.
     */
    public function test_02_ingestion_of_out_of_bounds_coordinates_is_rejected(): void
    {
        // Lat > 90
        $this->assertNull($this->adapter->ingestPositionMessage([
            'mmsi' => '525000002',
            'latitude' => 95.0,
            'longitude' => 104.0,
        ]));

        // Lat < -90
        $this->assertNull($this->adapter->ingestPositionMessage([
            'mmsi' => '525000002',
            'latitude' => -95.0,
            'longitude' => 104.0,
        ]));

        // Lng > 180
        $this->assertNull($this->adapter->ingestPositionMessage([
            'mmsi' => '525000002',
            'latitude' => 1.0,
            'longitude' => 185.0,
        ]));

        // Lng < -180
        $this->assertNull($this->adapter->ingestPositionMessage([
            'mmsi' => '525000002',
            'latitude' => 1.0,
            'longitude' => -185.0,
        ]));
    }

    /**
     * Test 3: Southern hemisphere (negative latitude) and positive longitude are stored accurately.
     */
    public function test_03_negative_latitude_and_positive_longitude_stored_accurately(): void
    {
        $targetLat = -6.2088000;
        $targetLng = 106.8456000;

        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525000003',
            'latitude' => $targetLat,
            'longitude' => $targetLng,
            'speed_over_ground' => 10.5,
            'course_over_ground' => 270.0,
            'ship_type' => 30, // Fishing
            'vessel_name' => 'KM BATAVIA',
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertNotNull($pos);
        $this->assertEquals($targetLat, (float) $pos->latitude);
        $this->assertEquals($targetLng, (float) $pos->longitude);

        // If PostgreSQL PostGIS is active, verify ST_X is longitude and ST_Y is latitude
        if (DB::connection()->getDriverName() === 'pgsql') {
            $spatial = DB::selectOne("
                SELECT ST_X(geom) as x, ST_Y(geom) as y, ST_SRID(geom) as srid 
                FROM vessel_positions WHERE id = ?
            ", [$pos->id]);

            $this->assertEquals(4326, $spatial->srid);
            $this->assertEqualsWithDelta($targetLng, (float) $spatial->x, 0.0001, 'ST_X must be Longitude');
            $this->assertEqualsWithDelta($targetLat, (float) $spatial->y, 0.0001, 'ST_Y must be Latitude');
        }
    }

    /**
     * Test 4: Live Vessels FeatureCollection outputs GeoJSON [longitude, latitude] and excludes (0,0).
     */
    public function test_04_live_vessels_geojson_order_and_zero_exclusion(): void
    {
        // 1. Valid vessel position in Indonesia
        $v1 = Vessel::create(['name' => 'VESSEL INDO', 'mmsi' => '525000004', 'vessel_type' => 'fishing']);
        $p1 = VesselPosition::create([
            'vessel_id' => $v1->id,
            'latitude' => -5.5000000,
            'longitude' => 110.5000000,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // 2. Legacy dirty vessel position with (0,0) coordinates
        $v2 = Vessel::create(['name' => 'VESSEL ZERO', 'mmsi' => '525000005', 'vessel_type' => 'cargo']);
        $p2 = VesselPosition::create([
            'vessel_id' => $v2->id,
            'latitude' => 0.0000000,
            'longitude' => 0.0000000,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        $geojson = $this->spatial->getLiveVesselsFeatureCollection(null, null, 100);

        $this->assertEquals('FeatureCollection', $geojson['type']);
        $this->assertNotEmpty($geojson['features']);

        // Find v1 feature
        $v1Feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525000004');
        $this->assertNotNull($v1Feature);
        $this->assertEquals('Point', $v1Feature['geometry']['type']);

        // Assert GeoJSON RFC 7946: index 0 is Longitude, index 1 is Latitude
        $coords = $v1Feature['geometry']['coordinates'];
        $this->assertEquals(110.5, $coords[0], 'Index 0 must be Longitude');
        $this->assertEquals(-5.5, $coords[1], 'Index 1 must be Latitude');

        // Assert (0,0) is strictly excluded from live map layer
        $v2Feature = collect($geojson['features'])->firstWhere('properties.mmsi', '525000005');
        $this->assertNull($v2Feature, '(0,0) positions must be excluded from live vessels feature collection');
    }

    /**
     * Test 5: Encounters FeatureCollection outputs strictly typed numeric [longitude, latitude].
     */
    public function test_05_encounters_geojson_coordinate_typing(): void
    {
        $v1 = Vessel::create(['name' => 'VESSEL 1', 'mmsi' => '525000010', 'vessel_type' => 'fishing']);
        $v2 = Vessel::create(['name' => 'VESSEL 2', 'mmsi' => '525000011', 'vessel_type' => 'reefer']);

        $enc = VesselEncounter::create([
            'encounter_code' => 'ENC-TEST-01',
            'vessel_1_id' => $v1->id,
            'vessel_2_id' => $v2->id,
            'latitude' => 1.2500000,
            'longitude' => 104.1500000,
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'duration_minutes' => 60,
            'min_distance_meters' => 150.0,
            'category' => 'fishing_carrier',
            'status' => 'candidate',
            'parameters_used' => ['max_distance_meters' => 500],
        ]);

        $geojson = $this->spatial->getEncountersFeatureCollection();

        $encFeature = collect($geojson['features'])->firstWhere('properties.code', 'ENC-TEST-01');
        $this->assertNotNull($encFeature);
        $this->assertEquals('Point', $encFeature['geometry']['type']);

        $coords = $encFeature['geometry']['coordinates'];
        $this->assertIsFloat($coords[0]);
        $this->assertIsFloat($coords[1]);
        $this->assertEquals(104.15, $coords[0], 'Index 0 must be Longitude');
        $this->assertEquals(1.25, $coords[1], 'Index 1 must be Latitude');
    }

    /**
     * Test 6: Dark Vessels (SAR) FeatureCollection outputs strictly typed numeric [longitude, latitude].
     */
    public function test_06_dark_vessels_geojson_coordinate_typing(): void
    {
        $scene = SatelliteScene::create([
            'scene_id' => 'SCENE-TEST-01',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'SAR-C',
            'sensor_mode' => 'IW',
            'polarization' => 'VV+VH',
            'acquisition_start' => now()->subHour(),
            'acquisition_end' => now(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
        ]);

        $det = SatelliteDetection::create([
            'satellite_scene_id' => $scene->id,
            'detection_identifier' => 'SAR-DET-TEST-01',
            'latitude' => -7.1234500,
            'longitude' => 112.5678900,
            'detected_at' => now(),
            'confidence_score' => 0.95,
            'correlation_status' => 'dark_vessel_candidate',
            'review_status' => 'possible_vessel',
            'tcr_db' => 19.5,
        ]);

        $geojson = $this->spatial->getSatelliteDetectionsFeatureCollection(null, true);

        $detFeature = collect($geojson['features'])->firstWhere('properties.identifier', 'SAR-DET-TEST-01');
        $this->assertNotNull($detFeature);
        $this->assertEquals('Point', $detFeature['geometry']['type']);

        $coords = $detFeature['geometry']['coordinates'];
        $this->assertIsFloat($coords[0]);
        $this->assertIsFloat($coords[1]);
        $this->assertEquals(112.56789, $coords[0], 'Index 0 must be Longitude');
        $this->assertEquals(-7.12345, $coords[1], 'Index 1 must be Latitude');
    }

    /**
     * Test 7: Historical Track GeoJSON outputs LineString and Points in [longitude, latitude] order.
     */
    public function test_07_historical_track_geojson_order(): void
    {
        $user = User::factory()->create();

        $v = Vessel::create(['name' => 'TRACK VESSEL', 'mmsi' => '525000007', 'vessel_type' => 'fishing']);

        $p1 = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -6.0000000,
            'longitude' => 106.0000000,
            'speed_knots' => 8.0,
            'course_degrees' => 90.0,
            'observed_at' => now()->subHours(2),
            'received_at' => now()->subHours(2),
        ]);

        $p2 = VesselPosition::create([
            'vessel_id' => $v->id,
            'latitude' => -5.9000000,
            'longitude' => 106.2000000,
            'speed_knots' => 8.5,
            'course_degrees' => 85.0,
            'observed_at' => now()->subHour(),
            'received_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/vessels/{$v->id}/track?range=24h");
        $response->assertStatus(200);

        $geojson = $response->json();
        $this->assertEquals('FeatureCollection', $geojson['type']);

        $lineFeature = collect($geojson['features'])->firstWhere('geometry.type', 'LineString');
        $this->assertNotNull($lineFeature);

        $lineCoords = $lineFeature['geometry']['coordinates'];
        $this->assertCount(2, $lineCoords);

        // Point 1: [lng, lat]
        $this->assertEquals(106.0, $lineCoords[0][0]);
        $this->assertEquals(-6.0, $lineCoords[0][1]);

        // Point 2: [lng, lat]
        $this->assertEquals(106.2, $lineCoords[1][0]);
        $this->assertEquals(-5.9, $lineCoords[1][1]);
    }
}

