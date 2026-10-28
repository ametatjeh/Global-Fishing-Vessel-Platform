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
        $response->assertSee('ESRI Dark Gray');
        $response->assertSee('btnFitVessels');
        $response->assertSee('btnFitSarCandidates');
        $response->assertSee('btnFitSarScene');
        $response->assertSee('btnResetView');
        $response->assertSee('toggle-layer-vessels');
        $response->assertSee('toggle-layer-dark-vessels');
        $response->assertSee('toggle-layer-sar-scenes');
    }

    public function test_tracking_page_renders_dedicated_maplibre_canvas_and_telemetry(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('tracking.index'));

        $response->assertOk();
        $response->assertSee('id="tracking-map-canvas"', false);
        $response->assertSee('Vessel Telemetry Inspector');
        $response->assertSee('btnFitAllVessels');
        $response->assertSee('Daftar Posisi Broadcast Terkini');
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
        $response = $this->actingAs($this->analyst)->getJson(route('api.sar.candidates.track', [
            'id' => $this->detection->id,
            'window' => 60,
        ]));

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertArrayHasKey('features', $data);
        $this->assertEquals(60, $data['properties']['window_minutes']);
        $this->assertArrayHasKey('position_count', $data['properties']);
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
