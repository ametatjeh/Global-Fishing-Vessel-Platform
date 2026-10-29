<?php

namespace Tests\Feature;

use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedMapFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Maritime Operations Analyst',
        ]);
    }

    public function test_map_page_renders_advanced_filters_panel_and_search_bar(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee('id="inputVesselSearch"', false);
        $response->assertSee('Search vessel, MMSI, or candidate...');
        $response->assertSee('id="btnToggleAdvancedFilters"', false);
        $response->assertSee('id="advancedFiltersPanel"', false);
        $response->assertSee('Advanced Maritime Filters');
        $response->assertSee('Identitas Armada Kapal (AIS)');
        $response->assertSee('Bendera Negara (Flag State)');
        $response->assertSee('Tipe Kapal (Vessel Type)');
        $response->assertSee('Rentang Kecepatan SOG (knots)');
        $response->assertSee('Rentang Arah Gerak COG (derajat)');
        $response->assertSee('Ketersediaan Haluan (Heading)');
        $response->assertSee('Usia Telemetri AIS (Freshness)');
        $response->assertSee('Kandidat Anomali Radar SAR');
        $response->assertSee('Status Review Analis');
        $response->assertSee('Status Korelasi AIS');
        $response->assertSee('Rentang TCR Hamburan Radar (dB)');
        $response->assertSee('Platform Satelit Radar');
        $response->assertSee('Rentang Waktu Observasi');
        $response->assertSee('Jendela Historis AIS Track');
        $response->assertSee('Terapkan Filter');
        $response->assertSee('Reset');
        $response->assertDontSee('RFMO');
        $response->assertDontSee('Cluster Padat');
    }

    public function test_unified_search_api_finds_vessels_and_sar_candidates(): void
    {
        // 1. Create a vessel
        $vessel = Vessel::create([
            'name' => 'PACIFIC EXPLORER',
            'mmsi' => '525889900',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.1234,
            'longitude' => 105.5678,
            'speed_knots' => 9.2,
            'course_degrees' => 110.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // 2. Create a SAR candidate
        $scene = SatelliteScene::create([
            'scene_id' => 'SAR-TEST-SCENE-001',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'SAR-C',
            'sensor_mode' => 'EW',
            'polarization' => 'HH',
            'acquisition_start' => now()->subHours(3),
            'acquisition_end' => now()->subHours(2),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[80, -50], [85, -50], [85, -55], [80, -55], [80, -50]]]]),
            'processing_status' => 'detection_completed',
        ]);

        $cand = SatelliteDetection::create([
            'satellite_scene_id' => $scene->id,
            'detection_identifier' => 'SAR-CAND-TARGET-99',
            'latitude' => -52.4321,
            'longitude' => 82.1234,
            'detected_at' => now()->subHours(2),
            'estimated_length_meters' => 75.0,
            'estimated_width_meters' => 20.0,
            'confidence_score' => 0.94,
            'tcr_db' => 18.2,
            'correlation_status' => 'insufficient_data',
            'review_status' => SatelliteDetection::REVIEW_PENDING,
        ]);

        // Query by vessel name
        $resVessel = $this->getJson('/api/map/search?q=EXPLORER');
        $resVessel->assertOk();
        $this->assertGreaterThanOrEqual(1, $resVessel->json('count'));
        $this->assertEquals('vessel', $resVessel->json('results.0.type'));
        $this->assertEquals('PACIFIC EXPLORER', $resVessel->json('results.0.title'));
        $this->assertTrue($resVessel->json('results.0.has_coords'));
        $this->assertEquals(-5.1234, $resVessel->json('results.0.lat'));

        // Query by candidate identifier
        $resCand = $this->getJson('/api/map/search?q=TARGET-99');
        $resCand->assertOk();
        $this->assertGreaterThanOrEqual(1, $resCand->json('count'));
        $this->assertEquals('sar_candidate', $resCand->json('results.0.type'));
        $this->assertEquals('SAR-CAND-TARGET-99', $resCand->json('results.0.title'));
        $this->assertTrue($resCand->json('results.0.has_coords'));
        $this->assertEquals(-52.4321, $resCand->json('results.0.lat'));

        // Empty search returns empty results
        $resEmpty = $this->getJson('/api/map/search?q=NONEXISTENT_VESSEL_XYZ');
        $resEmpty->assertOk();
        $this->assertEquals(0, $resEmpty->json('count'));
        $this->assertEmpty($resEmpty->json('results'));
    }

    public function test_vessels_layer_filters_by_flag_type_sog_and_heading_status(): void
    {
        // Vessel 1: Indonesian Fishing, SOG 12 kn, Valid Heading 45°
        $v1 = Vessel::create(['name' => 'KM INDO JAYA', 'mmsi' => '525000101', 'flag_state' => 'IDN', 'vessel_type' => 'fishing']);
        VesselPosition::create([
            'vessel_id' => $v1->id,
            'latitude' => -5.0,
            'longitude' => 105.0,
            'speed_knots' => 12.0,
            'course_degrees' => 45.0,
            'heading_degrees' => 45.0,
            'observed_at' => now()->subMinutes(15),
            'received_at' => now()->subMinutes(15),
        ]);

        // Vessel 2: Chinese Tanker, SOG 6 kn, COG Fallback (Heading null)
        $v2 = Vessel::create(['name' => 'TIANJIN VOYAGER', 'mmsi' => '412000102', 'flag_state' => 'CHN', 'vessel_type' => 'tanker']);
        VesselPosition::create([
            'vessel_id' => $v2->id,
            'latitude' => -6.0,
            'longitude' => 106.0,
            'speed_knots' => 6.0,
            'course_degrees' => 180.0,
            'heading_degrees' => null,
            'observed_at' => now()->subHours(5), // Stale
            'received_at' => now()->subHours(5),
        ]);

        // 1. Filter by flag IDN
        $resFlag = $this->getJson('/api/layers/vessels?flag=IDN');
        $resFlag->assertOk();
        $this->assertCount(1, $resFlag->json('features'));
        $this->assertEquals('KM INDO JAYA', $resFlag->json('features.0.properties.name'));

        // 2. Filter by vessel_type tanker
        $resType = $this->getJson('/api/layers/vessels?vessel_type=tanker');
        $resType->assertOk();
        $this->assertCount(1, $resType->json('features'));
        $this->assertEquals('TIANJIN VOYAGER', $resType->json('features.0.properties.name'));

        // 3. Filter by SOG range: min_sog=10
        $resSog = $this->getJson('/api/layers/vessels?min_sog=10');
        $resSog->assertOk();
        $this->assertCount(1, $resSog->json('features'));
        $this->assertEquals('KM INDO JAYA', $resSog->json('features.0.properties.name'));

        // 4. Filter by heading_status: has_heading
        $resHead = $this->getJson('/api/layers/vessels?heading_status=has_heading');
        $resHead->assertOk();
        $this->assertCount(1, $resHead->json('features'));
        $this->assertEquals('KM INDO JAYA', $resHead->json('features.0.properties.name'));

        // 5. Filter by freshness: live (< 2h)
        $resFresh = $this->getJson('/api/layers/vessels?freshness=live');
        $resFresh->assertOk();
        $this->assertCount(1, $resFresh->json('features'));
        $this->assertEquals('KM INDO JAYA', $resFresh->json('features.0.properties.name'));

        // 6. Filter by freshness: stale (>= 2h)
        $resStale = $this->getJson('/api/layers/vessels?freshness=stale');
        $resStale->assertOk();
        $this->assertCount(1, $resStale->json('features'));
        $this->assertEquals('TIANJIN VOYAGER', $resStale->json('features.0.properties.name'));
    }

    public function test_dark_vessels_layer_filters_by_review_status_tcr_and_platform(): void
    {
        $sceneA = SatelliteScene::create([
            'scene_id' => 'SCENE-A',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'SAR-C',
            'sensor_mode' => 'EW',
            'polarization' => 'HH',
            'acquisition_start' => now()->subDay(),
            'acquisition_end' => now()->subDay(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[80, -50], [85, -50], [85, -55], [80, -55], [80, -50]]]]),
            'processing_status' => 'detection_completed',
        ]);

        $cand1 = SatelliteDetection::create([
            'satellite_scene_id' => $sceneA->id,
            'detection_identifier' => 'SAR-DET-A1',
            'latitude' => -51.0,
            'longitude' => 81.0,
            'detected_at' => now()->subDay(),
            'estimated_length_meters' => 60.0,
            'estimated_width_meters' => 15.0,
            'confidence_score' => 0.88,
            'tcr_db' => 12.5,
            'correlation_status' => 'insufficient_data',
            'review_status' => 'pending_review',
        ]);

        $cand2 = SatelliteDetection::create([
            'satellite_scene_id' => $sceneA->id,
            'detection_identifier' => 'SAR-DET-A2',
            'latitude' => -52.0,
            'longitude' => 82.0,
            'detected_at' => now()->subDay(),
            'estimated_length_meters' => 120.0,
            'estimated_width_meters' => 30.0,
            'confidence_score' => 0.95,
            'tcr_db' => 22.0,
            'correlation_status' => 'insufficient_data',
            'review_status' => 'possible_vessel',
        ]);

        // 1. Filter by review_status: possible_vessel
        $resRev = $this->getJson('/api/layers/dark-vessels?review_status=possible_vessel');
        $resRev->assertOk();
        $this->assertCount(1, $resRev->json('features'));
        $this->assertEquals('SAR-DET-A2', $resRev->json('features.0.properties.identifier'));

        // 2. Filter by min_tcr: 20 dB
        $resTcr = $this->getJson('/api/layers/dark-vessels?min_tcr=20');
        $resTcr->assertOk();
        $this->assertCount(1, $resTcr->json('features'));
        $this->assertEquals('SAR-DET-A2', $resTcr->json('features.0.properties.identifier'));

        // 3. Filter by platform Sentinel-1A
        $resPlat = $this->getJson('/api/layers/dark-vessels?platform=Sentinel-1A');
        $resPlat->assertOk();
        $this->assertCount(2, $resPlat->json('features'));

        // 4. Filter by non-existent platform returns zero
        $resNone = $this->getJson('/api/layers/dark-vessels?platform=TerraSAR-X');
        $resNone->assertOk();
        $this->assertCount(0, $resNone->json('features'));
    }
}

