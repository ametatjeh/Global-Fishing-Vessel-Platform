<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NonInteractiveMaritimeBoundariesAndEnglishLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);
    }

    /**
     * Test that /map page renders without "Zona ZEE ASEAN" and with maritime boundaries toggle.
     */
    public function test_map_page_renders_with_line_boundaries_and_without_zona_zee_asean(): void
    {
        $response = $this->actingAs($this->analyst)->get('/map');

        $response->assertStatus(200);

        // Ensure "Zona ZEE ASEAN" does not exist in the rendered HTML
        $response->assertDontSee('Zona ZEE ASEAN');

        // Ensure maritime boundary layers toggle and legends exist
        $response->assertSee('ASEAN Exclusive Economic Zones (EEZ)');
        $response->assertSee('toggle-layer-asean-eez');
        $response->assertSee('Garis Batas ZEE &amp; Batas Maritim Resmi ASEAN (v12)', false);
        $response->assertSee('Garis Batas Luar ZEE (200 NM)');
    }

    /**
     * Test that global-map.js disables polygon fill opacity and disables click events on boundaries.
     */
    public function test_global_map_script_disables_fill_and_boundary_interaction(): void
    {
        $scriptPath = resource_path('js/map/global-map.js');
        $this->assertFileExists($scriptPath);

        $content = file_get_contents($scriptPath);

        // 1. Polygon fill is set to fill-opacity: 0.0 (hidden fill)
        $this->assertStringContainsString("'fill-opacity': 0.0", $content);

        // 2. Line layers are preserved
        $this->assertStringContainsString('asean-eez-polygon-outline-layer', $content);
        $this->assertStringContainsString('asean-eez-line-agreed', $content);
        $this->assertStringContainsString('asean-eez-line-undelimited', $content);
        $this->assertStringContainsString('asean-eez-line-disputed', $content);
        $this->assertStringContainsString('asean-eez-line-joint', $content);
        $this->assertStringContainsString('asean-eez-line-unknown', $content);

        // 3. ASEAN EEZ layers are NOT bound to map click handlers
        $this->assertDoesNotMatchRegularExpression('/this\.map\.on\(\s*[\'"]click[\'"]\s*,\s*[\'"]asean-eez/i', $content);

        // 4. ASEAN EEZ layers are NOT in clickableLayers (no hover pointer)
        $this->assertDoesNotMatchRegularExpression('/clickableLayers\s*=\s*\[[^\]]*asean-eez/i', $content);

        // 5. Interactive operational layers retain their click listeners
        $this->assertStringContainsString("this.map.on('click', 'vessels-oriented-layer'", $content);
        $this->assertStringContainsString("this.map.on('click', 'vessels-circle-layer'", $content);
        $this->assertStringContainsString("this.map.on('click', 'dark-vessels-circle-layer'", $content);
        $this->assertStringContainsString("this.map.on('click', 'encounters-circle-layer'", $content);
        $this->assertStringContainsString("this.map.on('click', 'sar-scenes-fill-layer'", $content);
        $this->assertStringContainsString("this.map.on('click', 'vessel-track-points-layer'", $content);
    }

    /**
     * Test that English-only country label enforcement is implemented in global-map.js.
     */
    public function test_global_map_script_enforces_english_only_country_labels(): void
    {
        $scriptPath = resource_path('js/map/global-map.js');
        $content = file_get_contents($scriptPath);

        // 1. enforceEnglishCountryLabels method exists
        $this->assertStringContainsString('enforceEnglishCountryLabels()', $content);

        // 2. Uses English-first coalesce fallback expression
        $this->assertStringContainsString("['get', 'name:en']", $content);
        $this->assertStringContainsString("['get', 'name_en']", $content);
        $this->assertStringContainsString("['get', 'name:latin']", $content);
        $this->assertStringContainsString("['get', 'name']", $content);

        // 3. Targets country symbol layers while excluding cities, states, towns, and roads
        $this->assertStringContainsString("lowerId.includes('country')", $content);
        $this->assertStringContainsString("lowerId.includes('state')", $content);
        $this->assertStringContainsString("lowerId.includes('city')", $content);
        $this->assertStringContainsString("lowerId.includes('road')", $content);

        // 4. Triggered on style.load and load events
        $this->assertStringContainsString("this.enforceEnglishCountryLabels()", $content);
    }
}
