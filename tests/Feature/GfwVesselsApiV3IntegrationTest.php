<?php

namespace Tests\Feature;

use App\Jobs\EnrichVesselGfwJob;
use App\Models\ComplianceRule;
use App\Models\DataProvider;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\ComplianceEngineService;
use App\Services\EncounterDetectionService;
use App\Services\Maritime\GfwVesselEnrichmentService;
use App\Services\Providers\GfwApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GfwVesselsApiV3IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected GfwApiService $apiService;
    protected GfwVesselEnrichmentService $enrichmentService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maritime.providers.gfw.token' => 'test-bearer-token-12345',
            'maritime.providers.gfw.base_url' => 'https://gateway.api.globalfishingwatch.org/v3',
            'maritime.providers.gfw.dataset' => 'public-global-vessel-identity:latest',
            'maritime.providers.gfw.search_cache_ttl' => 604800,
        ]);

        Cache::flush();

        DataProvider::firstOrCreate(
            ['slug' => 'global-fishing-watch'],
            [
                'name' => 'Global Fishing Watch',
                'type' => 'gfw_events',
                'is_enabled' => true,
                'health_status' => 'healthy',
            ]
        );

        $this->apiService = app(GfwApiService::class);
        $this->enrichmentService = app(GfwVesselEnrichmentService::class);
    }

    public function test_gfw_vessel_search_by_mmsi_upgrades_unknown_to_fishing(): void
    {
        $vessel = Vessel::create([
            'name' => 'UNKNOWN REEF EXPLORER',
            'mmsi' => '525112233',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
            'enrichment_status' => 'not_enriched',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response([
                'total' => 1,
                'entries' => [
                    [
                        'id' => 'gfw-vessel-uuid-999',
                        'selfReportedInfo' => [
                            ['ssvid' => '525112233', 'shipname' => 'SAMUDRA JAYA 01']
                        ],
                        'registryInfo' => [
                            [
                                'geartypes' => ['trawlers'],
                                'shiptype' => 'fishing'
                            ]
                        ],
                        'combinedSourcesInfo' => [
                            [
                                'shiptypes' => [['name' => 'fishing']],
                                'geartypes' => [['name' => 'trawlers']],
                            ]
                        ],
                    ]
                ]
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/gfw-vessel-uuid-999*' => Http::response([
                'id' => 'gfw-vessel-uuid-999',
                'registryInfo' => [
                    ['shiptype' => 'fishing', 'length' => 32.5, 'tonnage' => 180]
                ]
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response([
                'total' => 0,
                'entries' => []
            ], 200),
        ]);

        $result = $this->enrichmentService->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();

        $this->assertEquals('enriched', $result['status']);
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals('enriched', $vessel->enrichment_status);
        $this->assertNotNull($vessel->last_enriched_at);
        $this->assertIsArray($vessel->metadata);
        $this->assertEquals('global-fishing-watch', $vessel->metadata['enrichment']['provider']);
        $this->assertEquals('GFW_VESSELS_API_V3', $vessel->metadata['enrichment']['field_provenance']['vessel_type']['source']);
    }

    public function test_gfw_vessel_search_by_imo_matches_highest_priority(): void
    {
        $vessel = Vessel::create([
            'name' => 'PACIFIC VOYAGER',
            'mmsi' => '525998877',
            'imo' => '9123456',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*query=9123456*' => Http::response([
                'total' => 1,
                'entries' => [
                    [
                        'id' => 'gfw-imo-matched-uuid',
                        'registryInfo' => [
                            ['imo' => '9123456', 'shiptype' => 'fishing']
                        ],
                        'combinedSourcesInfo' => [
                            ['shiptypes' => [['name' => 'fishing']]]
                        ]
                    ]
                ]
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/gfw-imo-matched-uuid*' => Http::response([], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response([], 200),
        ]);

        $result = $this->enrichmentService->enrichVessel($vessel, dryRun: true);

        $this->assertTrue($result['candidate_found']);
        $this->assertEquals('imo_exact_match', $result['match_method']);
        $this->assertEquals('high_authoritative', $result['match_confidence']);
        $this->assertContains('IMO: 9123456', $result['matching_identifiers']);
    }

    public function test_gfw_classifies_unknown_to_cargo_or_pleasure_for_policy_handling(): void
    {
        $cargoVessel = Vessel::create([
            'name' => 'SUSPECT CARRIER',
            'mmsi' => '525443322',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response([
                'total' => 1,
                'entries' => [
                    [
                        'id' => 'gfw-cargo-uuid',
                        'selfReportedInfo' => [
                            ['ssvid' => '525443322', 'shipname' => 'SUSPECT CARRIER']
                        ],
                        'combinedSourcesInfo' => [
                            ['shiptypes' => [['name' => 'cargo']]]
                        ]
                    ]
                ]
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/gfw-cargo-uuid*' => Http::response([], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response([], 200),
        ]);

        $result = $this->enrichmentService->enrichVessel($cargoVessel, dryRun: false);
        $cargoVessel->refresh();

        // Policy rule: GFW cannot be used to classify a vessel as cargo or tanker
        $this->assertEquals('unknown', $cargoVessel->vessel_type);
        $this->assertEquals('enriched', $cargoVessel->enrichment_status);
    }

    public function test_unknown_vessel_remains_unknown_when_no_gfw_match(): void
    {
        $vessel = Vessel::create([
            'name' => 'GHOST VESSEL',
            'mmsi' => '525000001',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
            'enrichment_status' => 'not_enriched',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response([
                'total' => 0,
                'entries' => []
            ], 200),
        ]);

        $result = $this->enrichmentService->enrichVessel($vessel, dryRun: false);
        $vessel->refresh();

        $this->assertEquals('no_match', $result['status']);
        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertEquals('no_match', $vessel->enrichment_status);
        $this->assertNotNull($vessel->last_enriched_at);
    }

    public function test_manually_verified_vessel_classification_never_overwritten(): void
    {
        $vessel = Vessel::create([
            'name' => 'ANALYST VERIFIED CRAFT',
            'mmsi' => '525777888',
            'vessel_type' => 'unknown',
            'verification_status' => 'verified', // Analyst verified
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response([
                'total' => 1,
                'entries' => [
                    [
                        'id' => 'gfw-vessel-verified-test',
                        'selfReportedInfo' => [
                            ['ssvid' => '525777888', 'shipname' => 'ANALYST VERIFIED CRAFT']
                        ],
                        'combinedSourcesInfo' => [
                            ['shiptypes' => [['name' => 'fishing']]]
                        ]
                    ]
                ]
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/gfw-vessel-verified-test*' => Http::response([], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response([], 200),
        ]);

        $result = $this->enrichmentService->enrichVessel($vessel, dryRun: false);
        $vessel->refresh();

        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertArrayHasKey('vessel_type', $result['fields_preserved']);
        $this->assertStringContainsString('verified manual classification', $result['fields_preserved']['vessel_type']['reason']);
    }

    public function test_rate_limit_429_handled_gracefully_in_enrich_vessel_job(): void
    {
        $vessel = Vessel::create([
            'name' => 'RATE LIMIT TEST VESSEL',
            'mmsi' => '525888999',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response('Rate limit exceeded', 429),
        ]);

        $job = new EnrichVesselGfwJob($vessel->id);
        $job->handle($this->enrichmentService);

        $vessel->refresh();
        // Vessel type remains unknown, not marked as no_match or enriched
        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertEquals('not_enriched', $vessel->enrichment_status);
    }

    public function test_duplicate_enrichment_jobs_prevented_by_cache_lock(): void
    {
        $vessel = Vessel::create([
            'name' => 'CONCURRENCY VESSEL',
            'mmsi' => '525333444',
            'vessel_type' => 'unknown',
        ]);

        // Place lock beforehand
        $lock = Cache::lock('gfw_enrich_job_lock_' . $vessel->id, 3600);
        $lock->get();

        Http::fake();

        $job = new EnrichVesselGfwJob($vessel->id);
        $job->handle($this->enrichmentService);

        // Zero HTTP requests sent because lock was held
        Http::assertNothingSent();
    }

    public function test_encounter_detection_does_not_conflate_fishing_and_unknown(): void
    {
        $service = new EncounterDetectionService();

        // Reflection to test protected classifyEncounter
        $method = new \ReflectionMethod(EncounterDetectionService::class, 'classifyEncounter');
        $method->setAccessible(true);

        // Case 1: Both fishing -> fishing_fishing
        $cat1 = $method->invoke($service, 'fishing', 'fishing');
        $this->assertEquals('fishing_fishing', $cat1);

        // Case 2: One fishing, one unknown -> other (NEVER fishing_fishing)
        $cat2 = $method->invoke($service, 'fishing', 'unknown');
        $this->assertEquals('other', $cat2);

        // Case 3: Both unknown -> other
        $cat3 = $method->invoke($service, 'unknown', 'unknown');
        $this->assertEquals('other', $cat3);

        // Case 4: Carrier + fishing -> fishing_carrier
        $cat4 = $method->invoke($service, 'fishing', 'carrier');
        $this->assertEquals('fishing_carrier', $cat4);
    }

    public function test_compliance_engine_exempts_unknown_vessels_from_license_rules(): void
    {
        $unknownVessel = Vessel::create([
            'name' => 'UNCLASSIFIED VESSEL',
            'mmsi' => '525121212',
            'vessel_type' => 'unknown',
        ]);

        $pos = VesselPosition::create([
            'vessel_id' => $unknownVessel->id,
            'latitude' => -3.0,
            'longitude' => 108.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        $rule = ComplianceRule::create([
            'rule_code' => 'TEST_RULE_LICENSE',
            'rule_name' => 'Valid Fishing Authorization Required',
            'jurisdiction' => 'ASEAN-EEZ',
            'category' => 'license_validity',
            'severity' => 'critical',
            'rule_logic' => ['require_active_permit' => true],
            'is_active' => true,
        ]);

        $complianceService = app(ComplianceEngineService::class);
        $method = new \ReflectionMethod(ComplianceEngineService::class, 'checkRule');
        $method->setAccessible(true);

        $checkResult = $method->invoke($complianceService, $rule, $unknownVessel, $pos);

        // Unknown vessels must be exempt from license_validity violations
        $this->assertNull($checkResult);
    }

    public function test_gfw_search_lookup_is_cached_by_identifier(): void
    {
        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::sequence()
                ->push(['total' => 1, 'entries' => [['id' => 'cache-test-uuid', 'combinedSourcesInfo' => [['shiptypes' => [['name' => 'fishing']]]]]]], 200)
                ->push(['total' => 0, 'entries' => []], 200),
        ]);

        // First call - hits HTTP
        $res1 = $this->enrichmentService->querySearchEndpoint('525000999');
        $this->assertEquals(1, $res1['total']);

        // Second call - served from cache
        $res2 = $this->enrichmentService->querySearchEndpoint('525000999');
        $this->assertEquals(1, $res2['total']);

        // Only 1 HTTP call occurred
        Http::assertSentCount(1);
    }
}
