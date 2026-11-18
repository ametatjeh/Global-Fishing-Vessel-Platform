<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveMapVesselDisplayVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;
    protected DataProvider $aisProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);

        $this->aisProvider = DataProvider::firstOrCreate(
            ['slug' => 'ais-stream'],
            [
                'name' => 'AIS Live Stream Telemetry',
                'type' => 'ais_stream',
                'api_url' => 'wss://stream.aisstream.io/v0/stream',
                'is_enabled' => true,
            ]
        );

        $this->actingAs($this->analyst);
    }

    public function test_vessel_layer_api_returns_expected_geojson_structure_for_both_global_and_viewport_modes(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM LAUT INDAH',
            'mmsi' => '525999888',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $this->aisProvider->id,
            'latitude' => 2.5000,
            'longitude' => 105.0000,
            'speed_knots' => 7.5,
            'course_degrees' => 180.0,
            'heading_degrees' => 180,
            'nav_status' => 'under_way',
            'is_on_land' => false,
            'observed_at' => now()->subHours(10), // Stale (> 2h)
            'received_at' => now()->subHours(10),
        ]);

        // Global mode test
        $resGlobal = $this->getJson('/api/layers/vessels?mode=global');
        $resGlobal->assertOk();
        $this->assertEquals('global', $resGlobal->json('metadata.mode'));
        $featuresGlobal = $resGlobal->json('features');
        $this->assertCount(1, $featuresGlobal);
        $this->assertEquals('KM LAUT INDAH', $featuresGlobal[0]['properties']['name']);
        $this->assertEquals('fishing', $featuresGlobal[0]['properties']['vessel_type']);
        $this->assertFalse($featuresGlobal[0]['properties']['is_fresh']);
        $this->assertTrue($featuresGlobal[0]['properties']['has_orientation']);

        // Viewport mode test
        $resViewport = $this->getJson('/api/layers/vessels?mode=viewport&bbox=90.0000,-10.0000,140.0000,20.0000&zoom=3.50');
        $resViewport->assertOk();
        $this->assertEquals('viewport', $resViewport->json('metadata.mode'));
        $featuresViewport = $resViewport->json('features');
        $this->assertCount(1, $featuresViewport);
        $this->assertEquals([105.0000, 2.5000], $featuresViewport[0]['geometry']['coordinates']);
    }

    public function test_frontend_map_script_has_zero_zoom_gaps_for_unclustered_vessels(): void
    {
        $mapScriptPath = resource_path('js/map/global-map.js');
        $this->assertFileExists($mapScriptPath);

        $jsContent = file_get_contents($mapScriptPath);

        // 1. vessels-clusters must be present on vessels-source
        $this->assertStringContainsString("id: 'vessels-clusters'", $jsContent);
        $this->assertStringContainsString("source: 'vessels-source'", $jsContent);

        // 2. vessels-cluster-count must include Noto Sans font stack to prevent 404 font errors
        $this->assertStringContainsString("'text-font': ['Noto Sans Bold', 'Noto Sans Regular']", $jsContent);

        // 3. Unclustered vessel layers (vessels-oriented-layer and vessels-circle-layer)
        // must use vessels-source and must NOT have minzoom: 5.12, allowing unclustered vessels
        // to render across all zoom levels
        $this->assertStringContainsString("id: 'vessels-oriented-layer'", $jsContent);
        $this->assertStringContainsString("id: 'vessels-circle-layer'", $jsContent);

        // Verify that vessels-oriented-layer does NOT have minzoom restriction
        $orientedDefPattern = "/id:\s*'vessels-oriented-layer'[^}]+minzoom:\s*5\.12/";
        $this->assertDoesNotMatchRegularExpression($orientedDefPattern, $jsContent, 'vessels-oriented-layer must not be restricted by minzoom: 5.12');

        // Verify that vessels-circle-layer does NOT have minzoom restriction
        $circleDefPattern = "/id:\s*'vessels-circle-layer'[^}]+minzoom:\s*5\.12/";
        $this->assertDoesNotMatchRegularExpression($circleDefPattern, $jsContent, 'vessels-circle-layer must not be restricted by minzoom: 5.12');
    }
}
