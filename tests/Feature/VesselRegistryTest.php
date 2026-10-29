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
        $response->assertSee('Tampilkan');
        $response->assertSee('baris per halaman');
        $response->assertSee('Menampilkan');
        $response->assertSee('hasil');
        $response->assertSee('vessels?page=2');
    }
}
