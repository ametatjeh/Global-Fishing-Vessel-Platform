<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeprecatedAboutPageAndSingleLineFooterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);
    }

    public function test_about_page_returns_404_for_guest(): void
    {
        $response = $this->get('/about');
        $response->assertStatus(404);
    }

    public function test_about_page_returns_404_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->get('/about');
        $response->assertStatus(404);
    }

    public function test_layout_does_not_contain_internal_links_to_about(): void
    {
        $response = $this->actingAs($this->user)->get('/dashboard');
        $response->assertStatus(200);

        $response->assertDontSee('href="/about"', false);
        $response->assertDontSee("href='http://localhost/about'", false);
        $response->assertDontSee("href='https://localhost/about'", false);
        $response->assertDontSee('data-tooltip="About & Attributions"', false);
        $response->assertDontSee('About &amp; Attributions', false);
    }

    public function test_single_line_compact_footer_renders_on_map_page(): void
    {
        $response = $this->actingAs($this->user)->get('/map');
        $response->assertStatus(200);

        // Required text elements
        $response->assertSee('GFVIP', false);
        $response->assertSee('Global Fishing Vessel Monitoring Platform', false);
        $response->assertSee('Research Platform', false);
        $response->assertSee('2026', false);

        // Must NOT render old footer links or long disclaimers
        $response->assertDontSee('/about', false);
        $response->assertDontSee('Data &amp; Legal', false);
        $response->assertDontSee('Global Fishing Watch Data Attribution:', false);
        $response->assertDontSee('Pemberitahuan Estimasi Intelijen Maritim:', false);
    }

    public function test_single_line_compact_footer_has_compact_height_classes(): void
    {
        $response = $this->actingAs($this->user)->get('/map');
        $response->assertStatus(200);

        $response->assertSee('h-9 min-h-[36px]', false);
        $response->assertSee('flex items-center justify-between', false);
    }
}

