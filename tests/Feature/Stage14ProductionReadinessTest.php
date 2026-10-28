<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Stage14ProductionReadinessTest extends TestCase
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
            'organization' => 'Maritime Operations Directorate',
        ]);

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'organization' => 'Fisheries Intelligence Center',
        ]);

        $this->viewer = User::factory()->create([
            'role' => 'viewer',
            'organization' => 'Port Authority Observer',
        ]);
    }

    public function test_api_health_endpoint_returns_minimal_contract(): void
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'application',
                'database',
                'postgis',
                'queue',
                'scheduler',
                'ais',
                'copernicus',
                'gfw',
                'backup',
                'disaster_recovery',
            ]);

        $json = $response->json();
        $this->assertEquals('ok', $json['application']);
        $this->assertEquals('access_not_authorized', $json['gfw']);
    }

    public function test_api_health_endpoint_detailed_returns_comprehensive_observability_without_secrets(): void
    {
        $response = $this->getJson('/api/health?detail=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'system_health',
                'provider_health',
                'scientific_evidence_status',
                'backup_and_dr',
                'offsite_backup_gate',
                'storage',
                'alerts',
            ]);

        $content = $response->getContent();
        // Strict Secret Leakage Assertions
        $this->assertStringNotContainsString('DB_PASSWORD', $content);
        $this->assertStringNotContainsString('COPERNICUS_CLIENT_SECRET', $content);
        $this->assertStringNotContainsString('GFW_API_TOKEN', $content);
        $this->assertStringNotContainsString('bearer ', strtolower($content));
    }

    public function test_viewer_is_forbidden_from_updating_offsite_backup_gate(): void
    {
        $response = $this->actingAs($this->viewer)
            ->post('/settings/offsite-backup', [
                'status' => 'ACTIVE',
                'provider' => 'AWS_S3',
                'destination_uri' => 's3://vault/backups',
                'confirm_operator' => 1,
            ]);

        $response->assertStatus(403);
    }

    public function test_analyst_is_forbidden_from_updating_offsite_backup_gate(): void
    {
        $response = $this->actingAs($this->analyst)
            ->post('/settings/offsite-backup', [
                'status' => 'ACTIVE',
                'provider' => 'AWS_S3',
                'destination_uri' => 's3://vault/backups',
                'confirm_operator' => 1,
            ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_update_offsite_backup_gate_and_audit_log_is_recorded(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->post('/settings/offsite-backup', [
                'status' => 'READY',
                'provider' => 'S3_COMPATIBLE',
                'destination_uri' => 's3://my-test-vault/backups',
                'confirm_operator' => 1,
            ]);

        $response->assertRedirect('/settings');
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'offsite_backup_gate_update',
            'entity_type' => 'system_setting',
            'entity_id' => 'offsite_backup',
        ]);
    }

    public function test_offsite_backup_gate_requires_explicit_operator_confirmation(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->post('/settings/offsite-backup', [
                'status' => 'ACTIVE',
                'provider' => 'S3_COMPATIBLE',
                'destination_uri' => 's3://my-test-vault/backups',
                // confirm_operator omitted
            ]);

        $response->assertSessionHasErrors('confirm_operator');
    }

    public function test_ais_freshness_categorization_separates_live_stale_and_very_stale(): void
    {
        $vessel = Vessel::create([
            'mmsi' => '525999001',
            'name' => 'KM TEST FRESHNESS',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // 1. Live position (< 2h)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.12,
            'longitude' => 106.82,
            'observed_at' => now()->subMinutes(15),
            'received_at' => now(),
        ]);

        // 2. Stale position (2h - 6h)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.15,
            'longitude' => 106.85,
            'observed_at' => now()->subHours(3),
            'received_at' => now(),
        ]);

        // 3. Very stale position (> 6h)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.20,
            'longitude' => 106.90,
            'observed_at' => now()->subHours(10),
            'received_at' => now(),
        ]);

        $healthService = app(\App\Services\SystemHealthService::class);
        $freshness = $healthService->getAisFreshnessStatus();

        $this->assertEquals('LIVE', $freshness['freshness_rating']);
        $this->assertGreaterThanOrEqual(1, $freshness['metrics']['live_under_2h']);
        $this->assertGreaterThanOrEqual(1, $freshness['metrics']['stale_2h_to_6h']);
        $this->assertGreaterThanOrEqual(1, $freshness['metrics']['very_stale_over_6h']);
    }
}
