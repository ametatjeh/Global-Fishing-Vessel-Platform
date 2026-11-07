<?php

namespace Tests\Feature;

use App\Jobs\EnrichVesselGfwJob;
use App\Models\User;
use App\Models\Vessel;
use App\Services\Maritime\GfwVesselEnrichmentService;
use App\Services\Providers\GfwApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomaticGfwIntelligenceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_vessel_automatically_dispatches_background_job_on_show_view(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'F/V CAP SAINT PIERRE',
            'mmsi' => '227001001',
            'imo' => '9201013',
            'callsign' => 'FN8001',
            'flag_state' => 'FRA',
            'vessel_type' => 'fishing',
            'enrichment_status' => 'not_enriched',
        ]);

        $response = $this->actingAs($user)->get(route('vessels.show', $vessel->id));

        $response->assertStatus(200);

        // Job must be dispatched in the background without blocking the page load
        Queue::assertPushed(EnrichVesselGfwJob::class, function ($job) use ($vessel) {
            return $job->vesselId === $vessel->id && $job->force === false;
        });

        // Repeated view within lock period should not dispatch duplicate job
        Queue::fake();
        $response2 = $this->actingAs($user)->get(route('vessels.show', $vessel->id));
        $response2->assertStatus(200);
        Queue::assertNothingPushed();
    }

    public function test_ineligible_vessel_does_not_dispatch_job_and_manual_sync_is_rejected(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => 'analyst']);

        // Vessel without valid 7-digit IMO or valid 9-digit MMSI
        $vessel = Vessel::create([
            'name' => 'LOCAL SKIFF 99',
            'mmsi' => null,
            'imo' => null,
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'enrichment_status' => 'not_enriched',
        ]);

        $response = $this->actingAs($user)->get(route('vessels.show', $vessel->id));
        $response->assertStatus(200);
        Queue::assertNothingPushed();

        // Manual sync attempt on ineligible vessel should be rejected gracefully
        $postResponse = $this->actingAs($user)->post(route('vessels.gfw-sync', $vessel->id));
        $postResponse->assertRedirect(route('vessels.show', $vessel->id));
        $postResponse->assertSessionHas('warning');
        Queue::assertNothingPushed();
    }

    public function test_manual_sync_endpoint_dispatches_forced_job_with_flash_message(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'F/V OCEAN STAR',
            'mmsi' => '525001234',
            'imo' => '9123456',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'enrichment_status' => 'not_found',
        ]);

        $response = $this->actingAs($user)->post(route('vessels.gfw-sync', $vessel->id));

        $response->assertRedirect(route('vessels.show', $vessel->id));
        $response->assertSessionHas('success');

        Queue::assertPushed(EnrichVesselGfwJob::class, function ($job) use ($vessel) {
            return $job->vesselId === $vessel->id && $job->force === true;
        });
    }

    public function test_target_vessel_not_found_in_gfw_renders_not_found_status_and_empty_state(): void
    {
        config(['maritime.providers.gfw.token' => 'mock-gfw-token-abc']);

        $user = User::factory()->create(['role' => 'analyst']);

        // Mock GFW search: 0 entries returned for IMO and MMSI
        Http::fake([
            '*/vessels/search*' => Http::response([
                'total' => 0,
                'entries' => [],
            ], 200),
        ]);

        $vessel = Vessel::create([
            'id' => '01a18664-955f-72c8-a877-55d959e8c5e1',
            'name' => 'F/V CAP SAINT PIERRE',
            'mmsi' => '227001001',
            'imo' => '9201013',
            'callsign' => 'FN8001',
            'flag_state' => 'FRA',
            'vessel_type' => 'fishing',
            'operator' => 'Compagnie Francaise du Thon Oceanique',
            'owner' => 'Compagnie Francaise du Thon Oceanique',
            'enrichment_status' => 'not_enriched',
        ]);

        // Run enrichment directly
        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: false);

        $this->assertEquals('no_match', $result['status']);
        $this->assertEquals('not_found', $result['sync_status']);

        $vessel->refresh();
        $this->assertEquals('not_found', $vessel->gfw_sync_status);
        $this->assertNull($vessel->gfw_vessel_id);

        // View the target vessel page
        $response = $this->actingAs($user)->get(route('vessels.show', $vessel->id));

        $response->assertStatus(200);
        $response->assertSee('Not Found in GFW');
        $response->assertSee('Kapal Tidak Ditemukan dalam Katalog Publik Global Fishing Watch');
        $response->assertSee('public-global-vessel-identity:latest');
        $response->assertSee('9201013');
        $response->assertSee('227001001');
    }

    public function test_gfw_intelligence_suite_renders_7_tabs_and_mandatory_disclaimer(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'F/V MARITIME HOPE',
            'mmsi' => '525112233',
            'imo' => '9876543',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'enrichment_status' => 'enriched',
            'gfw_vessel_id' => 'gfw-vessel-abc123',
            'last_enriched_at' => now()->subHours(2),
            'metadata' => [
                'enrichment' => [
                    'provider' => 'global-fishing-watch',
                    'gfw_vessel_id' => 'gfw-vessel-abc123',
                    'dataset' => 'public-global-vessel-identity:latest',
                    'match_method' => 'imo_exact_match',
                    'match_confidence' => 'high_authoritative',
                    'matching_identifiers' => ['IMO: 9876543'],
                    'events_summary' => [
                        'fishing' => ['count' => 12, 'status' => 'available'],
                        'encounters' => ['count' => 2, 'status' => 'available'],
                        'loitering' => ['count' => 1, 'status' => 'available'],
                        'port_visits' => ['count' => 5, 'status' => 'available'],
                        'ais_gaps' => ['count' => 0, 'status' => 'available'],
                    ],
                    'raw_gfw_candidate' => [
                        'shipname' => 'MARITIME HOPE',
                        'ssvid' => '525112233',
                        'imo' => '9876543',
                        'flag' => 'IDN',
                        'shiptype' => 'fishing',
                        'geartype' => 'tuna purse seines',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('vessels.show', $vessel->id));

        $response->assertStatus(200);

        // Header and Badge
        $response->assertSee('GLOBAL FISHING WATCH MONITORING & EVENTS / GFW API v3', false);
        $response->assertSee('Synced');
        $response->assertSee('gfw-vessel-abc123');

        // All 7 Tabs
        $response->assertSee('1. Identitas & Registrasi', false);
        $response->assertSee('2. Aktivitas Penangkapan');
        $response->assertSee('3. Encounter & Transshipment', false);
        $response->assertSee('4. Loitering & Pelabuhan', false);
        $response->assertSee('5. AIS Gaps');
        $response->assertSee('6. Otorisasi & RFMO', false);
        $response->assertSee('7. Sumber Data & Disclaimer', false);

        // Disclaimers
        $response->assertSee('non-commercial research, educational, and public-interest purposes');
        $response->assertSee('PEMBERITAHUAN HUKUM & ANALISIS', false);
    }

    public function test_gfw_token_never_leaks_in_view_or_error_handling(): void
    {
        $token = 'test_secret_gfw_token_xyz987654321';
        config(['maritime.providers.gfw.token' => $token]);

        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'F/V SECURITY TEST',
            'mmsi' => '525998877',
            'imo' => '9998877',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'enrichment_status' => 'not_enriched',
        ]);

        $response = $this->actingAs($user)->get(route('vessels.show', $vessel->id));

        $response->assertStatus(200);
        $response->assertDontSee($token);
    }
}
