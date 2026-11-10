<?php

namespace Tests\Feature;

use App\Models\AisRetentionPolicy;
use App\Models\AisRetentionSetting;
use App\Models\AuditLog;
use App\Models\EncounterVessel;
use App\Models\Investigation;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselPosition;
use App\Services\DataRetentionPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DataRetentionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $superAdminUser;
    protected User $analystUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminUser = User::factory()->create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@gfvip.org',
            'role' => 'super_admin',
        ]);

        $this->adminUser = User::factory()->create([
            'name' => 'System Admin',
            'email' => 'admin@gfvip.org',
            'role' => 'admin',
        ]);

        $this->analystUser = User::factory()->create([
            'name' => 'Maritime Analyst',
            'email' => 'analyst@gfvip.org',
            'role' => 'analyst',
        ]);
    }

    public function test_system_settings_data_retention_page_accessible_by_admin(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/settings/retention');

        $response->assertStatus(200);
        $response->assertSee('AIS Data Retention Policy');
        $response->assertSee('Fishing Vessels (Fishing)');
        $response->assertSee('FISHING: 100% IMMUTABLE');
        $response->assertSee('Permanent (Unlimited / Forever)');
    }

    public function test_data_retention_page_forbidden_for_regular_analyst(): void
    {
        $response = $this->actingAs($this->analystUser)->get('/settings/retention');

        $response->assertStatus(403);
    }

    public function test_fishing_policy_retention_is_strictly_immutable_and_cannot_be_overridden(): void
    {
        $service = app(DataRetentionPolicyService::class);

        // Attempt to maliciously or accidentally set retention_days on fishing
        $payload = [
            'policies' => [
                'fishing' => [
                    'retention_days' => 7, // Attempt to set 7 days
                    'is_enabled' => 1,
                ],
                'cargo' => [
                    'retention_days' => 60,
                    'is_enabled' => 1,
                ],
            ],
            'settings' => [
                'auto_cleanup_enabled' => 0,
                'cleanup_schedule' => 'daily',
                'cleanup_time' => '03:00',
            ],
        ];

        $response = $this->actingAs($this->adminUser)->post('/settings/retention', $payload);

        $response->assertRedirect('/settings/retention');
        $response->assertSessionHas('status');

        $fishingPolicy = AisRetentionPolicy::where('vessel_type', 'fishing')->first();
        $this->assertNotNull($fishingPolicy);
        $this->assertNull($fishingPolicy->retention_days, 'Fishing retention_days MUST remain null (permanent).');
        $this->assertTrue($fishingPolicy->is_immutable, 'Fishing policy MUST remain immutable.');

        $cargoPolicy = AisRetentionPolicy::where('vessel_type', 'cargo')->first();
        $this->assertEquals(60, $cargoPolicy->retention_days);
    }

    public function test_non_fishing_and_unknown_policies_persist_and_audit_logged(): void
    {
        $payload = [
            'policies' => [
                'cargo' => ['retention_days' => 180, 'is_enabled' => 1],
                'tanker' => ['retention_days' => 180, 'is_enabled' => 1],
                'passenger' => ['retention_days' => 30, 'is_enabled' => 1],
                'unknown' => ['retention_days' => 14, 'is_enabled' => 1],
            ],
            'settings' => [
                'auto_cleanup_enabled' => 1,
                'cleanup_schedule' => 'weekly',
                'cleanup_time' => '04:00',
            ],
        ];

        $response = $this->actingAs($this->adminUser)->post('/settings/retention', $payload);

        $response->assertRedirect('/settings/retention');

        $this->assertDatabaseHas('ais_retention_policies', [
            'vessel_type' => 'unknown',
            'retention_days' => 14,
        ]);

        $this->assertDatabaseHas('ais_retention_settings', [
            'id' => 1,
            'auto_cleanup_enabled' => true,
            'cleanup_schedule' => 'weekly',
            'cleanup_time' => '04:00',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'entity_type' => 'ais_retention_policy',
            'user_id' => $this->adminUser->id,
        ]);
    }

    public function test_dry_run_simulation_does_not_delete_any_records(): void
    {
        $fishingVessel = Vessel::create(['name' => 'KM BERKAH LAUT', 'mmsi' => '525000001', 'vessel_type' => 'fishing']);
        $cargoVessel = Vessel::create(['name' => 'MV MERATUS', 'mmsi' => '525000002', 'vessel_type' => 'cargo']);

        // Create old positions (100 days old)
        $oldDate = now()->subDays(100);
        VesselPosition::create(['vessel_id' => $fishingVessel->id, 'latitude' => -5.0, 'longitude' => 106.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);
        VesselPosition::create(['vessel_id' => $cargoVessel->id, 'latitude' => 1.0, 'longitude' => 104.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);

        $this->assertEquals(2, VesselPosition::count());

        $response = $this->actingAs($this->adminUser)->post('/settings/retention/simulate');

        $response->assertRedirect('/settings/retention');
        $response->assertSessionHas('simulation');

        // Confirm database was completely untouched
        $this->assertEquals(2, VesselPosition::count());
    }

    public function test_fishing_vessel_positions_are_never_purged_even_when_exceeding_days(): void
    {
        $service = app(DataRetentionPolicyService::class);

        $fishingVessel = Vessel::create(['name' => 'KM BAHARI JAYA', 'mmsi' => '525000010', 'vessel_type' => 'fishing']);
        $cargoVessel = Vessel::create(['name' => 'MV PACIFIC CARGO', 'mmsi' => '525000020', 'vessel_type' => 'cargo']);

        // Set cargo retention to 30 days
        AisRetentionPolicy::where('vessel_type', 'cargo')->update(['retention_days' => 30, 'is_enabled' => true]);

        // Create positions from 90 days ago (older than 30 days)
        $oldDate = now()->subDays(90);
        $recentDate = now()->subDays(5);

        // Fishing positions (1 old, 1 recent) -> BOTH must be kept!
        $fpOld = VesselPosition::create(['vessel_id' => $fishingVessel->id, 'latitude' => -6.0, 'longitude' => 107.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);
        $fpRecent = VesselPosition::create(['vessel_id' => $fishingVessel->id, 'latitude' => -6.1, 'longitude' => 107.1, 'observed_at' => $recentDate, 'received_at' => $recentDate]);

        // Cargo positions (1 old, 1 recent) -> Old must be pruned, recent kept!
        $cpOld = VesselPosition::create(['vessel_id' => $cargoVessel->id, 'latitude' => 2.0, 'longitude' => 105.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);
        $cpRecent = VesselPosition::create(['vessel_id' => $cargoVessel->id, 'latitude' => 2.1, 'longitude' => 105.1, 'observed_at' => $recentDate, 'received_at' => $recentDate]);

        $this->assertEquals(4, VesselPosition::count());

        // Execute purge with force=true
        $result = $service->executeRetentionPurge(force: true, userId: $this->superAdminUser->id);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['records_purged']); // Exactly 1 cargo old position

        // Assert Fishing positions are 100% intact
        $this->assertDatabaseHas('vessel_positions', ['id' => $fpOld->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $fpRecent->id]);

        // Assert recent cargo is intact, old cargo is deleted
        $this->assertDatabaseHas('vessel_positions', ['id' => $cpRecent->id]);
        $this->assertDatabaseMissing('vessel_positions', ['id' => $cpOld->id]);

        // Master vessel identity must NEVER be deleted
        $this->assertDatabaseHas('vessels', ['id' => $cargoVessel->id]);
        $this->assertDatabaseHas('vessels', ['id' => $fishingVessel->id]);
    }

    public function test_vessels_with_operational_evidence_are_strictly_protected_from_purging(): void
    {
        $service = app(DataRetentionPolicyService::class);

        $cargoVessel = Vessel::create(['name' => 'MV SUSPICIOUS CARGO', 'mmsi' => '525000099', 'vessel_type' => 'cargo']);

        // Set cargo retention to 30 days
        AisRetentionPolicy::where('vessel_type', 'cargo')->update(['retention_days' => 30, 'is_enabled' => true]);

        // Tie this cargo vessel to an investigation
        Investigation::create([
            'case_number' => 'INV-2026-001',
            'title' => 'Transshipment Suspicion in Natuna',
            'summary' => 'Investigation into suspicious rendezvous in Natuna Sea.',
            'target_vessel_id' => $cargoVessel->id,
            'status' => 'open',
            'priority' => 'high',
        ]);

        // Create 100 days old position
        $oldDate = now()->subDays(100);
        $pos = VesselPosition::create(['vessel_id' => $cargoVessel->id, 'latitude' => 3.5, 'longitude' => 108.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);

        // Execute purge
        $result = $service->executeRetentionPurge(force: true, userId: $this->superAdminUser->id);

        // Position must NOT be deleted because vessel is evidence in an active investigation
        $this->assertEquals(0, $result['records_purged']);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    public function test_reclassified_fishing_vessel_immediately_protects_historical_positions(): void
    {
        $service = app(DataRetentionPolicyService::class);

        // Vessel starts as unknown
        $vessel = Vessel::create(['name' => 'UNKNOWN VESSEL 44', 'mmsi' => '525000044', 'vessel_type' => 'unknown']);

        AisRetentionPolicy::where('vessel_type', 'unknown')->update(['retention_days' => 14, 'is_enabled' => true]);

        $oldDate = now()->subDays(60);
        $pos = VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => -4.0, 'longitude' => 110.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);

        // Reclassify vessel to fishing
        $vessel->update(['vessel_type' => 'fishing']);

        // Execute purge
        $result = $service->executeRetentionPurge(force: true, userId: $this->superAdminUser->id);

        $this->assertEquals(0, $result['records_purged']);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    public function test_artisan_prune_command_dry_run_and_execution_guardrails(): void
    {
        $this->artisan('ais:prune-telemetry --dry-run')
            ->expectsOutputToContain('GFVIP - AIS HISTORICAL TELEMETRY RETENTION ENGINE')
            ->expectsOutputToContain('MODE SIMULASI / DRY-RUN')
            ->expectsOutputToContain('Simulasi selesai. Tidak ada data yang dihapus dari database.')
            ->assertExitCode(0);

        // When auto cleanup is disabled, running without force should warn and halt safely
        AisRetentionSetting::instance()->update(['auto_cleanup_enabled' => false]);

        $this->artisan('ais:prune-telemetry')
            ->expectsOutputToContain('Automatic Cleanup saat ini dinonaktifkan (DISABLED)')
            ->assertExitCode(0);
    }
}
