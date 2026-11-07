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
}
