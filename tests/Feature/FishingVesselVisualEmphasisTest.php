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
     * Test that the map page renders with Amber/Orange (#F59E0B) for Fishing in the scientific legend.
     */
    public function test_map_page_renders_amber_orange_for_fishing_in_legend(): void
    {
        $response = $this->actingAs($this->analyst)->get('/map');

        $response->assertOk();
        $response->assertSee('text-[#F59E0B]', false);
        $response->assertSee('Fishing');
    }

    /**
     * Test that global-map.js implements Amber/Orange (#F59E0B) and dark outline contrast for Fishing.
     */
    public function test_global_map_script_implements_fishing_visual_emphasis(): void
    {
        $scriptPath = resource_path('js/map/global-map.js');
        $this->assertFileExists($scriptPath);
        $content = file_get_contents($scriptPath);

        // 1. Amber/Orange (#F59E0B) used for fishing category
        $this->assertStringContainsString("id: 'fishing', fill: '#F59E0B'", $content);

        // 2. High contrast outline implemented for fishing (#451a03, #FFFFFF)
        $this->assertStringContainsString("#451a03", $content);
        $this->assertStringContainsString("#FFFFFF", $content);

        // 3. Neutral circle layer uses #F59E0B for fishing
        $this->assertStringContainsString("'fishing', '#F59E0B'", $content);

        // 4. Prominent scaling for fishing vessels on oriented and circle layers
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 0.60, 0.50", $content);
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 4.5, 3.5", $content);

        // 5. Selected vessel highlight ring has glowing amber ring for fishing
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 'rgba(245, 158, 11, 0.30)'", $content);
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], '#F59E0B'", $content);
        $this->assertStringContainsString("['==', ['get', 'vessel_type'], 'fishing'], 20", $content);

        // 6. Hover tooltip logic is registered for vessel layers
        $this->assertStringContainsString("showVesselHoverTooltip", $content);
        $this->assertStringContainsString("hideVesselHoverTooltip", $content);
        $this->assertStringContainsString("vessel-hover-tooltip", $content);

        // 7. Other vessel categories remain distinct and untouched
        $this->assertStringContainsString("id: 'passenger', fill: '#06B6D4'", $content);
        $this->assertStringContainsString("id: 'patrol', fill: '#DC2626'", $content);
        $this->assertStringContainsString("id: 'military', fill: '#DC2626'", $content);
        $this->assertStringContainsString("id: 'unknown', fill: '#94A3B8'", $content);
    }
}

