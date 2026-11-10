<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VesselRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_vessel_registry_lists_registered_vessels(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'F/V PACIFIC PIONEER',
            'mmsi' => '525098765',
            'imo' => '9876543',
            'callsign' => 'YDA8899',
            'flag_state' => 'IDN',
            'vessel_type' => 'fishing',
            'length_meters' => 54.5,
            'tonnage' => 450.0,
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($user)->get('/vessels');

        $response->assertStatus(200);
        $response->assertSee('PACIFIC PIONEER');
        $response->assertSee('525098765');
        $response->assertSee('9876543');
    }

    public function test_vessel_detail_view_shows_complete_profile_and_provenance(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'F/V ATLANTIC RUNNER',
            'mmsi' => '224112233',
            'imo' => '9112233',
            'callsign' => 'EAA123',
            'flag_state' => 'ESP',
            'vessel_type' => 'fishing',
            'operator' => 'Atlantic Pelagic Fisheries Ltd',
            'verification_status' => 'verified',
        ]);

        VesselIdentifier::create([
            'vessel_id' => $vessel->id,
            'identifier_type' => 'mmsi',
            'identifier_value' => '224112233',
            'valid_from' => now()->subYears(2),
        ]);

        $response = $this->actingAs($user)->get("/vessels/{$vessel->id}");

        $response->assertStatus(200);
        $response->assertSee('ATLANTIC RUNNER');
        $response->assertSee('Atlantic Pelagic Fisheries Ltd');
    }

    public function test_vessel_search_filters_by_mmsi_or_name(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        Vessel::create(['name' => 'ALPHA SEARCHER', 'mmsi' => '111222333']);
        Vessel::create(['name' => 'BETA CARRIER', 'mmsi' => '444555666']);

        $response = $this->actingAs($user)->get('/vessels?search=ALPHA');

        $response->assertStatus(200);
        $response->assertSee('ALPHA SEARCHER');
        $response->assertDontSee('BETA CARRIER');
    }

    public function test_vessel_registry_renders_custom_pagination(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        for ($i = 1; $i <= 55; $i++) {
            Vessel::create([
                'name' => sprintf('VESSEL %03d', $i),
                'mmsi' => sprintf('525%06d', $i),
            ]);
        }

        $response = $this->actingAs($user)->get('/vessels');

        $response->assertStatus(200);
        $response->assertSee('Show');
        $response->assertSee('rows per page');
        $response->assertSee('Showing');
        $response->assertSee('vessels');
        $response->assertSee('vessels?page=2');
    }

    public function test_vessel_registry_displays_row_numbers_sequentially_across_pagination(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        for ($i = 1; $i <= 55; $i++) {
            Vessel::create([
                'name' => sprintf('VESSEL %03d', $i),
                'mmsi' => sprintf('525%06d', $i),
            ]);
        }

        // Page 1: verify 'No.' header exists, starts at 1, and reaches 50
        $responsePage1 = $this->actingAs($user)->get('/vessels?page=1');
        $responsePage1->assertStatus(200);
        $responsePage1->assertSee('No.');
        $responsePage1->assertSeeInOrder([
            'No.',
            'VESSEL',
            '1',
            'VESSEL 001',
            '50',
            'VESSEL 050',
        ]);

        // Page 2: numbers continue from 51 to 55
        $responsePage2 = $this->actingAs($user)->get('/vessels?page=2');
        $responsePage2->assertStatus(200);
        $responsePage2->assertSeeInOrder([
            'No.',
            'VESSEL',
            '51',
            'VESSEL 051',
            '55',
            'VESSEL 055',
        ]);
    }

    public function test_vessel_registry_row_numbers_with_search_filter_and_empty_state(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        Vessel::create(['name' => 'KAPAL PATROLI 01', 'mmsi' => '111000001']);
        Vessel::create(['name' => 'KAPAL PATROLI 02', 'mmsi' => '111000002']);
        Vessel::create(['name' => 'KAPAL LOGISTIK', 'mmsi' => '222000001']);

        // Search returns 2 records, row numbers are 1 and 2
        $responseSearch = $this->actingAs($user)->get('/vessels?search=PATROLI');
        $responseSearch->assertStatus(200);
        $responseSearch->assertSeeInOrder([
            '1',
            'KAPAL PATROLI 01',
            '2',
            'KAPAL PATROLI 02',
        ]);
        $responseSearch->assertDontSee('KAPAL LOGISTIK');

        // Empty result state handles table safely without error
        $responseEmpty = $this->actingAs($user)->get('/vessels?search=TIDAK_ADA');
        $responseEmpty->assertStatus(200);
        $responseEmpty->assertSee('Belum ada kapal yang terdaftar dalam database registry saat ini.');
    }
}
