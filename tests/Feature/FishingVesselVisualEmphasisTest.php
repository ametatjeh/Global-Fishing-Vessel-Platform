<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FishingVesselVisualEmphasisTest extends TestCase
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
     * Test that the map page renders with Electric Cyan (#00E5FF) for Fishing in the scientific legend.
     */
    public function test_map_page_renders_electric_cyan_for_fishing_in_legend(): void
    {
        $response = $this->actingAs($this->analyst)->get('/map');

        $response->assertOk();
        $response->assertSee('bg-[#00E5FF]', false);
        $response->assertSee('text-[#00E5FF]', false);
        $response->assertSee('Fishing');
        $response->assertSee('(Perikanan)');
    }

    /**
     * Test that global-map.js implements Electric Cyan (#00E5FF) and dark outline contrast for Fishing.
     */
    public function test_global_map_script_implements_fishing_visual_emphasis(): void
    {
        $scriptPath = resource_path('js/map/global-map.js');
        $this->assertFileExists($scriptPath);
        $content = file_get_contents($scriptPath);

        // 1. Electric Cyan (#00E5FF) used for fishing category
        $this->assertStringContainsString("id: 'fishing', fill: '#00E5FF'", $content);

        // 2. Dark outline contrast implemented for fishing
        $this->assertStringContainsString("#00262e", $content);
        $this->assertStringContainsString("#00333d", $content);

        // 3. Neutral circle layer uses #00E5FF for fishing
        $this->assertStringContainsString("'fishing', '#00E5FF'", $content);

        // 4. Prominent scaling for fishing vessels on oriented and circle layers
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 0.60, 0.50", $content);
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 4.5, 3.5", $content);

        // 5. Selected vessel highlight ring has glowing cyan ring for fishing
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 'rgba(0, 229, 255, 0.30)'", $content);
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], '#00E5FF'", $content);
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 20", $content);

        // 6. Hover tooltip logic is registered for vessel layers
        $this->assertStringContainsString("showVesselHoverTooltip", $content);
        $this->assertStringContainsString("hideVesselHoverTooltip", $content);
        $this->assertStringContainsString("vessel-hover-tooltip", $content);

        // 7. Other vessel categories remain distinct
        $this->assertStringContainsString("id: 'passenger', fill: '#0284c7'", $content);
        $this->assertStringContainsString("id: 'patrol', fill: '#f43f5e'", $content);
        $this->assertStringContainsString("id: 'military', fill: '#ef4444'", $content);
        $this->assertStringContainsString("id: 'special', fill: '#f59e0b'", $content);
        $this->assertStringContainsString("id: 'unknown', fill: '#94a3b8'", $content);
    }
}

