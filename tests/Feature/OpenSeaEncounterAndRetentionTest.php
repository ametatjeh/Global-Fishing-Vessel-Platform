<?php

namespace Tests\Feature;

use App\Models\Port;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\EncounterVessel;
use App\Models\VesselPosition;
use App\Services\EncounterDetectionService;
use App\Services\PortGeofenceService;
use App\Services\DataRetentionPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpenSeaEncounterAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $regularAdmin;
    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'email' => 'superadmin_test@example.com',
            'is_active' => true,
        ]);

        $this->regularAdmin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin_test@example.com',
            'is_active' => true,
        ]);

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'email' => 'analyst_test@example.com',
            'is_active' => true,
        ]);

        // Ensure Jakarta Port exists in test DB with 9260m buffer
        Port::firstOrCreate(
            ['name' => 'JAKARTA', 'country' => 'Indonesia'],
            [
                'wpi_port_id' => 270,
                'port_code' => 'WPI-270',
                'latitude' => -6.10,
                'longitude' => 106.88,
                'port_size' => 'Major',
                'buffer_radius_meters' => 9260,
            ]
        );
    }

    protected function createTestVessel(string $type = 'fishing', ?string $name = null): Vessel
    {
        static $counter = 1000;
        $counter++;

        return Vessel::create([
            'name' => $name ?? "VESSEL-{$counter}",
            'mmsi' => (string) (525100000 + $counter),
            'vessel_type' => $type,
            'verification_status' => 'verified',
        ]);
    }

    public function test_01_two_vessels_in_open_sea_can_form_encounter(): void
    {
        // Two vessels in open sea (Java Sea ~16km offshore: -5.95, 106.88)
        $vesselA = $this->createTestVessel('fishing');
        $vesselB = $this->createTestVessel('fishing');

        $baseTime = Carbon::now()->subHours(2);

        // Position 1 (0 mins) - ~31m apart in open Java Sea (~16km offshore from Jakarta port)
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.9500,
            'longitude' => 106.8800,
            'observed_at' => $baseTime,
            'received_at' => $baseTime,
            'speed_knots' => 4.5,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -5.9502,
            'longitude' => 106.8802,
            'observed_at' => $baseTime,
            'received_at' => $baseTime,
            'speed_knots' => 4.2,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);

        // Position 2 (35 mins later, meeting duration >= 30 mins)
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.9505,
            'longitude' => 106.8805,
            'observed_at' => $baseTime->copy()->addMinutes(35),
            'received_at' => $baseTime->copy()->addMinutes(35),
            'speed_knots' => 4.5,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -5.9507,
            'longitude' => 106.8807,
            'observed_at' => $baseTime->copy()->addMinutes(35),
            'received_at' => $baseTime->copy()->addMinutes(35),
            'speed_knots' => 4.2,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);

        $service = new EncounterDetectionService(maxDistanceMeters: 500.0, minDurationMinutes: 30);
        $result = $service->detectEncounters(
            $baseTime->copy()->subMinutes(10),
            Carbon::now(),
            ['vessel_ids' => [$vesselA->id, $vesselB->id]]
        );

        $this->assertGreaterThanOrEqual(1, $result['detected'], 'Encounter in open sea must be detected');
        $this->assertDatabaseHas('vessel_encounters', [
            'vessel_1_id' => min($vesselA->id, $vesselB->id),
            'vessel_2_id' => max($vesselA->id, $vesselB->id),
            'status' => 'candidate',
        ]);
    }

    public function test_02_two_vessels_inside_port_boundary_or_buffer_are_strictly_rejected(): void
    {
        // Two vessels inside Jakarta port / harbor buffer (-6.07, 106.88 is ~3.3km from port center, inside 9260m buffer)
        $vesselA = $this->createTestVessel('fishing');
        $vesselB = $this->createTestVessel('carrier');

        $baseTime = Carbon::now()->subHours(2);

        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -6.0700,
            'longitude' => 106.8800,
            'observed_at' => $baseTime,
            'received_at' => $baseTime,
            'speed_knots' => 2.0,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -6.0710,
            'longitude' => 106.8810,
            'observed_at' => $baseTime,
            'received_at' => $baseTime,
            'speed_knots' => 2.0,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);

        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -6.0705,
            'longitude' => 106.8805,
            'observed_at' => $baseTime->copy()->addMinutes(40),
            'received_at' => $baseTime->copy()->addMinutes(40),
            'speed_knots' => 2.0,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -6.0715,
            'longitude' => 106.8815,
            'observed_at' => $baseTime->copy()->addMinutes(40),
            'received_at' => $baseTime->copy()->addMinutes(40),
            'speed_knots' => 2.0,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);

        $service = app(EncounterDetectionService::class);
        $result = $service->detectEncounters(
            $baseTime->copy()->subMinutes(10),
            Carbon::now(),
            ['vessel_ids' => [$vesselA->id, $vesselB->id]]
        );

        $this->assertEquals(0, $result['detected'], 'Vessels inside port buffer must NEVER produce an encounter');
        $this->assertDatabaseMissing('vessel_encounters', [
            'vessel_1_id' => min($vesselA->id, $vesselB->id),
            'vessel_2_id' => max($vesselA->id, $vesselB->id),
        ]);
    }

    public function test_03_two_vessels_in_anchorage_with_anchored_status_are_rejected(): void
    {
        $portGeofence = app(PortGeofenceService::class);

        // Even in offshore waters, if both report 'at_anchor' (nav_status 1) or 'moored' (nav_status 5)
        $posA = [
            'latitude' => -5.95,
            'longitude' => 106.88,
            'speed_knots' => 0.1,
            'navigational_status' => 1, // At anchor
        ];
        $posB = [
            'latitude' => -5.951,
            'longitude' => 106.881,
            'speed_knots' => 0.1,
            'navigational_status' => 1, // At anchor
        ];

        $gate = $portGeofence->canFormEncounter($posA, $posB);

        $this->assertFalse($gate['allowed'], 'Vessels at anchor in anchorage must not be permitted to form encounter');
        $this->assertEquals('anchored_nav_status', $gate['reason']);
    }

    public function test_04_vessels_moving_from_port_to_open_sea_only_detect_encounter_once_outside_buffer(): void
    {
        $portGeofence = app(PortGeofenceService::class);

        // Position A inside port buffer (~3.3km from Jakarta port)
        $insideA = ['latitude' => -6.07, 'longitude' => 106.88, 'speed_knots' => 8.0, 'navigational_status' => 0];
        $insideB = ['latitude' => -6.071, 'longitude' => 106.881, 'speed_knots' => 8.0, 'navigational_status' => 0];
        $gateInside = $portGeofence->canFormEncounter($insideA, $insideB);
        $this->assertFalse($gateInside['allowed'], 'Inside port buffer must not form encounter');

        // Position A has now sailed into open sea (~16km from Jakarta port)
        $outsideA = ['latitude' => -5.95, 'longitude' => 106.88, 'speed_knots' => 8.0, 'navigational_status' => 0];
        $outsideB = ['latitude' => -5.951, 'longitude' => 106.881, 'speed_knots' => 8.0, 'navigational_status' => 0];
        $gateOutside = $portGeofence->canFormEncounter($outsideA, $outsideB);
        $this->assertTrue($gateOutside['allowed'], 'Open sea position must be allowed');
    }

    public function test_05_retention_page_displays_actual_encounter_counts_and_empty_status(): void
    {
        // First, when database is empty
        VesselEncounter::query()->delete();

        // Refresh stats cache
        app(DataRetentionPolicyService::class)->getEncounterStorageStatistics(forceFresh: true);

        $response = $this->actingAs($this->superAdmin)->get(route('settings.retention.index'));

        $response->assertOk();
        $response->assertSee('Vessel Encounter Events Retention &amp; Cleanup', false);
        $response->assertSee('STATUS: EMPTY (0 RECORDS)', false);

        // Now create a candidate encounter
        $v1 = $this->createTestVessel('fishing');
        $v2 = $this->createTestVessel('carrier');

        $enc = VesselEncounter::create([
            'encounter_code' => 'ENC-TEST-001',
            'vessel_1_id' => $v1->id,
            'vessel_2_id' => $v2->id,
            'category' => 'fishing_carrier',
            'status' => 'candidate',
            'start_time' => now()->subDay(),
            'end_time' => now()->subDay()->addHour(),
            'duration_minutes' => 60,
            'min_distance_meters' => 200,
            'latitude' => -5.95,
            'longitude' => 106.88,
            'parameters_used' => ['test' => true],
        ]);

        EncounterVessel::create([
            'encounter_id' => $enc->id,
            'vessel_id' => $v1->id,
            'role' => 'participant',
        ]);
        EncounterVessel::create([
            'encounter_id' => $enc->id,
            'vessel_id' => $v2->id,
            'role' => 'carrier',
        ]);

        // Force fresh statistics
        app(DataRetentionPolicyService::class)->getEncounterStorageStatistics(forceFresh: true);

        $responseWithData = $this->actingAs($this->superAdmin)->get(route('settings.retention.index'));
        $responseWithData->assertOk();
        $responseWithData->assertSee('STATUS: 1 ACTIVE RECORDS', false);
        $responseWithData->assertSee('Delete All (1 Records)', false);
    }

    public function test_06_purge_all_encounters_via_controller_deletes_all_records_and_preserves_vessels(): void
    {
        $v1 = $this->createTestVessel('fishing');
        $v2 = $this->createTestVessel('tanker');

        // Create vessel positions
        $pos = VesselPosition::create([
            'vessel_id' => $v1->id,
            'latitude' => -5.95,
            'longitude' => 106.88,
            'observed_at' => now(),
            'received_at' => now(),
            'speed_knots' => 5.0,
            'nav_status' => 'under way using engine',
            'data_quality_score' => 1.0,
        ]);

        $enc = VesselEncounter::create([
            'encounter_code' => 'ENC-PURGE-999',
            'vessel_1_id' => $v1->id,
            'vessel_2_id' => $v2->id,
            'category' => 'fishing_bunker',
            'status' => 'candidate',
            'start_time' => now()->subDays(2),
            'end_time' => now()->subDays(2)->addHours(2),
            'duration_minutes' => 120,
            'min_distance_meters' => 150,
            'latitude' => -5.95,
            'longitude' => 106.88,
            'parameters_used' => ['test' => true],
        ]);

        EncounterVessel::create([
            'encounter_id' => $enc->id,
            'vessel_id' => $v1->id,
            'role' => 'participant',
        ]);

        $this->assertEquals(1, VesselEncounter::count());
        $this->assertEquals(1, EncounterVessel::count());

        // Execute purge via HTTP controller
        $response = $this->actingAs($this->superAdmin)->post(route('settings.retention.encounters.purge'), [
            'confirm_encounter_purge' => 1,
        ]);

        $response->assertRedirect(route('settings.retention.index'));

        // Encounters must be 0
        $this->assertEquals(0, VesselEncounter::count(), 'All vessel encounters must be permanently purged');
        $this->assertEquals(0, EncounterVessel::count(), 'All participant linkages must be purged');

        // Crucial requirement: Vessels and VesselPositions must be PRESERVED
        $this->assertDatabaseHas('vessels', ['id' => $v1->id]);
        $this->assertDatabaseHas('vessels', ['id' => $v2->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    public function test_07_purge_encounters_artisan_command_safely_cleans_records(): void
    {
        $v1 = $this->createTestVessel('fishing');
        $v2 = $this->createTestVessel('cargo');

        VesselEncounter::create([
            'encounter_code' => 'ENC-CLI-1',
            'vessel_1_id' => $v1->id,
            'vessel_2_id' => $v2->id,
            'category' => 'other',
            'status' => 'candidate',
            'start_time' => now()->subDays(5),
            'end_time' => now()->subDays(5)->addHour(),
            'duration_minutes' => 60,
            'min_distance_meters' => 300,
            'latitude' => -5.95,
            'longitude' => 106.88,
            'parameters_used' => ['cli' => true],
        ]);

        $this->artisan('encounters:purge', ['--force' => true])
            ->expectsOutputToContain('Deleted Vessel Encounters')
            ->assertExitCode(0);

        $this->assertEquals(0, VesselEncounter::count());
    }

    public function test_08_regular_admin_cannot_purge_encounters(): void
    {
        $response = $this->actingAs($this->regularAdmin)->post(route('settings.retention.encounters.purge'), [
            'confirm_encounter_purge' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_09_encounter_stats_json_endpoint_returns_live_metrics(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('settings.retention.encounters.stats', ['fresh' => 1]));

        $response->assertOk();
        $response->assertJsonStructure([
            'total_encounters',
            'total_participants',
            'candidate',
            'needs_review',
            'verified',
            'rejected',
            'is_empty',
        ]);
    }

    public function test_10_ports_import_command_loads_official_world_port_index(): void
    {
        $this->artisan('ports:import')
            ->expectsOutputToContain('Total Ports in File')
            ->assertExitCode(0);

        $this->assertGreaterThan(5000, Port::count());
        $this->assertGreaterThan(200, Port::where('country', 'Indonesia')->count());
    }
}
