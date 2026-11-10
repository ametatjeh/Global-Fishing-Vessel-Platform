<?php

namespace Tests\Feature;

use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapLibreFullVisualizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;
    protected SatelliteScene $scene;
    protected SatelliteDetection $detection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Radar Correlation Analyst',
        ]);

        $this->scene = SatelliteScene::create([
            'scene_id' => '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7',
            'platform_name' => 'Sentinel-1C',
            'instrument' => 'SAR-C',
            'sensor_mode' => 'EW',
            'polarization' => 'HH',
            'acquisition_start' => '2026-10-19 13:52:12',
            'acquisition_end' => '2026-10-19 13:52:36',
            'footprint_geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[80.0, -58.0], [87.0, -58.0], [87.0, -60.0], [80.0, -60.0], [80.0, -58.0]]]
            ]),
            'processing_status' => 'detection_completed',
        ]);

        $this->detection = SatelliteDetection::create([
            'satellite_scene_id' => $this->scene->id,
            'detection_identifier' => '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-001',
            'latitude' => -59.5432,
            'longitude' => 84.1234,
            'detected_at' => '2026-10-19 13:52:24',
            'estimated_length_meters' => 65.0,
            'estimated_width_meters' => 18.0,
            'confidence_score' => 0.95,
            'correlation_status' => 'insufficient_data',
            'review_status' => SatelliteDetection::REVIEW_PENDING,
            'tcr_db' => 15.8,
        ]);
    }

    public function test_maplibre_worker_asset_exists_in_build_manifest(): void
    {
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath, 'Vite manifest.json must exist in public/build.');

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);

        // Find MapLibre worker in manifest
        $workerEntry = null;
        foreach ($manifest as $key => $entry) {
            if (str_contains($key, 'maplibre-gl-worker')) {
                $workerEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($workerEntry, 'MapLibre worker entry must be registered in manifest.json.');
        $workerFilePath = public_path('build/' . $workerEntry['file']);
        $this->assertFileExists($workerFilePath, 'MapLibre worker file must physically exist in public/build/assets.');
        $this->assertGreaterThan(100000, filesize($workerFilePath), 'Worker file must be a non-empty compiled binary/script.');
    }

    public function test_global_map_page_renders_with_canvas_and_controls(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee('id="global-map-canvas"', false);
        $response->assertDontSee('ESRI Dark Gray');
        $response->assertDontSee('btnFitVessels');
        $response->assertDontSee('btnFitSarCandidates');
        $response->assertDontSee('btnFitSarScene');
        $response->assertSee('btnResetView');
        $response->assertSee('toggle-layer-asean-eez');
        $response->assertSee('ASEAN Exclusive Economic Zones (EEZ)');
        $response->assertSee('toggle-layer-vessels');
        $response->assertSee('toggle-layer-dark-vessels');
        $response->assertSee('toggle-layer-sar-scenes');
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'Direction unavailable:')
        );
        $this->assertTrue(
            str_contains($content, 'Selected vessel:')
        );
        $response->assertDontSee('id="activeTrackBanner"', false);
        $response->assertDontSee('id="btnFitActiveTrack"', false);
        $response->assertDontSee('id="btnToggleActiveTrack"', false);
        $response->assertDontSee('id="btnClearActiveTrack"', false);
        $response->assertDontSee('Cluster Padat');
        $response->assertDontSee('Cluster Kecil');
    }

    public function test_tracking_page_renders_dedicated_maplibre_canvas_and_telemetry(): void
    {
        // Consolidated Live Vessel Tracking hub in Global Map
        $response = $this->actingAs($this->analyst)->get(route('map.index'));
        $response->assertOk();
        $response->assertSee('id="global-map-canvas"', false);
        $response->assertDontSee('id="activeTrackBanner"', false);
        $response->assertSee('btnToggleAdvancedFilters');
        $response->assertSee('btnToggleLayers');

        // /tracking route is cleanly removed and returns 404
        $this->actingAs($this->analyst)->get('/tracking')->assertStatus(404);
    }

    public function test_candidate_detail_page_renders_maplibre_spatial_inspector(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('dark-vessels.show', $this->detection->id));

        $response->assertOk();
        $response->assertSee('id="candidate-detail-map"', false);
        $response->assertSee('candidateLng');
        $response->assertSee('candidateLat');
        $response->assertSee('loadAisTrack');
        $response->assertSee('select-track-window');
        $response->assertSee('toggle-ais-track');
    }

    public function test_sar_scenes_layer_api_returns_valid_geojson(): void
    {
        $response = $this->actingAs($this->analyst)->getJson('/api/layers/sar-scenes');

        $response->assertOk();
        $response->assertJsonStructure([
            'type',
            'features' => [
                '*' => [
                    'type',
                    'id',
                    'geometry' => [
                        'type',
                        'coordinates',
                    ],
                    'properties' => [
                        'scene_id',
                        'platform_name',
                    ],
                ],
            ],
        ]);

        $data = $response->json();
        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertNotEmpty($data['features']);

        // Check geometry validity
        foreach ($data['features'] as $feature) {
            $this->assertEquals('Polygon', $feature['geometry']['type']);
            $coords = $feature['geometry']['coordinates'][0];
            $this->assertGreaterThanOrEqual(4, count($coords));
            foreach ($coords as $point) {
                $this->assertCount(2, $point);
                $this->assertTrue(is_numeric($point[0]) && is_finite($point[0]));
                $this->assertTrue(is_numeric($point[1]) && is_finite($point[1]));
            }
        }
    }

    public function test_sar_candidates_layer_api_returns_candidates_with_review_status(): void
    {
        $response = $this->actingAs($this->analyst)->getJson('/api/layers/dark-vessels');

        $response->assertOk();
        $response->assertJsonStructure([
            'type',
            'features' => [
                '*' => [
                    'type',
                    'id',
                    'geometry' => [
                        'type',
                        'coordinates',
                    ],
                    'properties' => [
                        'id',
                        'identifier',
                        'confidence_score',
                        'correlation_status',
                        'review_status',
                        'latitude',
                        'longitude',
                    ],
                ],
            ],
        ]);

        $data = $response->json();
        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertNotEmpty($data['features']);

        foreach ($data['features'] as $feature) {
            $this->assertEquals('Point', $feature['geometry']['type']);
            $coords = $feature['geometry']['coordinates'];
            $this->assertCount(2, $coords);
            $this->assertNotNull($coords[0]);
            $this->assertNotNull($coords[1]);
            $this->assertTrue(is_finite($coords[0]) && $coords[0] >= -180 && $coords[0] <= 180);
            $this->assertTrue(is_finite($coords[1]) && $coords[1] >= -90 && $coords[1] <= 90);
            $this->assertNotEmpty($feature['properties']['review_status']);
        }
    }

    public function test_candidate_historical_track_api_returns_valid_linestring_and_points(): void
    {
        // 1. Candidate without AIS positions (Coverage gap scenario)
        $response = $this->actingAs($this->analyst)->getJson(route('api.sar.candidates.track', [
            'id' => $this->detection->id,
            'window' => 60,
        ]));

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertArrayHasKey('features', $data);
        $this->assertEquals(60, $data['properties']['window_minutes']);
        $this->assertEquals(0, $data['properties']['position_count']);
        $this->assertEquals('empty', $data['properties']['track_status']);

        // 2. Candidate with correlated vessel and historical track positions (>= 2 points)
        $vessel = Vessel::create([
            'mmsi' => '525999888',
            'name' => 'PACIFIC SURVEYOR',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $detTime = \Carbon\Carbon::parse($this->detection->detected_at);

        // Position 1: 20 mins before SAR (role: before_sar)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $this->detection->latitude - 0.05,
            'longitude' => $this->detection->longitude - 0.05,
            'speed_knots' => 10.2,
            'course_degrees' => 45.0,
            'heading_degrees' => 45.0,
            'observed_at' => $detTime->copy()->subMinutes(20),
            'received_at' => $detTime->copy()->subMinutes(20),
        ]);

        // Position 2: 2 mins after SAR, closest to candidate (role: nearest)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $this->detection->latitude + 0.005,
            'longitude' => $this->detection->longitude + 0.005,
            'speed_knots' => 10.0,
            'course_degrees' => 46.0,
            'heading_degrees' => 45.0,
            'observed_at' => $detTime->copy()->addMinutes(2),
            'received_at' => $detTime->copy()->addMinutes(2),
        ]);

        // Position 3: 25 mins after SAR (role: after_sar)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $this->detection->latitude + 0.06,
            'longitude' => $this->detection->longitude + 0.06,
            'speed_knots' => 9.8,
            'course_degrees' => 48.0,
            'heading_degrees' => 47.0,
            'observed_at' => $detTime->copy()->addMinutes(25),
            'received_at' => $detTime->copy()->addMinutes(25),
        ]);

        // Position 4: 90 mins after SAR (outside 60m window)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $this->detection->latitude + 0.20,
            'longitude' => $this->detection->longitude + 0.20,
            'speed_knots' => 10.0,
            'course_degrees' => 50.0,
            'observed_at' => $detTime->copy()->addMinutes(90),
            'received_at' => $detTime->copy()->addMinutes(90),
        ]);

        $trackResponse = $this->actingAs($this->analyst)->getJson(route('api.sar.candidates.track', [
            'id' => $this->detection->id,
            'window' => 60,
        ]));

        $trackResponse->assertOk();
        $trackData = $trackResponse->json();

        $this->assertEquals(3, $trackData['properties']['position_count']);
        $this->assertEquals('available', $trackData['properties']['track_status']);

        // Must have exactly 1 LineString feature
        $lineStrings = array_values(array_filter($trackData['features'], fn($f) => $f['geometry']['type'] === 'LineString'));
        $this->assertCount(1, $lineStrings);
        $this->assertEquals('ais_track_line', $lineStrings[0]['properties']['feature_type']);
        $this->assertCount(3, $lineStrings[0]['geometry']['coordinates']);

        // Check coordinate format [longitude, latitude]
        $firstCoord = $lineStrings[0]['geometry']['coordinates'][0];
        $this->assertCount(2, $firstCoord);
        $this->assertEquals(round($this->detection->longitude - 0.05, 4), round($firstCoord[0], 4)); // lng
        $this->assertEquals(round($this->detection->latitude - 0.05, 4), round($firstCoord[1], 4)); // lat

        // Observation point features
        $points = array_values(array_filter($trackData['features'], fn($f) => $f['geometry']['type'] === 'Point' && ($f['properties']['feature_type'] ?? '') === 'ais_position'));
        $this->assertCount(3, $points);

        $roles = array_column(array_column($points, 'properties'), 'role');
        $this->assertContains('before_sar', $roles);
        $this->assertContains('nearest', $roles);
        $this->assertContains('after_sar', $roles);

        // Window expansion (180 mins) includes position 4
        $expandedResponse = $this->actingAs($this->analyst)->getJson(route('api.sar.candidates.track', [
            'id' => $this->detection->id,
            'window' => 180,
        ]));
        $this->assertEquals(4, $expandedResponse->json('properties.position_count'));
    }

    public function test_invalid_coordinates_handled_gracefully(): void
    {
        // Malformed bbox query does not crash API
        $response = $this->actingAs($this->analyst)->getJson('/api/layers/vessels?bbox=invalid,coords,here,test');
        $response->assertOk();
        $this->assertEquals('FeatureCollection', $response->json('type'));

        // Out of bound coordinates rejected on telemetry
        $telemetryResponse = $this->postJson('/api/telemetry/ais', [
            'mmsi' => '999999999',
            'latitude' => 120.5, // Invalid > 90
            'longitude' => 45.0,
            'observed_at' => now()->toIso8601String(),
        ]);
        $telemetryResponse->assertStatus(422);
    }
}
