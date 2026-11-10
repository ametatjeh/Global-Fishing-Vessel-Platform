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
            'is_active' => true,
        ]);

        $this->actingAs($this->analyst);
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
        $response->assertSee('1. Vessel Fleet Identity (AIS)');
        $response->assertSee('Flag State');
        $response->assertSee('Vessel Type');
        $response->assertSee('Speed SOG Range (knots)');
        $response->assertSee('Course COG Range (degrees)');
        $response->assertSee('Heading Availability');
        $response->assertSee('AIS Telemetry Freshness');
        $response->assertSee('2. SAR Radar Anomaly Candidates');
        $response->assertSee('Analyst Review Status');
        $response->assertSee('AIS Correlation Status');
        $response->assertSee('Radar Backscatter TCR Range (dB)');
        $response->assertSee('Radar Satellite Platform');
        $response->assertSee('Observation Time Window');
        $response->assertSee('AIS Track History Window');
        $response->assertSee('Apply Filters');
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

        // 1. Filter by flag IDN and ISO2 ID
        $resFlag = $this->getJson('/api/layers/vessels?flag=IDN');
        $resFlag->assertOk();
        $this->assertCount(1, $resFlag->json('features'));
        $this->assertEquals('KM INDO JAYA', $resFlag->json('features.0.properties.name'));

        $resFlagIso2 = $this->getJson('/api/layers/vessels?flag=ID');
        $resFlagIso2->assertOk();
        $this->assertCount(1, $resFlagIso2->json('features'));
        $this->assertEquals('KM INDO JAYA', $resFlagIso2->json('features.0.properties.name'));

        // 1b. Filter by flag CHN and ISO2 CN
        $resFlagChn = $this->getJson('/api/layers/vessels?flag=CN');
        $resFlagChn->assertOk();
        $this->assertCount(1, $resFlagChn->json('features'));
        $this->assertEquals('TIANJIN VOYAGER', $resFlagChn->json('features.0.properties.name'));

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

    public function test_multi_filter_combination_and_flag_state_provenance_integrity(): void
    {
        // Vessel 1: Verified Explicit Indonesian Fishing, Live, Heading 90°
        $v1 = Vessel::create(['name' => 'KM BERKAH 01', 'mmsi' => '525000001', 'flag_state' => 'IDN', 'vessel_type' => 'fishing']);
        VesselPosition::create([
            'vessel_id' => $v1->id,
            'latitude' => -5.0,
            'longitude' => 106.0,
            'speed_knots' => 8.5,
            'course_degrees' => 90.0,
            'heading_degrees' => 90.0,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
        ]);

        // Vessel 2: Australian Cargo, SOG 15 kn, Heading Null (COG fallback 180°), Live
        $v2 = Vessel::create(['name' => 'SYDNEY EXPRESS', 'mmsi' => '503000002', 'flag_state' => 'AUS', 'vessel_type' => 'cargo']);
        VesselPosition::create([
            'vessel_id' => $v2->id,
            'latitude' => -10.0,
            'longitude' => 120.0,
            'speed_knots' => 15.0,
            'course_degrees' => 180.0,
            'heading_degrees' => null,
            'observed_at' => now()->subMinutes(30),
            'received_at' => now()->subMinutes(30),
        ]);

        // Vessel 3: Conflict Detected - Explicit Flag CN, but Australian MID 503
        $v3 = Vessel::create(['name' => 'PACIFIC CONFLICT', 'mmsi' => '503999999', 'flag_state' => 'CN', 'vessel_type' => 'tanker']);
        VesselPosition::create([
            'vessel_id' => $v3->id,
            'latitude' => 0.0,
            'longitude' => 108.0,
            'speed_knots' => 11.0,
            'course_degrees' => 270.0,
            'heading_degrees' => 270.0,
            'observed_at' => now()->subHours(1),
            'received_at' => now()->subHours(1),
        ]);

        // Vessel 4: MID Administrative Indicator only (flag_state is null, MMSI has IDN MID 525)
        $v4 = Vessel::create(['name' => 'UNREGISTERED JAYA', 'mmsi' => '525999888', 'flag_state' => null, 'vessel_type' => 'fishing']);
        VesselPosition::create([
            'vessel_id' => $v4->id,
            'latitude' => -4.0,
            'longitude' => 107.0,
            'speed_knots' => 6.0,
            'course_degrees' => 45.0,
            'heading_degrees' => null,
            'observed_at' => now()->subHours(6), // Stale (>2h)
            'received_at' => now()->subHours(6),
        ]);

        // Vessel 5: Complete Unknown (no flag_state, invalid MMSI)
        $v5 = Vessel::create(['name' => 'GHOST VESSEL', 'mmsi' => '000000000', 'flag_state' => null, 'vessel_type' => 'other']);
        VesselPosition::create([
            'vessel_id' => $v5->id,
            'latitude' => -2.0,
            'longitude' => 104.0,
            'speed_knots' => 3.0,
            'course_degrees' => null,
            'heading_degrees' => null,
            'observed_at' => now()->subDays(1),
            'received_at' => now()->subDays(1),
        ]);

        // A. Verify Multi-filter combination: flag=ID + vessel_type=fishing + min_sog=7 + max_sog=10 + heading_status=has_heading + freshness=live
        $resMulti = $this->getJson('/api/layers/vessels?flag=ID&vessel_type=fishing&min_sog=7&max_sog=10&heading_status=has_heading&freshness=live');
        $resMulti->assertOk();
        $this->assertCount(1, $resMulti->json('features'));
        $this->assertEquals('KM BERKAH 01', $resMulti->json('features.0.properties.name'));

        // B. Verify Flag State Provenance Categories
        $allRes = $this->getJson('/api/layers/vessels');
        $allRes->assertOk();
        $featuresByMmsi = collect($allRes->json('features'))->keyBy('properties.mmsi');

        // 1. Verified explicit
        $f1 = $featuresByMmsi->get('525000001');
        $this->assertEquals('verified_explicit', $f1['properties']['flag_status']);
        $this->assertTrue($f1['properties']['flag_verified']);

        // 2. Conflict detected (CN explicit vs AU MID 503)
        $f3 = $featuresByMmsi->get('503999999');
        $this->assertEquals('conflict_detected', $f3['properties']['flag_status']);
        $this->assertFalse($f3['properties']['flag_verified']);

        // 3. MID administrative indicator only (null explicit)
        $f4 = $featuresByMmsi->get('525999888');
        $this->assertEquals('mid_administrative_indicator', $f4['properties']['flag_status']);
        $this->assertFalse($f4['properties']['flag_verified']);

        // 4. Unknown
        $f5 = $featuresByMmsi->get('000000000');
        $this->assertEquals('unknown', $f5['properties']['flag_status']);
        $this->assertFalse($f5['properties']['flag_verified']);

        // C. Filter flag=UNKNOWN matches only unverified / missing flag vessels and DOES NOT return valid explicit flag vessels
        $resUnknown = $this->getJson('/api/layers/vessels?flag=UNKNOWN');
        $resUnknown->assertOk();
        $unknownNames = collect($resUnknown->json('features'))->pluck('properties.name')->all();
        $this->assertContains('GHOST VESSEL', $unknownNames);
        $this->assertNotContains('KM BERKAH 01', $unknownNames);
        $this->assertNotContains('SYDNEY EXPRESS', $unknownNames);
        $this->assertNotContains('PACIFIC CONFLICT', $unknownNames);
    }
}

