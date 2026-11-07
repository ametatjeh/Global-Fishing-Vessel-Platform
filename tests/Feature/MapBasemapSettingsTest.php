<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapBasemapSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Maritime Cartographer',
        ]);
    }

    public function test_map_page_renders_positron_as_default_basemap_option(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('map.index'));

        $response->assertOk();
        // Check Positron is default checked radio
        $response->assertSee('value="openfreemap-positron"', false);
        $response->assertSee('Positron High Contrast Clean', false);
        $response->assertSee('id="currentBasemapLabel"', false);

        // Check Dark Ocean Marine Style is available as openfreemap-dark
        $response->assertSee('value="openfreemap-dark"', false);
        $response->assertSee('Dark Ocean Marine Style', false);

        // Check Nautical Standard Vector and Topographic Bright Canvas
        $response->assertSee('value="openfreemap-liberty"', false);
        $response->assertSee('Nautical Standard Vector', false);
        $response->assertSee('value="openfreemap-bright"', false);
        $response->assertSee('Topographic Bright Canvas', false);
    }

    public function test_global_map_controller_implements_positron_default_and_vector_dark_ocean(): void
    {
        $scriptPath = resource_path('js/map/global-map.js');
        $this->assertFileExists($scriptPath);

        $content = file_get_contents($scriptPath);

        // Positron default
        $this->assertStringContainsString("'openfreemap-positron'", $content);
        $this->assertStringContainsString('https://tiles.openfreemap.org/styles/positron', $content);

        // Dark Ocean vector URL
        $this->assertStringContainsString("'openfreemap-dark'", $content);
        $this->assertStringContainsString('https://tiles.openfreemap.org/styles/dark', $content);

        // LocalStorage persistence key
        $this->assertStringContainsString("'gfvip_map_basemap'", $content);

        // Retry & resilient fallback logic
        $this->assertStringContainsString('scheduleBasemapRetryOrDegradation', $content);
        $this->assertStringContainsString('clearBasemapDegradationBanner', $content);
        $this->assertStringContainsString('restoreCachedLayerData', $content);
    }
}
