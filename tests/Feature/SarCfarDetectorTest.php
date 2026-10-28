<?php

namespace Tests\Feature;

use App\Models\SatelliteScene;
use App\Services\Sar\SarCfarDetectorService;
use App\Services\Sar\SarProcessingPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SarCfarDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_cfar_detector_identifies_bright_targets_against_sea_clutter(): void
    {
        $cfar = app(SarCfarDetectorService::class);

        // Generate synthetic sea clutter matrix (60x60) with background mean ~10.0
        $matrix = [];
        for ($r = 0; $r < 60; $r++) {
            $matrix[$r] = [];
            for ($c = 0; $c < 60; $c++) {
                $matrix[$r][$c] = 10.0 + (sin($r) * 2.0); // background clutter
            }
        }

        // Insert a bright vessel target at (30, 30) spanning 3x2 pixels (~30m x 20m at 10m/px)
        $matrix[29][30] = 500.0;
        $matrix[30][30] = 600.0;
        $matrix[31][30] = 550.0;
        $matrix[30][31] = 450.0;

        $results = $cfar->detectCandidates($matrix, 3, 10, 1e-4, 10.0);

        $this->assertNotEmpty($results);
        $vessel = $results[0];
        $this->assertGreaterThan(15.0, $vessel['estimated_length_meters']);
        $this->assertLessThan(60.0, $vessel['estimated_length_meters']);
        $this->assertGreaterThan(0.70, $vessel['confidence_score']);
        $this->assertGreaterThan(10.0, $vessel['target_to_clutter_db']);
    }

    public function test_cfar_detector_returns_empty_on_calm_water_with_no_targets(): void
    {
        $cfar = app(SarCfarDetectorService::class);

        // Uniform background
        $matrix = [];
        for ($r = 0; $r < 40; $r++) {
            $matrix[$r] = array_fill(0, 40, 12.0);
        }

        $results = $cfar->detectCandidates($matrix, 3, 10, 1e-5, 10.0);
        $this->assertEmpty($results);
    }

    public function test_sar_process_command_executes_catalog_validation(): void
    {
        SatelliteScene::create([
            'scene_id' => 'scene-test-unit-001',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'C-SAR',
            'sensor_mode' => 'IW',
            'polarization' => 'VV+VH',
            'acquisition_start' => now()->subHours(2),
            'acquisition_end' => now()->subHours(1),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'processing_status' => 'cataloged',
        ]);

        $this->artisan('sar:process scene-test-unit-001')
            ->expectsOutputToContain('Target Scene ID : scene-test-unit-001')
            ->assertExitCode(0);
    }

    public function test_sar_audit_pipeline_command_renders_table(): void
    {
        $this->artisan('sar:audit-pipeline')
            ->expectsOutputToContain('SENTINEL-1 SAR PIPELINE READINESS AUDIT')
            ->assertExitCode(0);
    }
}
