<?php

namespace Tests\Feature;

use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselPosition;
use App\Services\EncounterDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EncounterDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_haversine_proximity_detects_encounter_between_two_vessels(): void
    {
        $vesselA = Vessel::create([
            'name' => 'F/V OCEAN STAR',
            'mmsi' => '525000001',
            'vessel_type' => 'Trawler',
            'flag_state' => 'IDN',
        ]);

        $vesselB = Vessel::create([
            'name' => 'REEFER CARRIER 99',
            'mmsi' => '525000002',
            'vessel_type' => 'Reefer',
            'flag_state' => 'PAN',
        ]);

        $startTime = Carbon::parse('2026-10-19 10:00:00');
        $endTime = Carbon::parse('2026-10-19 12:00:00');

        // Vessel A position 1 (10:05)
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.9000,
            'longitude' => 106.8000,
            'speed_knots' => 1.2,
            'course_degrees' => 45.0,
            'observed_at' => Carbon::parse('2026-10-19 10:05:00'),
            'received_at' => Carbon::parse('2026-10-19 10:05:05'),
            'data_quality_score' => 1.0,
        ]);

        // Vessel A position 2 (10:40) - 35 mins later
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.9005,
            'longitude' => 106.8005,
            'speed_knots' => 1.1,
            'course_degrees' => 46.0,
            'observed_at' => Carbon::parse('2026-10-19 10:40:00'),
            'received_at' => Carbon::parse('2026-10-19 10:40:05'),
            'data_quality_score' => 1.0,
        ]);

        // Vessel B position 1 within ~150 meters (10:06)
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -5.9010,
            'longitude' => 106.8010,
            'speed_knots' => 1.0,
            'course_degrees' => 50.0,
            'observed_at' => Carbon::parse('2026-10-19 10:06:00'),
            'received_at' => Carbon::parse('2026-10-19 10:06:05'),
            'data_quality_score' => 1.0,
        ]);

        // Vessel B position 2 within ~150 meters (10:41)
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -5.9015,
            'longitude' => 106.8012,
            'speed_knots' => 0.9,
            'course_degrees' => 48.0,
            'observed_at' => Carbon::parse('2026-10-19 10:41:00'),
            'received_at' => Carbon::parse('2026-10-19 10:41:05'),
            'data_quality_score' => 1.0,
        ]);

        // Service with 500m max distance, 30m min duration, 10m tolerance
        $service = new EncounterDetectionService(
            maxDistanceMeters: 500.0,
            minDurationMinutes: 30,
            timestampToleranceMinutes: 10
        );

        $result = $service->detectEncounters($startTime, $endTime);

        $this->assertEquals(1, $result['detected']);
        $this->assertDatabaseHas('vessel_encounters', [
            'status' => 'candidate',
            'detection_method' => 'native_ais',
        ]);

        $encounter = VesselEncounter::first();
        $this->assertNotNull($encounter);
        $this->assertLessThanOrEqual(500, $encounter->min_distance_meters);
        $this->assertGreaterThanOrEqual(30, $encounter->duration_minutes);
    }

    public function test_vessels_far_apart_do_not_generate_encounters(): void
    {
        $vesselA = Vessel::create([
            'name' => 'F/V PACIFIC 1',
            'mmsi' => '525000010',
            'vessel_type' => 'Longliner',
            'flag_state' => 'IDN',
        ]);

        $vesselB = Vessel::create([
            'name' => 'F/V PACIFIC 2',
            'mmsi' => '525000020',
            'vessel_type' => 'Trawler',
            'flag_state' => 'MYS',
        ]);

        $startTime = Carbon::parse('2026-10-19 10:00:00');
        $endTime = Carbon::parse('2026-10-19 12:00:00');

        // Vessel A: -5.0, 105.0
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.0000,
            'longitude' => 105.0000,
            'observed_at' => Carbon::parse('2026-10-19 10:10:00'),
            'received_at' => Carbon::parse('2026-10-19 10:10:00'),
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.0100,
            'longitude' => 105.0100,
            'observed_at' => Carbon::parse('2026-10-19 10:50:00'),
            'received_at' => Carbon::parse('2026-10-19 10:50:00'),
            'data_quality_score' => 1.0,
        ]);

        // Vessel B: -6.0, 107.0 (> 200 km away)
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -6.0000,
            'longitude' => 107.0000,
            'observed_at' => Carbon::parse('2026-10-19 10:10:00'),
            'received_at' => Carbon::parse('2026-10-19 10:10:00'),
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -6.0100,
            'longitude' => 107.0100,
            'observed_at' => Carbon::parse('2026-10-19 10:50:00'),
            'received_at' => Carbon::parse('2026-10-19 10:50:00'),
            'data_quality_score' => 1.0,
        ]);

        $service = new EncounterDetectionService();
        $result = $service->detectEncounters($startTime, $endTime);

        $this->assertEquals(0, $result['detected']);
        $this->assertDatabaseCount('vessel_encounters', 0);
    }

    public function test_encounter_deduplication_prevents_duplicate_records(): void
    {
        $vesselA = Vessel::create(['name' => 'V1', 'mmsi' => '111111111']);
        $vesselB = Vessel::create(['name' => 'V2', 'mmsi' => '222222222']);

        $startTime = Carbon::parse('2026-10-19 10:00:00');
        $endTime = Carbon::parse('2026-10-19 12:00:00');

        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.9000,
            'longitude' => 106.8000,
            'observed_at' => Carbon::parse('2026-10-19 10:00:00'),
            'received_at' => Carbon::parse('2026-10-19 10:00:00'),
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.9001,
            'longitude' => 106.8001,
            'observed_at' => Carbon::parse('2026-10-19 10:35:00'),
            'received_at' => Carbon::parse('2026-10-19 10:35:00'),
            'data_quality_score' => 1.0,
        ]);

        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -5.9010,
            'longitude' => 106.8010,
            'observed_at' => Carbon::parse('2026-10-19 10:01:00'),
            'received_at' => Carbon::parse('2026-10-19 10:01:00'),
            'data_quality_score' => 1.0,
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -5.9011,
            'longitude' => 106.8011,
            'observed_at' => Carbon::parse('2026-10-19 10:36:00'),
            'received_at' => Carbon::parse('2026-10-19 10:36:00'),
            'data_quality_score' => 1.0,
        ]);

        $service = new EncounterDetectionService(
            maxDistanceMeters: 500.0,
            minDurationMinutes: 30,
            timestampToleranceMinutes: 10
        );

        // Run 1
        $res1 = $service->detectEncounters($startTime, $endTime);
        $totalInDbAfterRun1 = VesselEncounter::count();

        // Run 2 (duplicate execution)
        $res2 = $service->detectEncounters($startTime, $endTime);
        $totalInDbAfterRun2 = VesselEncounter::count();

        $this->assertEquals(1, $res1['detected']);
        $this->assertEquals(0, $res2['detected']);
        $this->assertEquals(1, $res2['skipped']);
        $this->assertEquals(1, $totalInDbAfterRun1);
        $this->assertEquals(1, $totalInDbAfterRun2);
    }
}
