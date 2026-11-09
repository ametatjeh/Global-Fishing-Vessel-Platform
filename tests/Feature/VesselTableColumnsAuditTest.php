<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\User;
use App\Models\Vessel;
use App\Services\Maritime\FlagStateService;
use App\Services\Maritime\GfwVesselEnrichmentService;
use App\Services\Maritime\VesselClassificationService;
use App\Services\Providers\AisStreamAdapter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VesselTableColumnsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'analyst']);
    }

    /**
     * A. FLAG Column Audit
     */
    public function test_vessel_kwangmyeong_and_korean_flag_renders_consistently_between_table_and_detail(): void
    {
        $vessel = Vessel::create([
            'id' => '01a1b620-c690-70cc-a0b3-fb030a4cc652',
            'name' => '111KWANGMYEONG',
            'mmsi' => '440111222',
            'flag_state' => 'KR',
            'vessel_type' => 'fishing',
        ]);

        // 1. Table view (/vessels)
        $resTable = $this->actingAs($this->user)->get('/vessels');
        $resTable->assertStatus(200);
        $resTable->assertSee('111KWANGMYEONG');
        $resTable->assertSee('🇰🇷');
        $resTable->assertSee('KR');
        $resTable->assertSee('Korea (Republic of)');

        // 2. Detail view (/vessels/{id})
        $resDetail = $this->actingAs($this->user)->get('/vessels/' . $vessel->id);
        $resDetail->assertStatus(200);
        $resDetail->assertSee('111KWANGMYEONG');
        $resDetail->assertSee('🇰🇷');
        $resDetail->assertSee('KR');
        $resDetail->assertSee('Korea (Republic of)');

        // 3. Consistency: flag info must match perfectly
        $this->assertSame($vessel->flag_info['iso2'], 'KR');
        $this->assertSame($vessel->flag_info['country_name'], 'Korea (Republic of)');
        $this->assertSame($vessel->flag_info['flag_emoji'], '🇰🇷');
    }

    public function test_vessel_with_mmsi_only_resolves_consistently_between_table_and_detail(): void
    {
        $vessel = Vessel::create([
            'name' => 'MMSI ONLY KOREAN VESSEL',
            'mmsi' => '440999888',
            'flag_state' => null,
            'vessel_type' => 'fishing',
        ]);

        $resTable = $this->actingAs($this->user)->get('/vessels');
        $resTable->assertStatus(200);
        $resTable->assertSee('MMSI ONLY KOREAN VESSEL');
        $resTable->assertSee('🇰🇷');
        $resTable->assertSee('KR');
        $resTable->assertSee('Korea (Republic of)');

        $resDetail = $this->actingAs($this->user)->get('/vessels/' . $vessel->id);
        $resDetail->assertStatus(200);
        $resDetail->assertSee('MMSI ONLY KOREAN VESSEL');
        $resDetail->assertSee('🇰🇷');
        $resDetail->assertSee('KR');
        $resDetail->assertSee('Korea (Republic of)');
    }

    public function test_flag_column_renders_correct_emoji_for_valid_country(): void
    {
        $vessel = Vessel::create([
            'name' => 'F/V OCEAN STAR',
            'mmsi' => '431009999',
            'flag_state' => 'JPN',
            'vessel_type' => 'fishing',
        ]);

        $res = $this->actingAs($this->user)->get('/vessels');
        $res->assertStatus(200);
        $res->assertSee('🇯🇵');
        $res->assertSee('JP');
        $res->assertSee('Japan');
    }

    public function test_flag_column_displays_neutral_unknown_when_empty_without_guessing_mmsi_or_name(): void
    {
        // Vessel has no explicit flag and no valid MMSI MID
        $vessel = Vessel::create([
            'name' => 'TRULY UNKNOWN VESSEL',
            'mmsi' => null,
            'flag_state' => null,
            'vessel_type' => 'fishing',
        ]);

        $res = $this->actingAs($this->user)->get('/vessels');
        $res->assertStatus(200);
        $res->assertSee('TRULY UNKNOWN VESSEL');
        $res->assertSee('🌐');
        $res->assertSee('Unknown');
    }

    public function test_flag_column_displays_unknown_for_invalid_code(): void
    {
        $details = FlagStateService::resolveCountryDetails('INVALID_CODE_XYZ');
        $this->assertFalse($details['is_known']);
        $this->assertSame('Unknown', $details['name']);
        $this->assertSame('🌐', $details['flag_emoji']);
    }

    /**
     * B & C. OPERATOR and OWNER Columns Audit
     */
    public function test_operator_and_owner_strictly_identical_between_table_and_detail(): void
    {
        $vessel = Vessel::create([
            'name' => 'F/V CAP SAINT PIERRE',
            'mmsi' => '227001001',
            'flag_state' => 'FRA',
            'vessel_type' => 'fishing',
            'operator' => 'Compagnie Francaise du Thon Oceanique',
            'owner' => 'CFTO Holding SAS',
        ]);

        $tableRes = $this->actingAs($this->user)->get('/vessels');
        $tableRes->assertStatus(200);
        $tableRes->assertSee('Compagnie Francaise du Thon Oceanique');
        $tableRes->assertSee('CFTO Holding SAS');

        $detailRes = $this->actingAs($this->user)->get("/vessels/{$vessel->id}");
        $detailRes->assertStatus(200);
        $detailRes->assertSee('Compagnie Francaise du Thon Oceanique');
        $detailRes->assertSee('CFTO Holding SAS');

        $this->assertSame($vessel->display_operator, 'Compagnie Francaise du Thon Oceanique');
        $this->assertSame($vessel->display_owner, 'CFTO Holding SAS');
    }

    public function test_owner_does_not_fallback_to_operator_when_null(): void
    {
        $vessel = Vessel::create([
            'name' => 'SOLO OPERATOR VESSEL',
            'mmsi' => '525112233',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'operator' => 'PT Samudera Bahari',
            'owner' => null,
        ]);

        $this->assertSame($vessel->display_operator, 'PT Samudera Bahari');
        $this->assertSame($vessel->display_owner, 'Not available');

        $tableRes = $this->actingAs($this->user)->get('/vessels');
        $tableRes->assertStatus(200);
        $tableRes->assertSee('PT Samudera Bahari');
        $tableRes->assertSee('Not available');

        $detailRes = $this->actingAs($this->user)->get("/vessels/{$vessel->id}");
        $detailRes->assertStatus(200);
        $detailRes->assertSee('PT Samudera Bahari');
        $detailRes->assertSee('Not available');
    }

    /**
     * D. VESSEL TYPE Column Audit
     */
    public function test_vessel_type_displays_ais_taxonomy_and_matches_detail(): void
    {
        $vessel = Vessel::create([
            'name' => 'RESCUE SHIP ALPHA',
            'mmsi' => '211999888',
            'flag_state' => 'DEU',
            'vessel_type' => 'sar',
        ]);

        $this->assertSame('Search and Rescue', $vessel->display_vessel_type);

        $tableRes = $this->actingAs($this->user)->get('/vessels');
        $tableRes->assertStatus(200);
        $tableRes->assertSee('Search and Rescue');

        $detailRes = $this->actingAs($this->user)->get("/vessels/{$vessel->id}");
        $detailRes->assertStatus(200);
        $detailRes->assertSee('Search and Rescue');
    }

    public function test_vessel_type_displays_unknown_when_null_or_empty(): void
    {
        $vessel = Vessel::create([
            'name' => 'MYSTERY SHIP',
            'mmsi' => '999888777',
            'flag_state' => null,
            'vessel_type' => 'unknown',
        ]);

        $this->assertSame('Unknown', $vessel->display_vessel_type);

        $tableRes = $this->actingAs($this->user)->get('/vessels');
        $tableRes->assertStatus(200);
        $tableRes->assertSee('Unknown');

        $detailRes = $this->actingAs($this->user)->get("/vessels/{$vessel->id}");
        $detailRes->assertStatus(200);
        $detailRes->assertSee('Unknown');
    }

    public function test_ais_position_without_shiptype_does_not_overwrite_valid_classification(): void
    {
        $vessel = Vessel::create([
            'name' => 'F/V PACIFIC LEADER',
            'mmsi' => '525999111',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'last_seen_at' => now()->subHours(2),
        ]);

        $adapter = app(AisStreamAdapter::class);
        $result = $adapter->ingestPositionMessage([
            'mmsi' => '525999111',
            'vessel_name' => 'F/V PACIFIC LEADER',
            'latitude' => -5.123,
            'longitude' => 106.123,
            'sog' => 8.5,
            'cog' => 120.0,
            'heading' => 120,
            'timestamp' => now()->toIso8601String(),
            // No ship_type provided in position message
        ]);

        $vessel->refresh();
        $this->assertSame('fishing', $vessel->vessel_type);
        $this->assertSame('Fishing', $vessel->display_vessel_type);
    }

    public function test_older_ais_static_message_does_not_overwrite_newer_classification(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // 1. Ingest a newer static message at T = now()
        $newerTime = Carbon::now();
        $adapter->ingestStaticMessage([
            'mmsi' => '525444555',
            'vessel_name' => 'SEASPAN ADVENTURE',
            'ship_type' => 52, // Tug
            'timestamp' => $newerTime->toIso8601String(),
        ]);

        $vessel = Vessel::where('mmsi', '525444555')->firstOrFail();
        $this->assertSame('tug', $vessel->vessel_type);
        $this->assertSame('Tug', $vessel->display_vessel_type);

        // 2. An older static message arrives delayed from yesterday with type 50 (Pilot)
        $olderTime = Carbon::now()->subDays(2);
        $adapter->ingestStaticMessage([
            'mmsi' => '525444555',
            'vessel_name' => 'SEASPAN ADVENTURE',
            'ship_type' => 50, // Pilot
            'timestamp' => $olderTime->toIso8601String(),
        ]);

        $vessel->refresh();
        // Classification must remain 'tug' because the incoming message was older!
        $this->assertSame('tug', $vessel->vessel_type);
        $this->assertSame('Tug', $vessel->display_vessel_type);
    }

    public function test_gfw_cannot_classify_vessel_as_cargo_or_tanker(): void
    {
        $vessel = Vessel::create([
            'name' => 'UNKNOWN TRADER',
            'mmsi' => '563111222',
            'vessel_type' => 'unknown',
        ]);

        $enrichmentService = app(GfwVesselEnrichmentService::class);

        $fakeGfwCandidate = [
            'id' => 'gfw-test-123',
            'shipname' => 'UNKNOWN TRADER',
            'ssvid' => '563111222',
            'combinedSourcesInfo' => [
                [
                    'shiptypes' => [
                        ['name' => 'cargo'], // GFW reports cargo
                    ],
                ],
            ],
        ];

        $reconciled = $enrichmentService->reconcileAndMap($vessel, $fakeGfwCandidate, null, null);

        // Cargo classification from GFW must be rejected and preserved as existing type
        $this->assertArrayHasKey('vessel_type', $reconciled['preserved']);
        $this->assertArrayNotHasKey('vessel_type', $reconciled['updates']);
        $this->assertStringContainsString('prohibited by policy', $reconciled['preserved']['vessel_type']['reason']);
    }

    /**
     * E. IMO Column Specific Audit
     */
    public function test_imo_column_displays_valid_imo_and_synchronizes_with_detail(): void
    {
        $vessel = Vessel::create([
            'name' => 'F/V TAIYO MARU NO. 55',
            'mmsi' => '431001002',
            'imo' => '9201009',
            'flag_state' => 'JPN',
            'vessel_type' => 'fishing',
            'operator' => 'Maruha Nichiro Corp',
            'owner' => 'Maruha Nichiro Corp',
        ]);

        $this->assertSame('9201009', $vessel->display_imo);

        $tableRes = $this->actingAs($this->user)->get('/vessels');
        $tableRes->assertStatus(200);
        $tableRes->assertSee('9201009');

        $detailRes = $this->actingAs($this->user)->get("/vessels/{$vessel->id}");
        $detailRes->assertStatus(200);
        $detailRes->assertSee('9201009');
    }

    public function test_imo_preserves_digits_and_cleans_prefix_without_confusion_with_mmsi(): void
    {
        // Leading zero IMO
        $v1 = Vessel::create([
            'name' => 'VESSEL LEADING ZERO',
            'mmsi' => '224001001',
            'imo' => '0123456',
            'vessel_type' => 'fishing',
        ]);
        $this->assertSame('0123456', $v1->display_imo);

        // IMO with prefix "IMO 9201010"
        $v2 = Vessel::create([
            'name' => 'VESSEL PREFIX IMO',
            'mmsi' => '224001002',
            'imo' => 'IMO 9201010',
            'vessel_type' => 'fishing',
        ]);
        $this->assertSame('9201010', $v2->display_imo);

        // Vessel with empty IMO
        $v3 = Vessel::create([
            'name' => 'VESSEL NO IMO',
            'mmsi' => '224001003',
            'imo' => null,
            'vessel_type' => 'fishing',
        ]);
        $this->assertSame('—', $v3->display_imo);
    }

    public function test_imo_confused_with_mmsi_is_rejected(): void
    {
        // IMO field filled with identical 9-digit MMSI
        $v1 = Vessel::create([
            'name' => 'VESSEL CONFUSED MMSI',
            'mmsi' => '440111222',
            'imo' => '440111222',
            'vessel_type' => 'fishing',
        ]);
        // Rejects MMSI placed in IMO field
        $this->assertSame('—', $v1->display_imo);

        // IMO field with random 9-digit number
        $v2 = Vessel::create([
            'name' => 'VESSEL NINE DIGIT IMO',
            'mmsi' => '440999888',
            'imo' => '999888777',
            'vessel_type' => 'fishing',
        ]);
        $this->assertSame('—', $v2->display_imo);
    }

    /**
     * F. Vessel Type Numeric Code Mapping
     */
    public function test_vessel_type_numeric_code_translates_to_taxonomy_without_number_leak(): void
    {
        $v1 = Vessel::create([
            'name' => 'VESSEL CODE 30',
            'mmsi' => '525000030',
            'vessel_type' => '30',
        ]);
        $this->assertSame('Fishing', $v1->display_vessel_type);

        $v2 = Vessel::create([
            'name' => 'VESSEL CODE 70',
            'mmsi' => '525000070',
            'vessel_type' => '70',
        ]);
        $this->assertSame('Cargo', $v2->display_vessel_type);

        $tableRes = $this->actingAs($this->user)->get('/vessels');
        $tableRes->assertStatus(200);
        $tableRes->assertSee('Fishing');
        $tableRes->assertSee('Cargo');
    }

    /**
     * G. Flag Country Filter Audits
     */
    public function test_flag_country_dropdown_renders_iso2_and_country_name(): void
    {
        Vessel::create([
            'name' => 'KOREAN REEFER',
            'mmsi' => '440111333',
            'flag_state' => 'KOR',
            'vessel_type' => 'carrier',
        ]);

        Vessel::create([
            'name' => 'INDONESIAN TRAWLER',
            'mmsi' => '525222333',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
        ]);

        $res = $this->actingAs($this->user)->get('/vessels');
        $res->assertStatus(200);
        // ISO2 and Country name format in dropdown
        $res->assertSee('KR • Korea (Republic of)');
        $res->assertSee('ID • Indonesia');
    }

    public function test_filter_by_flag_country_kr_returns_only_korean_vessels(): void
    {
        // 1. Vessel with explicit flag_state = 'KOR'
        $kr1 = Vessel::create([
            'name' => 'F/V DONG WON 707',
            'mmsi' => '440001001',
            'flag_state' => 'KOR',
            'vessel_type' => 'fishing',
            'operator' => 'Dongwon Industries Ltd',
            'owner' => 'Dongwon Industries Ltd',
        ]);

        // 2. Vessel with null flag_state, but MMSI starts with 440 (MID for Korea)
        $kr2 = Vessel::create([
            'name' => '111KWANGMYEONG',
            'mmsi' => '440111222',
            'flag_state' => null,
            'vessel_type' => 'fishing',
        ]);

        // 3. Vessel from Indonesia
        $id1 = Vessel::create([
            'name' => 'KM MUTIARA LAUT',
            'mmsi' => '525000001',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
        ]);

        // 4. Vessel from Panama
        $pa1 = Vessel::create([
            'name' => 'F/V ATLANTIC SPIRIT',
            'mmsi' => '351001001',
            'flag_state' => 'PAN',
            'vessel_type' => 'carrier',
        ]);

        // Query with flag_country=KR
        $resKr = $this->actingAs($this->user)->get('/vessels?flag_country=KR');
        $resKr->assertStatus(200);
        $resKr->assertSee('F/V DONG WON 707');
        $resKr->assertSee('111KWANGMYEONG');
        $resKr->assertDontSee('KM MUTIARA LAUT');
        $resKr->assertDontSee('F/V ATLANTIC SPIRIT');

        // Query with legacy flag_state=KOR should also return the exact same Korean vessels
        $resKor = $this->actingAs($this->user)->get('/vessels?flag_country=KOR');
        $resKor->assertStatus(200);
        $resKor->assertSee('F/V DONG WON 707');
        $resKor->assertSee('111KWANGMYEONG');
        $resKor->assertDontSee('KM MUTIARA LAUT');
        $resKor->assertDontSee('F/V ATLANTIC SPIRIT');
    }

    public function test_filter_by_flag_country_preserves_pagination_and_query_string(): void
    {
        // Create 15 Indonesian vessels
        for ($i = 1; $i <= 15; $i++) {
            Vessel::create([
                'name' => sprintf('KM BAHARI %02d', $i),
                'mmsi' => sprintf('525000%03d', $i),
                'flag_state' => 'IDN',
                'vessel_type' => 'fishing',
            ]);
        }

        // Create 2 Spanish vessels
        Vessel::create([
            'name' => 'F/V PLAYA DE BAKIO',
            'mmsi' => '224001001',
            'flag_state' => 'ESP',
            'vessel_type' => 'fishing',
        ]);

        // Request page 2 with per_page=10 and flag_country=ID
        $res = $this->actingAs($this->user)->get('/vessels?flag_country=ID&per_page=10&page=2');
        $res->assertStatus(200);
        $res->assertDontSee('F/V PLAYA DE BAKIO');
        // Pagination link must include flag_country=ID
        $res->assertSee('flag_country=ID');
    }

    public function test_filter_by_flag_country_combines_with_search_and_vessel_type(): void
    {
        Vessel::create([
            'name' => 'F/V DONG WON 707',
            'mmsi' => '440001001',
            'flag_state' => 'KOR',
            'vessel_type' => 'fishing',
        ]);

        Vessel::create([
            'name' => 'F/V DONG WON REEFER',
            'mmsi' => '440001002',
            'flag_state' => 'KOR',
            'vessel_type' => 'carrier',
        ]);

        Vessel::create([
            'name' => 'F/V SAJO COLUMBIA',
            'mmsi' => '440001003',
            'flag_state' => 'KOR',
            'vessel_type' => 'fishing',
        ]);

        // Combined: flag_country=KR + vessel_type=fishing + search=DONG
        $res = $this->actingAs($this->user)->get('/vessels?flag_country=KR&vessel_type=fishing&search=DONG');
        $res->assertStatus(200);
        $res->assertSee('F/V DONG WON 707');
        $res->assertDontSee('F/V DONG WON REEFER'); // Wrong type (carrier)
        $res->assertDontSee('F/V SAJO COLUMBIA'); // Wrong search (SAJO)
    }

    public function test_vessels_pagination_per_page_options(): void
    {
        // Generate 35 vessels
        for ($i = 1; $i <= 35; $i++) {
            Vessel::create([
                'name' => sprintf('VESSEL_%03d', $i),
                'mmsi' => sprintf('52500%04d', $i),
                'flag_state' => 'IDN',
                'vessel_type' => 'fishing',
            ]);
        }

        // Test per_page = 10
        $res10 = $this->actingAs($this->user)->get('/vessels?per_page=10');
        $res10->assertOk();
        $this->assertCount(10, $res10->viewData('vessels'));
        $res10->assertSee('value="10" selected', false);

        // Test per_page = 25
        $res25 = $this->actingAs($this->user)->get('/vessels?per_page=25');
        $res25->assertOk();
        $this->assertCount(25, $res25->viewData('vessels'));
        $res25->assertSee('value="25" selected', false);

        // Test per_page = 50 (should show all 35 without page 2)
        $res50 = $this->actingAs($this->user)->get('/vessels?per_page=50');
        $res50->assertOk();
        $this->assertCount(35, $res50->viewData('vessels'));
        $res50->assertSee('value="50" selected', false);

        // Test per_page = 100
        $res100 = $this->actingAs($this->user)->get('/vessels?per_page=100');
        $res100->assertOk();
        $this->assertCount(35, $res100->viewData('vessels'));
        $res100->assertSee('value="100" selected', false);

        // Test per_page = all (Semua)
        $resAll = $this->actingAs($this->user)->get('/vessels?per_page=all');
        $resAll->assertOk();
        $this->assertCount(35, $resAll->viewData('vessels'));
        $resAll->assertSee('value="all" selected', false);
        $resAll->assertSee('Semua');

        // Test per_page = semua (alias)
        $resSemua = $this->actingAs($this->user)->get('/vessels?per_page=semua');
        $resSemua->assertOk();
        $this->assertCount(35, $resSemua->viewData('vessels'));
        $resSemua->assertSee('value="all" selected', false);
    }
}
