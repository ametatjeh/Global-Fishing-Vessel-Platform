<?php

namespace Tests\Feature;

use App\Models\ComplianceEvent;
use App\Models\ComplianceRule;
use App\Models\DataProvider;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAndRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $analyst;
    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'organization' => 'Headquarters',
        ]);

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'organization' => 'Intel Unit',
        ]);

        $this->viewer = User::factory()->create([
            'role' => 'viewer',
            'organization' => 'Guest Port Authority',
        ]);
    }

    public function test_viewer_is_forbidden_from_triggering_provider_health_check(): void
    {
        $provider = DataProvider::create([
            'slug' => 'test-provider',
            'name' => 'Test Provider',
            'type' => 'ais_stream',
            'is_enabled' => true,
        ]);

        // Viewer should receive 403 Forbidden
        $response = $this->actingAs($this->viewer)
            ->post("/providers/{$provider->slug}/health");

        $response->assertStatus(403);
    }

    public function test_viewer_is_forbidden_from_accessing_system_settings_and_audit_logs(): void
    {
        $this->actingAs($this->viewer)
            ->get('/settings')
            ->assertStatus(403);

        $this->actingAs($this->viewer)
            ->get('/audit-logs')
            ->assertStatus(403);
    }

    public function test_analyst_can_access_investigation_creation_and_update_compliance(): void
    {
        $this->actingAs($this->analyst)
            ->get('/investigations/create')
            ->assertStatus(200);

        $rule = ComplianceRule::create([
            'rule_code' => 'TEST-01',
            'rule_name' => 'Test Rule',
            'category' => 'ais_gap',
            'severity' => 'medium',
            'jurisdiction' => 'national_eez',
            'legal_source' => 'Permen KP 05/2021 & IMO Res. A.1106(29)',
            'rule_logic' => ['max_gap_hours' => 8],
            'is_active' => true,
        ]);

        $vessel = Vessel::create([
            'mmsi' => '525000001',
            'name' => 'TEST VESSEL',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $event = ComplianceEvent::create([
            'event_code' => 'EVT-TEST-01',
            'compliance_rule_id' => $rule->id,
            'vessel_id' => $vessel->id,
            'event_time' => now(),
            'latitude' => 0.0,
            'longitude' => 100.0,
            'severity' => 'medium',
            'status' => 'open',
            'evidence_summary' => 'Test event summary',
            'supporting_data' => ['test_key' => 'test_val'],
        ]);

        $response = $this->actingAs($this->analyst)
            ->post("/compliance/events/{$event->id}/status", [
                'status' => 'resolved',
                'resolution_notes' => 'Verified by analyst',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('compliance_events', [
            'id' => $event->id,
            'status' => 'resolved',
        ]);
    }

    public function test_super_admin_has_unrestricted_access_to_all_endpoints(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/settings')
            ->assertStatus(200);

        $this->actingAs($this->superAdmin)
            ->get('/audit-logs')
            ->assertStatus(200);
    }
}
