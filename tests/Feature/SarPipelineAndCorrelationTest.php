<?php

namespace Tests\Feature;

use App\Models\SatelliteScene;
use App\Models\SatelliteDetection;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Sar\SarAisCorrelationService;
use App\Services\Sar\SarProcessingPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SarPipelineAndCorrelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sar_pipeline_readiness_evaluates_all_10_stages(): void
    {
        $service = app(SarProcessingPipelineService::class);
        $audit = $service->auditPipelineReadiness();

        $this->assertEquals('Sentinel-1 C-SAR Vessel Detection Pipeline', $audit['pipeline']);
        $this->assertCount(10, $audit['stages']);

        $stageNames = array_column($audit['stages'], 'name');
        $this->assertContains('OAuth2 Authentication', $stageNames);
        $this->assertContains('SAR Image Preprocessing', $stageNames);
        $this->assertContains('CFAR Candidate Vessel Detection', $stageNames);
        $this->assertContains('AIS-SAR Correlation Engine', $stageNames);
        $this->assertContains('Analyst Review & Provenance', $stageNames);
    }

    public function test_ais_sar_correlation_matches_close_vessel(): void
    {
        $vessel = Vessel::create([
            'mmsi' => '999888777',
            'name' => 'CORRELATED SHIP',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $observedAt = now()->subMinutes(10);

        // Vessel position at (-5.000, 106.000)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.000,
            'longitude' => 106.000,
            'observed_at' => $observedAt,
            'received_at' => $observedAt,
        ]);

        $scene = SatelliteScene::first() ?? SatelliteScene::create([
            'scene_id' => 'test-scene-uuid-mock',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'C-SAR',
            'sensor_mode' => 'IW',
            'polarization' => 'VV+VH',
            'acquisition_start' => now()->subMinutes(15),
            'acquisition_end' => now()->subMinutes(5),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'processing_status' => 'cataloged',
        ]);

        // Candidate detection 300 meters away (-5.002, 106.001)
        $detection = SatelliteDetection::create([
            'satellite_scene_id' => $scene->id,
            'detection_identifier' => 'DET-TEST-001',
            'latitude' => -5.002,
            'longitude' => 106.001,
            'detected_at' => $observedAt,
            'confidence_score' => 0.88,
            'correlation_status' => 'candidate',
        ]);

        $service = app(SarAisCorrelationService::class);
        $result = $service->correlateDetection($detection, 1500.0, 30);

        $this->assertEquals('matched', $result['status']);
        $this->assertEquals($vessel->id, $result['vessel_id']);

        $detection->refresh();
        $this->assertEquals('matched', $detection->correlation_status);
        $this->assertEquals($vessel->id, $detection->correlated_vessel_id);
        $this->assertLessThan(1500, (float) $detection->distance_to_nearest_ais_meters);
    }

    public function test_ais_sar_correlation_marks_insufficient_data_when_no_regional_ais(): void
    {
        $scene = SatelliteScene::first() ?? SatelliteScene::create([
            'scene_id' => 'test-scene-uuid-mock-2',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'C-SAR',
            'sensor_mode' => 'IW',
            'polarization' => 'VV+VH',
            'acquisition_start' => now()->subMinutes(15),
            'acquisition_end' => now()->subMinutes(5),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'processing_status' => 'cataloged',
        ]);

        // Remote open-ocean candidate where no AIS tracks exist at all (-45.0, 80.0)
        $detection = SatelliteDetection::create([
            'satellite_scene_id' => $scene->id,
            'detection_identifier' => 'DET-REMOTE-002',
            'latitude' => -45.0,
            'longitude' => 80.0,
            'detected_at' => now()->subHours(2),
            'confidence_score' => 0.75,
            'correlation_status' => 'candidate',
        ]);

        $service = app(SarAisCorrelationService::class);
        $result = $service->correlateDetection($detection, 1500.0, 30);

        $this->assertEquals('insufficient_data', $result['status']);

        $detection->refresh();
        $this->assertEquals('insufficient_data', $detection->correlation_status);
        $this->assertStringContainsString('tidak memadai', $detection->analyst_notes);
    }
}
