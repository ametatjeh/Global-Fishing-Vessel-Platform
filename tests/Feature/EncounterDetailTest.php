<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EncounterDetailTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;
    protected Vessel $vessel1;
    protected Vessel $vessel2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);

        $this->vessel1 = Vessel::create([
            'id' => (string) Str::uuid(),
            'name' => 'KM SAMUDRA JAYA 01',
            'mmsi' => '525111001',
            'imo' => '9111001',
            'vessel_type' => 'fishing',
            'flag_state' => 'ID',
            'callsign' => 'YDA1001',
        ]);

        $this->vessel2 = Vessel::create([
            'id' => (string) Str::uuid(),
            'name' => 'MV OCEAN CARRIER 02',
            'mmsi' => '525111002',
            'imo' => '9111002',
            'vessel_type' => 'cargo',
            'flag_state' => 'PA',
            'callsign' => 'HP1002',
        ]);
    }

    public function test_encounter_detail_page_with_two_valid_vessels_returns_http_200(): void
    {
        $encounter = VesselEncounter::create([
            'id' => (string) Str::uuid(),
            'encounter_code' => 'ENC-TEST-001',
            'vessel_1_id' => $this->vessel1->id,
            'vessel_2_id' => $this->vessel2->id,
            'category' => 'fishing_carrier',
            'status' => 'needs_review',
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'duration_minutes' => 60,
            'min_distance_meters' => 125.50,
            'median_distance_meters' => 180.00,
            'latitude' => 5.1234567,
            'longitude' => 106.1234567,
            'detection_method' => 'native_ais',
            'algorithm_version' => 'v2.1',
            'parameters_used' => ['max_distance_meters' => 500],
        ]);

        $response = $this->actingAs($this->analyst)->get(route('encounters.show', $encounter->id));

        $response->assertStatus(200);
        $response->assertSee('ENC-TEST-001');
        $response->assertSee('KM SAMUDRA JAYA 01');
        $response->assertSee('MV OCEAN CARRIER 02');

        // Verify explicit named parameter link for vessel 1 and vessel 2
        $expectedUrl1 = route('vessels.show', ['id' => $this->vessel1->id]);
        $expectedUrl2 = route('vessels.show', ['id' => $this->vessel2->id]);

        $response->assertSee($expectedUrl1, false);
        $response->assertSee($expectedUrl2, false);
        $response->assertSee('View Vessel Profile &rarr;', false);
    }

    public function test_encounter_detail_page_handles_missing_or_unlinked_vessel_gracefully(): void
    {
        $encounter = VesselEncounter::create([
            'id' => (string) Str::uuid(),
            'encounter_code' => 'ENC-UNLINKED-002',
            'vessel_1_id' => $this->vessel1->id,
            'vessel_2_id' => $this->vessel2->id,
            'category' => 'other',
            'status' => 'candidate',
            'start_time' => now()->subHours(3),
            'end_time' => now()->subHours(2),
            'duration_minutes' => 60,
            'min_distance_meters' => 300.00,
            'latitude' => 4.5,
            'longitude' => 105.5,
            'detection_method' => 'gfw_events',
            'parameters_used' => [],
        ]);

        // Simulate missing vessel2 relation and null vessel ID
        $encounter->setRelation('vessel2', null);
        $encounter->vessel_2_id = null;

        $view = $this->actingAs($this->analyst)->view('encounters.show', ['encounter' => $encounter]);

        // Must render cleanly without throwing UrlGenerationException
        $view->assertSee('ENC-UNLINKED-002');
        $view->assertSee('KM SAMUDRA JAYA 01');
        $view->assertSee('Vessel profile unavailable');

        $expectedUrl1 = route('vessels.show', ['id' => $this->vessel1->id]);
        $view->assertSee($expectedUrl1, false);
    }

    public function test_encounter_detail_displays_fallback_when_vessel_id_is_empty(): void
    {
        $encounter = VesselEncounter::create([
            'id' => (string) Str::uuid(),
            'encounter_code' => 'ENC-EMPTY-003',
            'vessel_1_id' => $this->vessel1->id,
            'vessel_2_id' => $this->vessel2->id,
            'category' => 'other',
            'status' => 'candidate',
            'start_time' => now()->subHours(1),
            'end_time' => now(),
            'duration_minutes' => 60,
            'min_distance_meters' => 200.00,
            'latitude' => 6.0,
            'longitude' => 107.0,
            'parameters_used' => [],
        ]);

        // Manually unset vessel_2_id on the model instance and render view directly or test fallback
        $encounter->vessel_2_id = null;
        $encounter->setRelation('vessel2', null);

        $view = $this->actingAs($this->analyst)->view('encounters.show', ['encounter' => $encounter]);

        $view->assertSee('Vessel profile unavailable');
        $view->assertSee(route('vessels.show', ['id' => $this->vessel1->id]), false);
    }

    public function test_non_existent_encounter_returns_http_404(): void
    {
        $randomUuid = (string) Str::uuid();
        $response = $this->actingAs($this->analyst)->get(route('encounters.show', $randomUuid));

        $response->assertStatus(404);
    }

    public function test_vessel_encounter_model_accessors_support_both_naming_conventions(): void
    {
        $encounter = new VesselEncounter([
            'vessel_1_id' => $this->vessel1->id,
            'vessel_2_id' => $this->vessel2->id,
        ]);

        $this->assertEquals($this->vessel1->id, $encounter->vessel_1_id);
        $this->assertEquals($this->vessel2->id, $encounter->vessel_2_id);
        $this->assertEquals($this->vessel1->id, $encounter->vessel1_id);
        $this->assertEquals($this->vessel2->id, $encounter->vessel2_id);
    }
}
