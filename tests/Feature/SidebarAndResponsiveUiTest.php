<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarAndResponsiveUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);
    }

    /**
     * Test that master layout renders desktop collapse, mobile hamburger, overlay, and tooltips.
     */
    public function test_master_layout_renders_sidebar_collapsible_and_mobile_drawer_elements(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('dashboard'));

        $response->assertStatus(200);

        // Desktop and mobile sidebar controls
        $response->assertSee('id="app-sidebar"', false);
        $response->assertSee('id="sidebar-overlay"', false);
        $response->assertSee('id="sidebar-desktop-toggle"', false);
        $response->assertSee('id="sidebar-mobile-toggle"', false);
        $response->assertSee('id="sidebar-mobile-close"', false);
        $response->assertSee('id="sidebar-floating-tooltip"', false);
        $response->assertSee('id="main-content"', false);

        // Zero-FOUC initialization script
        $response->assertSee('gfvip_sidebar_minimized', false);
        $response->assertSee('sidebar-collapsed', false);

        // Check tooltip attributes on core navigation links
        $response->assertSee('data-tooltip="Overview Dashboard"', false);
        $response->assertSee('data-tooltip="Global Vessel Map"', false);
        $response->assertDontSee('data-tooltip="Live Vessel Tracking"', false);
        $response->assertSee('data-tooltip="Vessel Registry"', false);
        $response->assertDontSee('data-tooltip="Fishing Grounds (FAO/EEZ)"', false);
        $response->assertSee('data-tooltip="Vessel Encounters"', false);
        $response->assertSee('data-tooltip="Analyst Review (SAR)"', false);
        $response->assertSee('data-tooltip="Fishing Compliance"', false);
        $response->assertSee('data-tooltip="Investigations Dossier"', false);
        $response->assertSee('data-tooltip="Data Sources & Providers"', false);
        $response->assertSee('data-tooltip="System Settings"', false);
        $response->assertSee('data-tooltip="Activity Logs"', false);
        $response->assertDontSee('data-tooltip="About & Attributions"', false);
        $response->assertDontSee('/about', false);
        $response->assertSee('data-tooltip="Manajemen Akun"', false);
    }

    /**
     * Test that analyst user gets proper navigation without root super-admin elements.
     */
    public function test_analyst_user_navigation_structure(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee('id="sidebar-desktop-toggle"', false);
        $response->assertSee('id="sidebar-mobile-toggle"', false);
        $response->assertDontSee('data-tooltip="Manajemen Akun"', false);
    }

    /**
     * Test that all critical admin views render 200 OK and have responsive table containers.
     */
    public function test_admin_pages_render_and_contain_responsive_table_containers(): void
    {
        // 1. Dashboard
        $dash = $this->actingAs($this->superAdmin)->get(route('dashboard'));
        $dash->assertStatus(200);
        $dash->assertSee('responsive-table-container', false);

        // 2. Vessels Registry
        $vessels = $this->actingAs($this->superAdmin)->get(route('vessels.index'));
        $vessels->assertStatus(200);
        $vessels->assertSee('responsive-table-container', false);
        $vessels->assertSee('min-w-[850px]', false);

        // 3. Encounters Index
        $encounters = $this->actingAs($this->superAdmin)->get(route('encounters.index'));
        $encounters->assertStatus(200);
        $encounters->assertSee('responsive-table-container', false);
        $encounters->assertSee('min-w-[800px]', false);

        // 4. Dark Vessels (SAR Analyst Review)
        $dark = $this->actingAs($this->superAdmin)->get(route('dark-vessels.index'));
        $dark->assertStatus(200);
        $dark->assertSee('responsive-table-container', false);
        $dark->assertSee('min-w-[900px]', false);

        // 5. Compliance Index
        $comp = $this->actingAs($this->superAdmin)->get(route('compliance.index'));
        $comp->assertStatus(200);
        $comp->assertSee('responsive-table-container', false);

        // 6. Investigations Index
        $inv = $this->actingAs($this->superAdmin)->get(route('investigations.index'));
        $inv->assertStatus(200);
        $inv->assertSee('responsive-table-container', false);
        $inv->assertSee('min-w-[850px]', false);

        // 7. Deprecated Fishing Grounds Route (410 Gone)
        $fg = $this->actingAs($this->superAdmin)->get('/fishing-grounds');
        $fg->assertStatus(410);

        // 8. Admin Users Index
        $users = $this->actingAs($this->superAdmin)->get(route('admin.users.index'));
        $users->assertStatus(200);
        $users->assertSee('responsive-table-container', false);
        $users->assertSee('min-w-[850px]', false);

        // 9. Activity Logs
        $logs = $this->actingAs($this->superAdmin)->get(route('audit-logs.index'));
        $logs->assertStatus(200);
        $logs->assertSee('responsive-table-container', false);
        $logs->assertSee('min-w-[750px]', false);

        // 10. Data Providers Catalog
        $prov = $this->actingAs($this->superAdmin)->get(route('providers.index'));
        $prov->assertStatus(200);

        // 11. System Settings
        $settings = $this->actingAs($this->superAdmin)->get(route('settings.index'));
        $settings->assertStatus(200);

        // 12. About Page is removed (returns 404)
        $about = $this->actingAs($this->superAdmin)->get('/about');
        $about->assertStatus(404);

        // 13. Map View
        $map = $this->actingAs($this->superAdmin)->get(route('map.index'));
        $map->assertStatus(200);

        // 14. Live Tracking View is removed (returns 404)
        $track = $this->actingAs($this->superAdmin)->get('/tracking');
        $track->assertStatus(404);
    }
}

