<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\Investigation;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearcherRoleAccessRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected User $researcher;
    protected User $superAdmin;
    protected User $admin;
    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->researcher = User::factory()->create([
            'name' => 'Field Researcher',
            'email' => 'field.researcher@gfvip.org',
            'role' => 'researcher',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@gfvip.org',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'name' => 'System Administrator',
            'email' => 'admin@gfvip.org',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->analyst = User::factory()->create([
            'name' => 'Maritime Intelligence Analyst',
            'email' => 'analyst@gfvip.org',
            'role' => 'analyst',
            'is_active' => true,
        ]);
    }

    public function test_researcher_is_strictly_forbidden_from_admin_users_endpoint(): void
    {
        $response = $this->actingAs($this->researcher)->get('/admin/users');
        $response->assertStatus(403);
    }

    public function test_researcher_is_strictly_forbidden_from_audit_logs_endpoint(): void
    {
        $response = $this->actingAs($this->researcher)->get('/audit-logs');
        $response->assertStatus(403);
    }

    public function test_researcher_is_strictly_forbidden_from_encounters_endpoints(): void
    {
        // 1. Index route
        $responseIndex = $this->actingAs($this->researcher)->get('/encounters');
        $responseIndex->assertStatus(403);

        // 2. Detail route
        $vessel1 = Vessel::create(['name' => 'V1', 'mmsi' => '525000001', 'vessel_type' => 'fishing']);
        $vessel2 = Vessel::create(['name' => 'V2', 'mmsi' => '525000002', 'vessel_type' => 'cargo']);
        $encounter = VesselEncounter::create([
            'encounter_code' => 'ENC-RESTRICTED-01',
            'vessel_1_id' => $vessel1->id,
            'vessel_2_id' => $vessel2->id,
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'latitude' => -5.85,
            'longitude' => 106.85,
            'min_distance_meters' => 150,
            'duration_minutes' => 45,
            'status' => 'candidate',
            'category' => 'transshipment',
            'parameters_used' => ['max_distance_meters' => 500],
        ]);

        $responseDetail = $this->actingAs($this->researcher)->get("/encounters/{$encounter->id}");
        $responseDetail->assertStatus(403);
    }

    public function test_researcher_is_strictly_forbidden_from_classification_review_endpoint(): void
    {
        $response = $this->actingAs($this->researcher)->get('/vessels/classification-review');
        $response->assertStatus(403);
    }

    public function test_researcher_is_strictly_forbidden_from_investigations_endpoints(): void
    {
        // 1. Index route
        $responseIndex = $this->actingAs($this->researcher)->get('/investigations');
        $responseIndex->assertStatus(403);

        // 2. Create route
        $responseCreate = $this->actingAs($this->researcher)->get('/investigations/create');
        $responseCreate->assertStatus(403);

        // 3. Detail route
        $vessel = Vessel::create(['name' => 'Target Vessel', 'mmsi' => '525000999', 'vessel_type' => 'fishing']);
        $investigation = Investigation::create([
            'case_number' => 'INV-2026-RESTRICTED',
            'title' => 'Secret Dossier',
            'summary' => 'Confidential investigative inquiry into prohibited transshipment.',
            'target_vessel_id' => $vessel->id,
            'created_by_user_id' => $this->superAdmin->id,
            'status' => 'open',
            'priority' => 'high',
            'lead_user_id' => $this->superAdmin->id,
        ]);

        $responseShow = $this->actingAs($this->researcher)->get("/investigations/{$investigation->id}");
        $responseShow->assertStatus(403);

        // 4. Export route
        $responseExport = $this->actingAs($this->researcher)->get("/investigations/{$investigation->id}/export");
        $responseExport->assertStatus(403);
    }

    public function test_researcher_ui_navigation_does_not_render_restricted_links(): void
    {
        $response = $this->actingAs($this->researcher)->get('/dashboard');
        $response->assertStatus(200);

        // Assert restricted route URLs and labels do not appear in the sidebar/navigation
        $response->assertDontSee('/admin/users', false);
        $response->assertDontSee('User Management', false);

        $response->assertDontSee('/audit-logs', false);
        $response->assertDontSee('Activity Logs', false);

        $response->assertDontSee('/encounters', false);
        $response->assertDontSee('Vessel Encounters', false);

        $response->assertDontSee('/vessels/classification-review', false);
        $response->assertDontSee('Classification Review', false);

        $response->assertDontSee('/investigations', false);
        $response->assertDontSee('Investigations', false);
    }

    public function test_authorized_roles_retain_access_to_their_respective_features(): void
    {
        // Super Admin has universal access
        $this->actingAs($this->superAdmin)->get('/admin/users')->assertStatus(200);
        $this->actingAs($this->superAdmin)->get('/audit-logs')->assertStatus(200);
        $this->actingAs($this->superAdmin)->get('/encounters')->assertStatus(200);
        $this->actingAs($this->superAdmin)->get('/vessels/classification-review')->assertStatus(200);
        $this->actingAs($this->superAdmin)->get('/investigations')->assertStatus(200);

        // Admin has access to encounters, classification-review, audit-logs, investigations
        $this->actingAs($this->admin)->get('/audit-logs')->assertStatus(200);
        $this->actingAs($this->admin)->get('/encounters')->assertStatus(200);
        $this->actingAs($this->admin)->get('/vessels/classification-review')->assertStatus(200);
        $this->actingAs($this->admin)->get('/investigations')->assertStatus(200);

        // Analyst has access to encounters, classification-review, investigations
        $this->actingAs($this->analyst)->get('/encounters')->assertStatus(200);
        $this->actingAs($this->analyst)->get('/vessels/classification-review')->assertStatus(200);
        $this->actingAs($this->analyst)->get('/investigations')->assertStatus(200);
    }
}
