<?php

namespace Tests\Feature;

use App\Models\EezZone;
use App\Models\FaoFishingArea;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapLayerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_layers_endpoint_returns_layer_catalog_and_metadata(): void
    {
        $response = $this->getJson('/api/layers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'layers' => [
                    '*' => [
                        'id',
                        'name',
                        'type',
                        'color',
                        'provider_status',
                        'description',
                    ]
                ]
            ]);

        $layers = collect($response->json('layers'));
        $this->assertNotNull($layers->firstWhere('id', 'layer-live-vessels'));
        $this->assertNotNull($layers->firstWhere('id', 'layer-fao-areas'));
        $this->assertNotNull($layers->firstWhere('id', 'layer-eez-boundaries'));
        $this->assertNotNull($layers->firstWhere('id', 'layer-eez-zones'));
        $this->assertNotNull($layers->firstWhere('id', 'layer-vessel-encounters'));
        $this->assertNotNull($layers->firstWhere('id', 'layer-dark-vessels'));
    }

    public function test_fao_layer_returns_geojson_feature_collection(): void
    {
        FaoFishingArea::create([
            'area_code' => '57',
            'name_en' => 'Indian Ocean, Eastern',
            'ocean_basin' => 'Indian Ocean',
            'area_type' => 'major',
            'surface_water_sqkm' => 29840000.0,
            'version' => '2024.1',
            'source_dataset_url' => 'https://www.fao.org/fishery/docs/maps/Default.htm',
            'license_name' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
            'import_date' => now(),
            'geometry_geojson' => json_encode([
                'type' => 'MultiPolygon',
                'coordinates' => [[[[77.0, -10.0], [105.0, -10.0], [105.0, 15.0], [77.0, 15.0], [77.0, -10.0]]]],
            ]),
            'bbox' => [77.0, -10.0, 105.0, 15.0],
        ]);

        $response = $this->getJson('/api/layers/fao?bbox=70,-15,110,20');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'metadata' => ['count', 'dataset', 'license'],
                'features',
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('57', $response->json('features.0.properties.area_code'));
        $this->assertEquals('Indian Ocean, Eastern', $response->json('features.0.properties.name_en'));
    }

    public function test_eez_layer_returns_geojson_with_provenance_data(): void
    {
        EezZone::create([
            'mrgid' => 8480,
            'geoname' => 'Indonesian Exclusive Economic Zone',
            'sovereign_state' => 'Indonesia',
            'sovereign_iso3' => 'IDN',
            'pol_type' => '200NM',
            'area_sqkm' => 6159032.0,
            'version' => 'v12',
            'source_name' => 'Flanders Marine Institute (VLIZ) Marine Regions',
            'source_url' => 'https://www.marineregions.org/downloads.php',
            'license_name' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
            'attribution_notice' => 'Maritime Boundaries Geodatabase: Maritime Boundaries and Exclusive Economic Zone (200NM), version 12.',
            'import_date' => now(),
            'geometry_geojson' => json_encode([
                'type' => 'MultiPolygon',
                'coordinates' => [[[[95.0, -5.0], [141.0, -5.0], [141.0, 6.0], [95.0, 6.0], [95.0, -5.0]]]],
            ]),
            'bbox' => [95.0, -5.0, 141.0, 6.0],
            'crosses_antimeridian' => false,
        ]);

        $response = $this->getJson('/api/layers/eez?bbox=90,-10,145,10');

        $response->assertStatus(200);
        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('Indonesian Exclusive Economic Zone', $response->json('features.0.properties.geoname'));
        $this->assertEquals('Indonesia', $response->json('features.0.properties.sovereign_state'));
    }

    public function test_eez_boundaries_layer_returns_geojson_line_collection(): void
    {
        \App\Models\EezBoundary::create([
            'line_id' => 3201,
            'line_name' => 'Indonesia - India Maritime Boundary (Treaty 1974)',
            'line_type' => 'Treaty',
            'sovereign1' => 'Indonesia',
            'sovereign2' => 'India',
            'length_km' => 89.5,
            'geometry_geojson' => [
                'type' => 'MultiLineString',
                'coordinates' => [[[95.0, 5.0], [96.0, 6.0]]],
            ],
        ]);

        $response = $this->getJson('/api/layers/eez-boundaries?bbox=90,0,100,10');

        $response->assertStatus(200);
        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('Indonesia - India Maritime Boundary (Treaty 1974)', $response->json('features.0.properties.line_name'));
        $this->assertEquals('Treaty', $response->json('features.0.properties.line_type'));
    }

    public function test_fma_layer_returns_geojson_feature_collection(): void
    {
        \App\Models\FisheriesManagementArea::create([
            'rfb_code' => 'WCPFC',
            'name' => 'Western and Central Pacific Fisheries Commission',
            'mandate_type' => 'RFMO',
            'ocean_basin' => 'Pacific Ocean',
            'competence_summary' => 'Highly migratory fish stocks',
            'version' => '2024.1',
            'source_dataset_url' => 'https://www.fao.org/fishery/geoserver/fifao/ows',
            'license_name' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
            'import_date' => now(),
            'geometry_geojson' => json_encode([
                'type' => 'MultiPolygon',
                'coordinates' => [[[[120.0, -10.0], [180.0, -10.0], [180.0, 20.0], [120.0, 20.0], [120.0, -10.0]]]],
            ]),
            'bbox' => [120.0, -10.0, 180.0, 20.0],
            'crosses_antimeridian' => false,
        ]);

        $response = $this->getJson('/api/layers/fma?bbox=110,-15,185,25');

        $response->assertStatus(200);
        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('WCPFC', $response->json('features.0.properties.rfb_code'));
        $this->assertEquals('RFMO', $response->json('features.0.properties.mandate_type'));
    }

    public function test_encounters_layer_returns_zero_when_no_encounters_detected(): void
    {
        $response = $this->getJson('/api/layers/encounters');

        $response->assertStatus(200);
        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertEquals(0, $response->json('metadata.count'));
        $this->assertEmpty($response->json('features'));
    }

    public function test_ais_telemetry_endpoint_validates_and_stores_position(): void
    {
        $payload = [
            'mmsi' => '525112233',
            'latitude' => -5.4321,
            'longitude' => 105.1234,
            'speed_over_ground' => 8.5,
            'course_over_ground' => 145.0,
            'vessel_name' => 'PACIFIC VOYAGER',
            'ship_type' => 'Trawler',
            'flag' => 'IDN',
            'timestamp' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/telemetry/ais', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Posisi AIS berhasil di-ingest.',
            ]);

        $this->assertDatabaseHas('vessels', [
            'name' => 'PACIFIC VOYAGER',
            'flag_state' => 'IDN',
        ]);

        $this->assertDatabaseHas('vessel_positions', [
            'latitude' => -5.4321,
            'longitude' => 105.1234,
        ]);
    }

    public function test_ais_telemetry_endpoint_rejects_invalid_coordinates(): void
    {
        $payload = [
            'mmsi' => '525112233',
            'latitude' => 195.0, // Invalid latitude > 90
            'longitude' => 105.1234,
        ];

        $response = $this->postJson('/api/telemetry/ais', $payload);

        $response->assertStatus(422);
    }

    public function test_ais_telemetry_normalizes_identifiers_and_prevents_duplicate_positions(): void
    {
        $payload = [
            'mmsi' => 'MMSI-525998877',
            'latitude' => -6.1234,
            'longitude' => 106.8765,
            'speed_over_ground' => 7.2,
            'vessel_name' => 'KM SAMUDRA JAYA',
            'timestamp' => '2026-10-19T10:00:00Z',
        ];

        // First ingestion
        $response1 = $this->postJson('/api/telemetry/ais', $payload);
        $response1->assertStatus(200);
        $pos1Id = $response1->json('position_id');

        // Verify MMSI was normalized to digits only
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525998877',
            'name' => 'KM SAMUDRA JAYA',
        ]);

        // Duplicate ingestion with exact same time and coordinates
        $response2 = $this->postJson('/api/telemetry/ais', $payload);
        $response2->assertStatus(200);
        $pos2Id = $response2->json('position_id');

        // Should return the existing position, not create a duplicate
        $this->assertEquals($pos1Id, $pos2Id);
        $this->assertEquals(1, \App\Models\VesselPosition::where('latitude', -6.1234)->where('longitude', 106.8765)->count());
    }

    public function test_ais_telemetry_guards_against_out_of_order_last_seen_regression(): void
    {
        $mmsi = '525443322';

        // 1. Ingest newer position (12:00)
        $this->postJson('/api/telemetry/ais', [
            'mmsi' => $mmsi,
            'latitude' => -7.1000,
            'longitude' => 110.2000,
            'timestamp' => '2026-10-19T12:00:00Z',
        ])->assertStatus(200);

        $vessel = \App\Models\Vessel::where('mmsi', $mmsi)->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('2026-10-19 12:00:00', $vessel->last_seen_at->format('Y-m-d H:i:s'));

        // 2. Ingest older/delayed historical position (09:00)
        $this->postJson('/api/telemetry/ais', [
            'mmsi' => $mmsi,
            'latitude' => -7.5000,
            'longitude' => 110.1000,
            'timestamp' => '2026-10-19T09:00:00Z',
        ])->assertStatus(200);

        // Vessel last_seen_at must NOT be regressed to 09:00
        $vessel->refresh();
        $this->assertEquals('2026-10-19 12:00:00', $vessel->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_vessels_layer_returns_live_vessels_geojson_with_bbox_filter(): void
    {
        $vessel1 = Vessel::create([
            'name' => 'KM LAUT INDONESIA',
            'mmsi' => '525999001',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel1->id,
            'latitude' => -5.5000,
            'longitude' => 105.2000,
            'speed_knots' => 9.5,
            'course_degrees' => 120.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        $vessel2 = Vessel::create([
            'name' => 'NORTH SEA VOYAGER',
            'mmsi' => '235999002',
            'flag_state' => 'GB',
            'vessel_type' => 'cargo',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel2->id,
            'latitude' => 55.5000,
            'longitude' => 3.2000,
            'speed_knots' => 12.0,
            'course_degrees' => 180.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // Request inside Indonesian bounding box
        $resInside = $this->getJson('/api/layers/vessels?bbox=90,-10,140,10');
        $resInside->assertStatus(200);
        $this->assertEquals('FeatureCollection', $resInside->json('type'));
        $this->assertCount(1, $resInside->json('features'));
        $this->assertEquals('KM LAUT INDONESIA', $resInside->json('features.0.properties.name'));
        $this->assertEquals('525999001', $resInside->json('features.0.properties.mmsi'));

        // Request global / no bbox returns both
        $resAll = $this->getJson('/api/layers/vessels');
        $resAll->assertStatus(200);
        $this->assertCount(2, $resAll->json('features'));
    }

    public function test_vessels_layer_heading_and_cog_orientation_priority(): void
    {
        // 1. Vessel with valid heading (45°) and COG (55°) -> Priority: Heading
        $vessel1 = Vessel::create(['name' => 'VESSEL HEADING', 'mmsi' => '525000001']);
        VesselPosition::create([
            'vessel_id' => $vessel1->id,
            'latitude' => -6.0,
            'longitude' => 106.0,
            'heading_degrees' => 45.0,
            'course_degrees' => 55.0,
            'speed_knots' => 10.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // 2. Vessel with null heading and valid COG (135°) -> Priority: COG fallback
        $vessel2 = Vessel::create(['name' => 'VESSEL COG', 'mmsi' => '525000002']);
        VesselPosition::create([
            'vessel_id' => $vessel2->id,
            'latitude' => -7.0,
            'longitude' => 107.0,
            'heading_degrees' => null,
            'course_degrees' => 135.0,
            'speed_knots' => 8.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // 3. Vessel with 511 NMEA not-available heading and valid COG (220°) -> Fallback: COG
        $vessel3 = Vessel::create(['name' => 'VESSEL 511 NMEA', 'mmsi' => '525000003']);
        VesselPosition::create([
            'vessel_id' => $vessel3->id,
            'latitude' => -8.0,
            'longitude' => 108.0,
            'heading_degrees' => 511.0,
            'course_degrees' => 220.0,
            'speed_knots' => 6.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // 4. Vessel with null heading and null COG -> Neutral orientation
        $vessel4 = Vessel::create(['name' => 'VESSEL NEUTRAL', 'mmsi' => '525000004']);
        VesselPosition::create([
            'vessel_id' => $vessel4->id,
            'latitude' => -9.0,
            'longitude' => 109.0,
            'heading_degrees' => null,
            'course_degrees' => null,
            'speed_knots' => 0.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // 5. Vessel with out-of-range coordinates -> Rejected from FeatureCollection
        $vessel5 = Vessel::create(['name' => 'VESSEL INVALID COORDS', 'mmsi' => '525000005']);
        VesselPosition::create([
            'vessel_id' => $vessel5->id,
            'latitude' => 150.0, // Invalid latitude (> 90)
            'longitude' => 300.0, // Invalid longitude (> 180)
            'heading_degrees' => 90.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        $response = $this->getJson('/api/layers/vessels');
        $response->assertStatus(200);

        $features = collect($response->json('features'))->keyBy('properties.mmsi');

        // Check 1: Heading priority
        $this->assertTrue($features->has('525000001'));
        $f1 = $features->get('525000001');
        $this->assertEquals('heading', $f1['properties']['orientation_source']);
        $this->assertEquals('heading', $f1['properties']['orientation_type']);
        $this->assertEquals(45.0, $f1['properties']['orientation_degrees']);
        $this->assertTrue($f1['properties']['has_orientation']);

        // Check 2: COG fallback
        $this->assertTrue($features->has('525000002'));
        $f2 = $features->get('525000002');
        $this->assertEquals('cog', $f2['properties']['orientation_source']);
        $this->assertEquals('cog', $f2['properties']['orientation_type']);
        $this->assertEquals(135.0, $f2['properties']['orientation_degrees']);
        $this->assertTrue($f2['properties']['has_orientation']);

        // Check 3: NMEA 511 ignored and fallback to COG
        $this->assertTrue($features->has('525000003'));
        $f3 = $features->get('525000003');
        $this->assertEquals('cog', $f3['properties']['orientation_source']);
        $this->assertEquals('cog', $f3['properties']['orientation_type']);
        $this->assertEquals(220.0, $f3['properties']['orientation_degrees']);
        $this->assertNull($f3['properties']['heading_degrees']);

        // Check 4: Neutral / unavailable when neither heading nor COG available
        $this->assertTrue($features->has('525000004'));
        $f4 = $features->get('525000004');
        $this->assertEquals('unavailable', $f4['properties']['orientation_source']);
        $this->assertEquals('neutral', $f4['properties']['orientation_type']);
        $this->assertFalse($f4['properties']['has_orientation']);

        // Check 5: Invalid coordinates vessel is excluded
        $this->assertFalse($features->has('525000005'));
    }

    public function test_vessels_cardinal_angles_and_nmea_sentinel_handling(): void
    {
        $cardinals = [
            ['mmsi' => '525000010', 'heading' => 0.0, 'cog' => null, 'expected_deg' => 0.0, 'expected_src' => 'heading'],
            ['mmsi' => '525000011', 'heading' => 90.0, 'cog' => null, 'expected_deg' => 90.0, 'expected_src' => 'heading'],
            ['mmsi' => '525000012', 'heading' => 180.0, 'cog' => null, 'expected_deg' => 180.0, 'expected_src' => 'heading'],
            ['mmsi' => '525000013', 'heading' => 270.0, 'cog' => null, 'expected_deg' => 270.0, 'expected_src' => 'heading'],
            ['mmsi' => '525000014', 'heading' => null, 'cog' => 0.0, 'expected_deg' => 0.0, 'expected_src' => 'cog'],
            ['mmsi' => '525000015', 'heading' => null, 'cog' => 3600.0, 'expected_deg' => 0.0, 'expected_src' => 'unavailable'],
        ];

        foreach ($cardinals as $item) {
            $vessel = Vessel::create(['name' => 'VESSEL ' . $item['mmsi'], 'mmsi' => $item['mmsi']]);
            VesselPosition::create([
                'vessel_id' => $vessel->id,
                'latitude' => -5.0,
                'longitude' => 105.0,
                'heading_degrees' => $item['heading'],
                'course_degrees' => $item['cog'],
                'speed_knots' => 7.5,
                'observed_at' => now(),
                'received_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/layers/vessels');
        $response->assertStatus(200);

        $features = collect($response->json('features'))->keyBy('properties.mmsi');

        foreach ($cardinals as $item) {
            $this->assertTrue($features->has($item['mmsi']));
            $feat = $features->get($item['mmsi']);
            $this->assertEquals($item['expected_src'], $feat['properties']['orientation_source']);
            $this->assertEquals($item['expected_deg'], $feat['properties']['orientation_degrees']);
        }
    }
}
