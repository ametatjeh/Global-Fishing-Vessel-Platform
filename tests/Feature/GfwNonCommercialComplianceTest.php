<?php

namespace Tests\Feature;

use App\Jobs\SyncProviderDataJob;
use App\Models\DataProvider;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Services\Providers\AisStreamAdapter;
use App\Services\Providers\CopernicusSarAdapter;
use App\Services\Providers\FaoFishingAreaAdapter;
use App\Services\Providers\GfwApiService;
use App\Services\Providers\GlobalFishingWatchAdapter;
use App\Services\Providers\MarineRegionsEezAdapter;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GfwNonCommercialComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected User $researcher;
    protected string $secretToken = 'gfw-top-secret-token-xyz-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->researcher = User::factory()->create([
            'name' => 'Marine Compliance Researcher',
            'email' => 'researcher@marine-intel.org',
            'role' => 'researcher',
            'organization' => 'Ocean Research Center',
            'is_active' => true,
        ]);

        config(['maritime.providers.gfw.token' => $this->secretToken]);
    }

    public function test_gfw_api_token_is_never_exposed_in_views_or_api_responses(): void
    {
        $vessel = Vessel::create([
            'name' => 'TESTING ATTRIBUTION VESSEL',
            'mmsi' => '525111222',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
            'gfw_vessel_id' => 'gfw-vessel-uuid-9999',
            'metadata' => [
                'enrichment' => [
                    'events_summary' => [
                        'port_visits' => ['count' => 0, 'status' => 'permission_restricted'],
                        'loitering' => ['count' => 0, 'status' => 'permission_restricted'],
                        'encounters' => ['count' => 0, 'status' => 'permission_restricted'],
                        'fishing' => ['count' => 0, 'status' => 'permission_restricted'],
                    ],
                ],
            ],
        ]);

        $routesToAudit = [
            '/about',
            '/providers',
            '/encounters',
            "/vessels/{$vessel->id}",
            '/map',
            '/api/health?detail=1',
        ];

        foreach ($routesToAudit as $route) {
            $response = $this->actingAs($this->researcher)->get($route);
            $response->assertStatus(200);

            // Secret token must NEVER appear in raw output, HTML, or JSON
            $content = $response->getContent();
            $this->assertStringNotContainsString($this->secretToken, $content, "GFW token leaked in {$route}");
            $this->assertStringNotContainsString("Bearer {$this->secretToken}", $content, "GFW Bearer token leaked in {$route}");
        }
    }

    public function test_mandatory_gfw_attribution_is_rendered_in_about_page(): void
    {
        $response = $this->actingAs($this->researcher)->get('/about');
        $response->assertStatus(200);

        // 1. Mandatory verbatim attribution
        $response->assertSee('This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes.');

        // 2. Non-commercial research badge
        $response->assertSee('NON-COMMERCIAL RESEARCH');

        // 3. Provider source link
        $response->assertSee('https://globalfishingwatch.org');

        // 4. Mandatory legal disclaimer
        $response->assertSee('Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.');

        // 5. Zero monetization statement
        $response->assertSee('Bebas Biaya 100%');
        $response->assertSee('Tanpa Langganan Premium');
    }

    public function test_mandatory_gfw_attribution_is_rendered_in_providers_page(): void
    {
        $response = $this->actingAs($this->researcher)->get('/providers');
        $response->assertStatus(200);

        $response->assertSee('Global Fishing Watch Data Attribution');
        $response->assertSee('NON-COMMERCIAL RESEARCH');
        $response->assertSee('https://globalfishingwatch.org');
        $response->assertSee('This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes.');
        $response->assertSee('Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.');
    }

    public function test_mandatory_gfw_attribution_is_rendered_in_global_footer(): void
    {
        // Audit footer on dashboard
        $response = $this->actingAs($this->researcher)->get('/dashboard');
        $response->assertStatus(200);

        $response->assertSee('NON-COMMERCIAL RESEARCH');
        $response->assertSee('Global Fishing Watch Data Attribution:');
        $response->assertSee('This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes.');
        $response->assertSee('Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.');
        $response->assertSee('https://globalfishingwatch.org');
    }

    public function test_mandatory_disclaimer_is_rendered_in_encounters_and_vessels_page(): void
    {
        $vessel = Vessel::create([
            'name' => 'DISCLAIMER AUDIT VESSEL',
            'mmsi' => '525999888',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
            'gfw_vessel_id' => 'gfw-id-1234',
            'metadata' => [
                'enrichment' => [
                    'events_summary' => [
                        'encounters' => ['count' => 0, 'status' => 'permission_restricted'],
                    ],
                ],
            ],
        ]);

        // Encounters index
        $encResponse = $this->actingAs($this->researcher)->get('/encounters');
        $encResponse->assertStatus(200);
        $encResponse->assertSee('NON-COMMERCIAL RESEARCH');
        $encResponse->assertSee('Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.');
        $encResponse->assertSee('https://globalfishingwatch.org');

        // Vessel detail
        $vslResponse = $this->actingAs($this->researcher)->get("/vessels/{$vessel->id}");
        $vslResponse->assertStatus(200);
        $vslResponse->assertSee('NON-COMMERCIAL RESEARCH');
        $vslResponse->assertSee('This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes.');
        $vslResponse->assertSee('Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.');
    }

    public function test_gfw_events_403_forbidden_sets_research_access_required_without_synthetic_data(): void
    {
        $initialEncounterCount = VesselEncounter::count();

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/datasets*' => Http::response([
                'entries' => [['id' => 'public-global-encounters-events:latest']],
                'total' => 1,
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response([
                'message' => 'Not authorized by permissions for dataset',
                'statusCode' => 403,
            ], 403),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);

        // 1. Health check returns research_access_required
        $health = $adapter->healthCheck();
        $this->assertSame('research_access_required', $health['status']);
        $this->assertStringContainsString('Research Access required', $health['message']);

        // 2. Ingestion sync returns forbidden_research_required
        $sync = $adapter->sync(['limit' => 10]);
        $this->assertSame('failed', $sync['status']);
        $this->assertSame('forbidden_research_required', $sync['error_type']);
        $this->assertSame(0, $sync['inserted']);

        // 3. Prohibit synthetic data generation: database count must remain unchanged
        $this->assertSame($initialEncounterCount, VesselEncounter::count());

        // 4. Provider record in database has status research_access_required
        $provider = DataProvider::where('slug', 'global-fishing-watch')->first();
        $this->assertNotNull($provider);
        $this->assertSame('research_access_required', $provider->health_status);
        $this->assertFalse($provider->commercial_use_allowed);

        // 5. SystemHealthService reflects research_access_required
        $healthService = app(SystemHealthService::class);
        $gfwHealth = $healthService->getGfwStatus();
        $this->assertSame('research_access_required', $gfwHealth['status']);
        $this->assertSame('NON-COMMERCIAL RESEARCH', $gfwHealth['badge']);
        $this->assertFalse($gfwHealth['commercial_use_allowed']);
    }

    public function test_all_gfw_features_are_free_without_paywall_or_subscription(): void
    {
        // Accessible freely for registered researchers
        $this->actingAs($this->researcher);

        $freePages = [
            '/about',
            '/vessels',
            '/fishing-grounds',
            '/encounters',
            '/providers',
            '/map',
        ];

        foreach ($freePages as $page) {
            $res = $this->get($page);
            $res->assertStatus(200);
            $res->assertDontSee('Upgrade to Premium');
            $res->assertDontSee('Subscription Required');
            $res->assertDontSee('Beli Paket Langganan');
            $res->assertDontSee('Pricing Plan');
        }
    }

    public function test_other_data_providers_function_independently_when_gfw_requires_research_access(): void
    {
        // Simulate GFW 403 Forbidden
        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response([
                'message' => 'Forbidden',
            ], 403),
        ]);

        // Background sync job executes all providers
        $job = new SyncProviderDataJob(providerSlug: null);
        $job->handle(
            app(GlobalFishingWatchAdapter::class),
            app(CopernicusSarAdapter::class),
            app(AisStreamAdapter::class),
            app(FaoFishingAreaAdapter::class),
            app(MarineRegionsEezAdapter::class)
        );

        // GFW status is research_access_required, but job completed cleanly without failing other modules
        $gfwProvider = DataProvider::where('slug', 'global-fishing-watch')->first();
        $this->assertSame('research_access_required', $gfwProvider->health_status);

        // Platform portal pages continue to render normally
        $this->actingAs($this->researcher);
        $this->get('/map')->assertStatus(200);
        $this->get('/vessels')->assertStatus(200);
        $this->get('/fishing-grounds')->assertStatus(200);
        $this->get('/providers')->assertStatus(200);
    }
}

