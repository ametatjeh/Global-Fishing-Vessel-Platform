<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VesselOwnerOperatorFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_vessel_table_hides_raw_uuid_from_display_but_keeps_profile_link(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'F/V PACIFIC QUEEN',
            'mmsi' => '525000001',
            'imo' => '9000001',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'operator' => 'PT Samudera Bahari',
            'owner' => 'PT Lautan Jaya',
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($user)->get('/vessels');

        $response->assertStatus(200);
        $response->assertSee('F/V PACIFIC QUEEN');
        // Profile link must use the vessel UUID
        $response->assertSee(route('vessels.show', $vessel->id));
        // Raw UUID string should not be displayed as visible column text in table
        $response->assertDontSee("data-col=\"uuid\"");
        $response->assertDontSee("<span class=\"font-mono\">{$vessel->id}</span>", false);
    }

    public function test_vessel_table_displays_operator_and_owner_columns(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        // Vessel with both operator and owner
        $v1 = Vessel::create([
            'name' => 'OCEAN HARVESTER',
            'mmsi' => '525000002',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'operator' => 'PT Perikanan Indonesia',
            'operator_country' => 'IDN',
            'owner' => 'PT Hasil Laut Mandiri',
            'owner_country' => 'IDN',
        ]);

        // Vessel with missing operator and owner
        $v2 = Vessel::create([
            'name' => 'MYSTERY VOYAGER',
            'mmsi' => '525000003',
            'flag_state' => null,
            'vessel_type' => 'fishing',
            'operator' => null,
            'owner' => null,
        ]);

        $response = $this->actingAs($user)->get('/vessels');

        $response->assertStatus(200);
        // Header assertions
        $response->assertSee('OPERATOR');
        $response->assertSee('OWNER');

        // V1 assertions
        $response->assertSee('PT Perikanan Indonesia');
        $response->assertSee('PT Hasil Laut Mandiri');

        // V2 assertions: "Not available" fallback
        $response->assertSee('Not available');
    }

    public function test_vessel_table_displays_flag_with_emoji_and_handles_unknown(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        Vessel::create([
            'name' => 'SPANISH TRAWLER',
            'mmsi' => '224000001',
            'flag_state' => 'ESP',
            'vessel_type' => 'fishing',
        ]);

        Vessel::create([
            'name' => 'NO FLAG DRIFTER',
            'mmsi' => '999000001',
            'flag_state' => null,
            'vessel_type' => 'fishing',
        ]);

        $response = $this->actingAs($user)->get('/vessels');

        $response->assertStatus(200);
        // Spain emoji and country name
        $response->assertSee('🇪🇸');
        $response->assertSee('Spain');
        // Unknown flag state fallback
        $response->assertSee('Unknown');
    }

    public function test_filter_by_flag_country_specific_and_unknown(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $v1 = Vessel::create([
            'name' => 'VESSEL INDO',
            'mmsi' => '525111111',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
        ]);

        $v2 = Vessel::create([
            'name' => 'VESSEL SPAIN',
            'mmsi' => '224111111',
            'flag_state' => 'ESP',
            'vessel_type' => 'fishing',
        ]);

        $v3 = Vessel::create([
            'name' => 'VESSEL NO FLAG',
            'mmsi' => '999111111',
            'flag_state' => null,
            'vessel_type' => 'fishing',
        ]);

        // Filter specific flag state
        $resIdn = $this->actingAs($user)->get('/vessels?flag_country=IDN');
        $resIdn->assertStatus(200);
        $resIdn->assertSee('VESSEL INDO');
        $resIdn->assertDontSee('VESSEL SPAIN');
        $resIdn->assertDontSee('VESSEL NO FLAG');

        // Filter unknown flag state
        $resUnknown = $this->actingAs($user)->get('/vessels?flag_country=unknown');
        $resUnknown->assertStatus(200);
        $resUnknown->assertSee('VESSEL NO FLAG');
        $resUnknown->assertDontSee('VESSEL INDO');
        $resUnknown->assertDontSee('VESSEL SPAIN');
    }

    public function test_filter_by_owner_country_specific_and_unknown(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        Vessel::create([
            'name' => 'VESSEL OWNER CHN',
            'mmsi' => '412000001',
            'owner' => 'China Fisheries Corp',
            'owner_country' => 'CHN',
            'vessel_type' => 'fishing',
        ]);

        Vessel::create([
            'name' => 'VESSEL OWNER ESP',
            'mmsi' => '224000002',
            'owner' => 'Pescanova S.A.',
            'owner_country' => 'ESP',
            'vessel_type' => 'fishing',
        ]);

        Vessel::create([
            'name' => 'VESSEL OWNER UNKNOWN',
            'mmsi' => '333000003',
            'owner' => null,
            'owner_country' => null,
            'vessel_type' => 'fishing',
        ]);

        // Specific owner country
        $resChn = $this->actingAs($user)->get('/vessels?owner_country=CHN');
        $resChn->assertStatus(200);
        $resChn->assertSee('VESSEL OWNER CHN');
        $resChn->assertDontSee('VESSEL OWNER ESP');
        $resChn->assertDontSee('VESSEL OWNER UNKNOWN');

        // Unknown owner country
        $resUnknown = $this->actingAs($user)->get('/vessels?owner_country=unknown');
        $resUnknown->assertStatus(200);
        $resUnknown->assertSee('VESSEL OWNER UNKNOWN');
        $resUnknown->assertDontSee('VESSEL OWNER CHN');
        $resUnknown->assertDontSee('VESSEL OWNER ESP');
    }

    public function test_filter_by_operator_country_specific_and_unknown(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        Vessel::create([
            'name' => 'VESSEL OPERATOR TWN',
            'mmsi' => '416000001',
            'operator' => 'Kaohsiung Marine Ltd',
            'operator_country' => 'TWN',
            'vessel_type' => 'fishing',
        ]);

        Vessel::create([
            'name' => 'VESSEL OPERATOR JPN',
            'mmsi' => '431000001',
            'operator' => 'Nippon Suisan Ltd',
            'operator_country' => 'JPN',
            'vessel_type' => 'fishing',
        ]);

        Vessel::create([
            'name' => 'VESSEL OPERATOR UNKNOWN',
            'mmsi' => '431000002',
            'operator' => null,
            'operator_country' => null,
            'vessel_type' => 'fishing',
        ]);

        // Specific operator country
        $resTwn = $this->actingAs($user)->get('/vessels?operator_country=TWN');
        $resTwn->assertStatus(200);
        $resTwn->assertSee('VESSEL OPERATOR TWN');
        $resTwn->assertDontSee('VESSEL OPERATOR JPN');
        $resTwn->assertDontSee('VESSEL OPERATOR UNKNOWN');

        // Unknown operator country
        $resUnknown = $this->actingAs($user)->get('/vessels?operator_country=unknown');
        $resUnknown->assertStatus(200);
        $resUnknown->assertSee('VESSEL OPERATOR UNKNOWN');
        $resUnknown->assertDontSee('VESSEL OPERATOR TWN');
        $resUnknown->assertDontSee('VESSEL OPERATOR JPN');
    }

    public function test_combined_filters_with_and_condition(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        // Flag: PAN, Owner: CYM, Operator: IDN
        $match = Vessel::create([
            'name' => 'TARGET MULTI MATCH',
            'mmsi' => '355000001',
            'flag_state' => 'PAN',
            'owner' => 'Cayman Holding',
            'owner_country' => 'CYM',
            'operator' => 'Jakarta Operations',
            'operator_country' => 'IDN',
            'vessel_type' => 'fishing',
        ]);

        // Partial match 1: Flag: PAN, Owner: CYM, Operator: TWN (mismatch operator)
        $partial1 = Vessel::create([
            'name' => 'PARTIAL MISMATCH OPERATOR',
            'mmsi' => '355000002',
            'flag_state' => 'PAN',
            'owner' => 'Cayman Holding',
            'owner_country' => 'CYM',
            'operator' => 'Taipei Operations',
            'operator_country' => 'TWN',
            'vessel_type' => 'fishing',
        ]);

        // Partial match 2: Flag: IDN, Owner: CYM, Operator: IDN (mismatch flag)
        $partial2 = Vessel::create([
            'name' => 'PARTIAL MISMATCH FLAG',
            'mmsi' => '525000099',
            'flag_state' => 'IDN',
            'owner' => 'Cayman Holding',
            'owner_country' => 'CYM',
            'operator' => 'Jakarta Operations',
            'operator_country' => 'IDN',
            'vessel_type' => 'fishing',
        ]);

        $response = $this->actingAs($user)->get('/vessels?flag_country=PAN&owner_country=CYM&operator_country=IDN');

        $response->assertStatus(200);
        $response->assertSee('TARGET MULTI MATCH');
        $response->assertDontSee('PARTIAL MISMATCH OPERATOR');
        $response->assertDontSee('PARTIAL MISMATCH FLAG');

        // Check active filters count badge displays 3
        $response->assertSee('3 active filters');
        // Check Reset Filters button is rendered
        $response->assertSee('Reset Filters');
        $response->assertSee(route('vessels.index'));
    }

    public function test_reset_filters_button_and_dropdown_options(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        Vessel::create([
            'name' => 'TEST SHIP',
            'mmsi' => '525123456',
            'flag_state' => 'FRA',
            'owner_country' => 'FRA',
            'operator_country' => 'FRA',
            'vessel_type' => 'fishing',
        ]);

        $response = $this->actingAs($user)->get('/vessels');

        $response->assertStatus(200);
        // Verify Flag Country dropdown is present
        $response->assertSee('All Flags');
        $response->assertSee('Unknown');
        $response->assertSee('France');
        $response->assertSee('All Types');

        // Verify Owner Country & Operator Country filter dropdowns are completely removed from UI
        $response->assertDontSee('Owner Country (Negara Pemilik)');
        $response->assertDontSee('Operator Country (Negara Operator)');
        $response->assertDontSee('Semua Negara Owner');
        $response->assertDontSee('Semua Negara Operator');
        $response->assertDontSee('id="owner_country"', false);
        $response->assertDontSee('id="operator_country"', false);
    }

    public function test_compact_mode_single_line_layout_and_hidden_callsign_and_no_flags(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'PACIFIC VOYAGER',
            'mmsi' => '525999111',
            'imo' => '9991112',
            'callsign' => 'YDA9988',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'operator' => 'PT Pelagic Bahari',
            'operator_country' => 'IDN',
            'owner' => 'PT Nusantara Samudera',
            'owner_country' => 'IDN',
        ]);

        \App\Models\VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => 26.0612,
            'longitude' => -80.1345,
            'speed_knots' => 10.5,
            'course_degrees' => 180.0,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($user)->get('/vessels');

        $response->assertStatus(200);

        // 1. Vessel name is displayed, Callsign text & value are hidden from table
        $response->assertSee('PACIFIC VOYAGER');
        $response->assertDontSee('Callsign:');
        $response->assertDontSee('YDA9988');

        // 2. Operator is displayed without country flag emoji
        $response->assertSee('PT Pelagic Bahari');
        $response->assertDontSee('Negara Operator:');

        // 3. Owner is displayed without country flag emoji
        $response->assertSee('PT Nusantara Samudera');
        $response->assertDontSee('Negara Pemilik:');

        // 4. Coordinates formatted on a single horizontal line under LAST SEEN header
        $response->assertSee('LAST SEEN');
        $response->assertSee('26.06°, -80.13°');

        // 5. Single-line whitespace-nowrap and responsive classes are present
        $response->assertSee('whitespace-nowrap', false);
        $response->assertSee('min-w-[850px]', false);
        $response->assertSee('responsive-table-container', false);
    }

    public function test_detail_and_list_operator_and_owner_values_are_strictly_identical(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        // Test vessel matching the exact UUID and data of 4 ROSES
        $vessel = Vessel::create([
            'id' => '4820a2e2-1e1e-49b7-b5ba-a1d3f5cd90f0',
            'name' => '4 ROSES',
            'mmsi' => '538070201',
            'imo' => '1007718',
            'flag_state' => 'MH',
            'vessel_type' => 'passenger',
            'operator' => null,
            'owner' => 'NICKEL CORP CAYMAN',
            'owner_country' => 'CYM',
            'operator_country' => null,
            'verification_status' => 'provider_reported',
        ]);

        // 1. Check list view
        $listResponse = $this->actingAs($user)->get('/vessels');
        $listResponse->assertStatus(200);
        $listResponse->assertSee('4 ROSES');
        $listResponse->assertSee('NICKEL CORP CAYMAN');
        $listResponse->assertSee('Not available');

        // 2. Check detail view
        $detailResponse = $this->actingAs($user)->get("/vessels/{$vessel->id}");
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('4 ROSES');
        $detailResponse->assertSee('NICKEL CORP CAYMAN');
        $detailResponse->assertSee('Not available');

        // 3. Verify accessors produce identical strings
        $this->assertSame($vessel->display_operator, 'Not available');
        $this->assertSame($vessel->display_owner, 'NICKEL CORP CAYMAN');
    }

    public function test_owner_does_not_fallback_to_operator_when_owner_is_null(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'ISOLATED OPERATOR VESSEL',
            'mmsi' => '525999888',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'operator' => 'Acme Fishing Operations Ltd',
            'operator_country' => 'IDN',
            'owner' => null,
            'owner_country' => null,
        ]);

        // List view
        $listResponse = $this->actingAs($user)->get('/vessels');
        $listResponse->assertStatus(200);
        $listResponse->assertSee('Acme Fishing Operations Ltd');
        $listResponse->assertSee('Not available');

        // Detail view: Owner must NOT take Operator name
        $detailResponse = $this->actingAs($user)->get("/vessels/{$vessel->id}");
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('Acme Fishing Operations Ltd');
        $detailResponse->assertSee('Not available');

        $this->assertSame($vessel->display_operator, 'Acme Fishing Operations Ltd');
        $this->assertSame($vessel->display_owner, 'Not available');
    }

    public function test_vessel_with_both_null_displays_not_available_on_both_pages(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'GHOST DRIFTER',
            'mmsi' => '525000999',
            'flag_state' => null,
            'vessel_type' => 'unknown',
            'operator' => null,
            'owner' => null,
        ]);

        // List view
        $listResponse = $this->actingAs($user)->get('/vessels');
        $listResponse->assertStatus(200);
        $listResponse->assertSee('GHOST DRIFTER');
        $listResponse->assertSee('Not available');

        // Detail view
        $detailResponse = $this->actingAs($user)->get("/vessels/{$vessel->id}");
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('GHOST DRIFTER');
        $detailResponse->assertSee('Not available');

        $this->assertSame($vessel->display_operator, 'Not available');
        $this->assertSame($vessel->display_owner, 'Not available');
    }
}

