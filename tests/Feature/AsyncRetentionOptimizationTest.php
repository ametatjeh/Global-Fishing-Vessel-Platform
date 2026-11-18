<?php

namespace Tests\Feature;

use App\Jobs\ExecuteRetentionCleanupJob;
use App\Models\AisRetentionPolicy;
use App\Models\AisRetentionRun;
use App\Models\AisRetentionSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\DataRetentionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncRetentionOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $admin;
    protected User $analyst;
    protected DataRetentionPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@gfw.org',
            'role' => 'super_admin',
        ]);

        $this->admin = User::factory()->create([
            'name' => 'System Administrator',
            'email' => 'admin@gfw.org',
            'role' => 'admin',
        ]);

        $this->analyst = User::factory()->create([
            'name' => 'Maritime Analyst',
            'email' => 'analyst@gfw.org',
            'role' => 'analyst',
        ]);

        $this->service = app(DataRetentionPolicyService::class);
    }

    public function test_retention_overview_uses_cache_and_avoids_repeated_full_scans(): void
    {
        Cache::forget(DataRetentionPolicyService::CACHE_KEY_STATS);

        // First call calculates and stores in cache
        $stats1 = $this->service->getStorageStatistics();
        $this->assertArrayHasKey('total_positions', $stats1);
        $this->assertArrayHasKey('fishing_positions', $stats1);
        $this->assertArrayHasKey('non_fishing_positions', $stats1);

        // Put sentinel value into cache to verify cache hit
        $sentinelStats = $stats1;
        $sentinelStats['sentinel_test'] = true;
        Cache::put(DataRetentionPolicyService::CACHE_KEY_STATS, $sentinelStats, 3600);

        // Second call must return from cache
        $stats2 = $this->service->getStorageStatistics(forceFresh: false);
        $this->assertTrue($stats2['sentinel_test'] ?? false);

        // Force fresh must bypass cache
        $stats3 = $this->service->getStorageStatistics(forceFresh: true);
        $this->assertArrayNotHasKey('sentinel_test', $stats3);
    }

    public function test_retention_page_renders_with_paginated_history_and_runs(): void
    {
        // Create sample runs
        for ($i = 1; $i <= 15; $i++) {
            AisRetentionRun::create([
                'run_type' => 'manual_purge',
                'status' => 'completed',
                'total_records_purged' => $i * 10,
                'batch_count' => $i,
                'duration_seconds' => 1.5,
                'initiated_by_user_id' => $this->admin->id,
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/settings/retention');

        $response->assertStatus(200);
        $response->assertSee('Retention Execution &amp; Cleanup History', false);
        $response->assertSee('Total 15 execution records');
        // Pagination link should be present
        $response->assertSee('runs_page=2');
    }

    public function test_run_status_json_endpoint_returns_live_metrics(): void
    {
        $run = AisRetentionRun::create([
            'run_type' => 'manual_purge',
            'status' => 'running',
            'total_records_purged' => 500,
            'batch_count' => 5,
            'chunk_size' => 100,
            'started_at' => now()->subSeconds(10),
            'initiated_by_user_id' => $this->superAdmin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/settings/retention/runs/{$run->id}/status");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $run->id,
            'status' => 'running',
            'total_records_purged' => 500,
            'batch_count' => 5,
            'is_active' => true,
        ]);
    }

    public function test_super_admin_can_cancel_active_retention_run(): void
    {
        $run = AisRetentionRun::create([
            'run_type' => 'manual_purge',
            'status' => 'running',
            'started_at' => now(),
            'initiated_by_user_id' => $this->superAdmin->id,
        ]);

        $response = $this->actingAs($this->superAdmin)->post("/settings/retention/runs/{$run->id}/cancel");

        $response->assertRedirect('/settings/retention');
        $this->assertEquals('cancelled', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->completed_at);
    }

    public function test_regular_admin_cannot_cancel_retention_run(): void
    {
        $run = AisRetentionRun::create([
            'run_type' => 'manual_purge',
            'status' => 'running',
            'started_at' => now(),
            'initiated_by_user_id' => $this->superAdmin->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/settings/retention/runs/{$run->id}/cancel");

        $response->assertStatus(403);
        $this->assertEquals('running', $run->fresh()->status);
    }

    public function test_refresh_stats_endpoint_recalculates_cache(): void
    {
        $response = $this->actingAs($this->admin)->post('/settings/retention/refresh-stats');

        $response->assertRedirect('/settings/retention');
        $response->assertSessionHas('status', 'Statistik retensi data AIS berhasil diperbarui secara langsung dari database.');
    }

    public function test_async_job_executes_run_and_releases_atomic_lock(): void
    {
        $cargoVessel = Vessel::create([
            'name' => 'MV ASYNC CARGO',
            'mmsi' => '525999001',
            'vessel_type' => 'cargo',
        ]);

        AisRetentionPolicy::where('vessel_type', 'cargo')->update([
            'retention_days' => 30,
            'is_enabled' => true,
        ]);

        $oldDate = now()->subDays(60);
        $pos = VesselPosition::create([
            'vessel_id' => $cargoVessel->id,
            'latitude' => -5.0,
            'longitude' => 106.0,
            'observed_at' => $oldDate,
            'received_at' => $oldDate,
        ]);

        $run = AisRetentionRun::create([
            'run_type' => 'manual_purge',
            'status' => 'pending',
            'chunk_size' => 100,
            'initiated_by_user_id' => $this->superAdmin->id,
        ]);

        // Execute job
        $job = new ExecuteRetentionCleanupJob($run);
        $job->handle($this->service);

        $freshRun = $run->fresh();
        $this->assertEquals('completed', $freshRun->status);
        $this->assertEquals(1, $freshRun->total_records_purged);
        $this->assertNotNull($freshRun->completed_at);
        $this->assertFalse(Cache::has('ais_retention_execution_lock'));
        $this->assertDatabaseMissing('vessel_positions', ['id' => $pos->id]);
    }

    public function test_async_job_fails_cleanly_if_another_run_holds_atomic_lock(): void
    {
        $run = AisRetentionRun::create([
            'run_type' => 'manual_purge',
            'status' => 'pending',
            'chunk_size' => 100,
            'initiated_by_user_id' => $this->superAdmin->id,
        ]);

        // Manually acquire lock
        $lock = Cache::lock('ais_retention_execution_lock', 60);
        $lock->get();

        try {
            $job = new ExecuteRetentionCleanupJob($run);
            $job->handle($this->service);

            $this->assertEquals('failed', $run->fresh()->status);
            $this->assertStringContainsString('proses retensi lain', $run->fresh()->error_message);
        } finally {
            $lock->release();
        }
    }
}
