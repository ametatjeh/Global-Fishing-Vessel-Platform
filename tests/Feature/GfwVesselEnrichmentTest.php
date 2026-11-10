<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Maritime\GfwVesselEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GfwVesselEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected ?DataProvider $gfwProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gfwProvider = DataProvider::firstOrCreate(
            ['slug' => 'global-fishing-watch'],
            [
                'name' => 'Global Fishing Watch (Events API v3)',
                'type' => 'gfw_events',
                'api_url' => 'https://gateway.api.globalfishingwatch.org/v3',
                'auth_type' => 'bearer_token',
                'is_enabled' => true,
            ]
        );

        config(['maritime.providers.gfw.token' => 'test-gfw-token-mock']);
    }

    protected function createTestVessel(array $attributes = []): Vessel
    {
        return Vessel::create(array_merge([
            'name' => 'TEST VESSEL',
            'mmsi' => '123456789',
            'vessel_type' => 'unknown',
            'verification_status' => 'provider_reported',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $attributes));
    }

    protected function mockGfwSearchResponse(array $entries = []): array
    {
        return [
            'limit' => 30,
            'total' => count($entries),
            'entries' => $entries,
        ];
    }

    protected function sampleGfwEntry(): array
    {
        return [
            'dataset' => 'public-global-vessel-identity:v5.0',
            'id' => 'a4534777b-b656-fe37-84db-ebc0fc9c48bd',
            'registryInfo' => [
                [
                    'id' => 'reg-1',
                    'sourceCode' => ['IMO'],
                    'ssvid' => '538070201',
                    'flag' => 'MHL',
                    'shipname' => 'SIRONA3',
                    'callsign' => 'V7JQ8',
                    'imo' => '1007718',
                    'tonnageGt' => 917,
                    'geartypes' => ['PASSENGER'],
                ]
            ],
            'registryOwners' => [
                [
                    'name' => 'NICKEL CORP CAYMAN',
                    'flag' => 'CYM',
                    'ssvid' => '538070201',
                    'sourceCode' => ['IMO'],
                ]
            ],
            'combinedSourcesInfo' => [
                [
                    'vesselId' => 'a4534777b-b656-fe37-84db-ebc0fc9c48bd',
                    'shiptypes' => [
                        ['name' => 'PASSENGER', 'source' => 'SECONDARY_CLASSIFIER_MODEL']
                    ],
                    'bestLengthM' => [
                        ['value' => 48.16, 'source' => 'SECONDARY_CLASSIFIER_MODEL']
                    ],
                    'bestTonnageGt' => [
                        ['value' => 917, 'source' => 'SECONDARY_CLASSIFIER_MODEL']
                    ],
                    'bestEnginePowerKw' => [
                        ['value' => 2566.02, 'source' => 'SECONDARY_CLASSIFIER_MODEL']
                    ],
                ]
            ],
            'selfReportedInfo' => [
                [
                    'id' => 'a4534777b-b656-fe37-84db-ebc0fc9c48bd',
                    'ssvid' => '538070201',
                    'shipname' => '4 ROSES',
                    'flag' => 'MHL',
                    'callsign' => 'V7JQ8',
                    'imo' => '1007718',
                    'sourceCode' => ['AIS'],
                ]
            ]
        ];
    }

    /**
     * 1. GFW vessel search by IMO
     */
    public function test_gfw_vessel_search_by_imo(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*query=1007718*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: false);

        $this->assertTrue($result['candidate_found']);
        $this->assertEquals('imo_exact_match', $result['match_method']);
        $this->assertEquals('a4534777b-b656-fe37-84db-ebc0fc9c48bd', $result['gfw_vessel_id']);
    }

    /**
     * 2. GFW vessel search by MMSI
     */
    public function test_gfw_vessel_search_by_mmsi(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => null,
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*query=538070201*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: false);

        $this->assertTrue($result['candidate_found']);
        $this->assertEquals('mmsi_exact_match', $result['match_method']);
    }

    /**
     * 3. Ambiguous name matching rejected when no identifiers match
     */
    public function test_ambiguous_name_matching_rejected(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'PACIFIC STAR',
            'imo' => null,
            'mmsi' => '999888777',
            'callsign' => 'WXYZ',
            'vessel_type' => 'unknown',
        ]);

        // GFW returns candidate with different MMSI/IMO/callsign
        $unrelatedCandidate = $this->sampleGfwEntry();
        $unrelatedCandidate['selfReportedInfo'][0]['ssvid'] = '123456789';
        $unrelatedCandidate['selfReportedInfo'][0]['imo'] = '9876543';
        $unrelatedCandidate['selfReportedInfo'][0]['callsign'] = 'OTHER';

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*query=999888777*' => Http::response($this->mockGfwSearchResponse([]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*query=PACIFIC+STAR*' => Http::response($this->mockGfwSearchResponse([$unrelatedCandidate]), 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: true);

        $this->assertFalse($result['candidate_found']);
        $this->assertEquals('no_match', $result['status']);
    }

    /**
     * 4. Correct GFW-to-local vessel identity mapping
     */
    public function test_correct_gfw_to_local_vessel_identity_mapping(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
            'tonnage' => null,
            'owner' => null,
            'engine_power_kw' => null,
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('passenger', $vessel->vessel_type);
        $this->assertEquals(917.0, (float) $vessel->tonnage);
        $this->assertEquals(2566.02, (float) $vessel->engine_power_kw);
        $this->assertEquals('NICKEL CORP CAYMAN', $vessel->owner);
        $this->assertEquals('a4534777b-b656-fe37-84db-ebc0fc9c48bd', $vessel->gfw_vessel_id);
    }

    /**
     * 5. Null field preservation (never overwrite valid values with null/empty)
     */
    public function test_null_field_preservation(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'callsign' => 'V7JQ8',
            'flag_state' => 'MH',
            'vessel_type' => 'passenger',
        ]);

        // Candidate with null callsign and flag
        $sparseCandidate = $this->sampleGfwEntry();
        $sparseCandidate['registryInfo'][0]['callsign'] = null;
        $sparseCandidate['registryInfo'][0]['flag'] = null;
        $sparseCandidate['selfReportedInfo'][0]['callsign'] = null;
        $sparseCandidate['selfReportedInfo'][0]['flag'] = null;

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$sparseCandidate]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($sparseCandidate, 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $service->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('V7JQ8', $vessel->callsign);
        $this->assertEquals('MH', $vessel->flag_state);
    }

    /**
     * 6. Verified identity preservation (never overwrite manually verified value)
     */
    public function test_verified_identity_preservation(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'fishing', // Manually verified as fishing
            'verification_status' => 'verified',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $service->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals('verified', $vessel->verification_status);
    }

    /**
     * 7. Conflicting source values recorded for analyst review
     */
    public function test_conflicting_source_values_recorded(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'length_meters' => 56.0, // AIS static reported length
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals(56.0, (float) $vessel->length_meters);
        $this->assertArrayHasKey('length_meters', $result['conflicts']);
        $this->assertEquals('conflict_detected', $vessel->enrichment_status);
    }

    /**
     * 8. HTTP 403 permission handling (does not break identity enrichment)
     */
    public function test_http_403_permission_handling(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            // Port visits 200, encounters 403
            'https://gateway.api.globalfishingwatch.org/v3/events*port-visits*' => Http::response(['total' => 270, 'entries' => []], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*encounters*' => Http::response(['statusCode' => 403, 'error' => 'Forbidden'], 403),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: false);

        $this->assertTrue($result['candidate_found']);
        $this->assertEquals('permission_restricted', $result['events_summary']['encounters']['status']);
        $this->assertEquals(270, $result['events_summary']['port_visits']['count']);
    }

    /**
     * 9. HTTP 429 rate limiting handling
     */
    public function test_http_429_rate_limiting_handled(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'RATE LIMIT VESSEL',
            'imo' => '9999999',
            'mmsi' => '999999999',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response(['statusCode' => 429, 'error' => 'Too Many Requests'], 429),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: true);

        $this->assertFalse($result['candidate_found']);
        $this->assertEquals('no_match', $result['status']);
    }

    /**
     * 10. HTTP 5xx server error handling
     */
    public function test_http_5xx_server_error_handled(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'ERROR VESSEL',
            'imo' => '8888888',
            'mmsi' => '888888888',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response(['statusCode' => 503, 'error' => 'Service Unavailable'], 503),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: true);

        $this->assertFalse($result['candidate_found']);
        $this->assertEquals('no_match', $result['status']);
    }

    /**
     * 11. Idempotent enrichment
     */
    public function test_idempotent_enrichment(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $res1 = $service->enrichVessel($vessel, dryRun: false);
        $res2 = $service->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('passenger', $vessel->vessel_type);
        $this->assertEquals(917.0, (float) $vessel->tonnage);
        $this->assertEquals($res1['gfw_vessel_id'], $res2['gfw_vessel_id']);
    }

    /**
     * 12. Dry-run without database modification
     */
    public function test_dry_run_does_not_modify_database(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
            'tonnage' => null,
            'enrichment_status' => 'not_enriched',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $result = $service->enrichVessel($vessel, dryRun: true);

        $vessel->refresh();
        $this->assertTrue($result['dry_run']);
        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertNull($vessel->tonnage);
        $this->assertEquals('not_enriched', $vessel->enrichment_status);
    }

    /**
     * 13. Enrichment audit trail and provenance recorded
     */
    public function test_enrichment_audit_trail_recorded(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        $service = app(GfwVesselEnrichmentService::class);
        $service->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertNotNull($vessel->metadata['enrichment']);
        $this->assertEquals('global-fishing-watch', $vessel->metadata['enrichment']['provider']);
        $this->assertArrayHasKey('field_provenance', $vessel->metadata['enrichment']);
        $this->assertArrayHasKey('tonnage', $vessel->metadata['enrichment']['field_provenance']);
    }

    /**
     * 14. Vessel detail page displays enriched sections
     */
    public function test_vessel_detail_page_displays_enriched_sections(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'passenger',
            'tonnage' => 917.0,
            'gfw_vessel_id' => 'a4534777b-b656-fe37-84db-ebc0fc9c48bd',
            'enrichment_status' => 'enriched',
            'last_enriched_at' => now(),
            'metadata' => [
                'alternative_names' => ['SIRONA3'],
                'enrichment' => [
                    'events_summary' => [
                        'port_visits' => ['count' => 270, 'status' => 'available'],
                        'loitering' => ['count' => 1, 'status' => 'available'],
                        'encounters' => ['count' => null, 'status' => 'permission_restricted'],
                        'fishing' => ['count' => 0, 'status' => 'available'],
                    ],
                    'field_provenance' => [
                        'tonnage' => ['source' => 'GFW_IMO_REGISTRY', 'confidence' => 'high'],
                    ]
                ]
            ]
        ]);

        $response = $this->actingAs($user)->get(route('vessels.show', $vessel->id));

        $response->assertStatus(200);
        $response->assertSee('4 ROSES');
        $response->assertSee('SIRONA3');
        $response->assertSee('passenger');
        $response->assertSee('917 GT');
        $response->assertSee('a4534777b-b656-fe37-84db-ebc0fc9c48bd');
        $response->assertSee('Enrichment: ENRICHED');
        $response->assertSee('Data Provenance & Traceability', false);
    }

    /**
     * 15. Missing and unavailable field presentation
     */
    public function test_missing_and_unavailable_field_presentation(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = $this->createTestVessel([
            'name' => 'MINIMAL VESSEL',
            'mmsi' => '123456789',
            'vessel_type' => 'unknown',
            'length_meters' => null,
            'beam_meters' => null,
            'tonnage' => null,
            'engine_power_kw' => null,
            'build_year' => null,
            'owner' => null,
            'enrichment_status' => 'not_enriched',
        ]);

        $response = $this->actingAs($user)->get(route('vessels.show', $vessel->id));

        $response->assertStatus(200);
        $response->assertSee('MINIMAL VESSEL');
        $response->assertSee('&lt;not available&gt;', false);
    }

    /**
     * 16. Artisan command dry-run and live execution
     */
    public function test_artisan_command_dry_run_and_live_execution(): void
    {
        $vessel = $this->createTestVessel([
            'name' => '4 ROSES',
            'imo' => '1007718',
            'mmsi' => '538070201',
            'vessel_type' => 'unknown',
        ]);

        Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/vessels/search*' => Http::response($this->mockGfwSearchResponse([$this->sampleGfwEntry()]), 200),
            'https://gateway.api.globalfishingwatch.org/v3/vessels/a4534777b*' => Http::response($this->sampleGfwEntry(), 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => Http::response(['total' => 0, 'entries' => []], 200),
        ]);

        // Dry-run execution
        $this->artisan('gfw:vessels:enrich', [
            '--vessel' => $vessel->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN SIMULATION')
            ->expectsOutputToContain('TARGET RESOLVED: 4 ROSES')
            ->expectsOutputToContain('IMO: 1007718')
            ->assertSuccessful();

        $vessel->refresh();
        $this->assertEquals('unknown', $vessel->vessel_type);

        // Live execution
        $this->artisan('gfw:vessels:enrich', [
            '--vessel' => $vessel->id,
        ])
            ->expectsOutputToContain('LIVE EXECUTION')
            ->expectsOutputToContain('ENRICHMENT APPLIED')
            ->assertSuccessful();

        $vessel->refresh();
        $this->assertEquals('passenger', $vessel->vessel_type);
        $this->assertEquals(917.0, (float) $vessel->tonnage);
    }
}
