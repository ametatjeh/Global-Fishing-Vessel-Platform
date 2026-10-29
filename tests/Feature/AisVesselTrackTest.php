<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AisVesselTrackTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Maritime Operations Analyst',
        ]);
    }

    public function test_vessel_track_api_returns_404_for_unknown_vessel(): void
    {
        $response = $this->actingAs($this->analyst)
            ->getJson('/api/vessels/non-existent-uuid/track');

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_vessel_track_api_returns_empty_when_no_positions(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM SAMUDRA 01',
            'mmsi' => '525123456',
        ]);

        $response = $this->actingAs($this->analyst)
            ->getJson("/api/vessels/{$vessel->id}/track");

        $response->assertStatus(200)
            ->assertJson([
                'type' => 'FeatureCollection',
                'features' => [],
                'properties' => [
                    'vessel_id' => $vessel->id,
                    'mmsi' => '525123456',
                    'track_status' => 'empty',
                    'message' => 'No historical AIS track available',
                    'position_count' => 0,
                ],
            ]);
    }

    public function test_vessel_track_api_returns_single_point_when_one_position(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM BAHARI 02',
            'mmsi' => '525654321',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.1234,
            'longitude' => 106.5678,
            'speed_knots' => 8.5,
            'course_degrees' => 120.0,
            'heading_degrees' => 122.0,
            'observed_at' => now()->subMinutes(15),
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->analyst)
            ->getJson("/api/vessels/{$vessel->id}/track");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertEquals('single_point', $data['properties']['track_status']);
        $this->assertEquals(1, $data['properties']['position_count']);
        $this->assertCount(1, $data['features']);
        $this->assertEquals('Point', $data['features'][0]['geometry']['type']);
        $this->assertEquals([106.5678, -5.1234], $data['features'][0]['geometry']['coordinates']);
        $this->assertTrue($data['features'][0]['properties']['is_latest']);
    }

    public function test_vessel_track_api_returns_linestring_and_points_when_multiple_positions(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM NUSANTARA 03',
            'mmsi' => '525999888',
        ]);

        // Position 1: oldest
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -6.0000,
            'longitude' => 105.0000,
            'speed_knots' => 10.0,
            'course_degrees' => 45.0,
            'observed_at' => now()->subHours(3),
            'received_at' => now(),
        ]);

        // Position 2: intermediate
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.5000,
            'longitude' => 105.5000,
            'speed_knots' => 10.2,
            'course_degrees' => 50.0,
            'observed_at' => now()->subHours(2),
            'received_at' => now(),
        ]);

        // Position 3: newest
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -5.0000,
            'longitude' => 106.0000,
            'speed_knots' => 10.5,
            'course_degrees' => 55.0,
            'observed_at' => now()->subHours(1),
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->analyst)
            ->getJson("/api/vessels/{$vessel->id}/track");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertEquals('available', $data['properties']['track_status']);
        $this->assertEquals(3, $data['properties']['position_count']);

        // First feature must be LineString
        $lineFeature = $data['features'][0];
        $this->assertEquals('LineString', $lineFeature['geometry']['type']);
        $this->assertEquals('ais_vessel_track_line', $lineFeature['properties']['feature_type']);
        $this->assertCount(3, $lineFeature['geometry']['coordinates']);
        $this->assertEquals([105.0, -6.0], $lineFeature['geometry']['coordinates'][0]);
        $this->assertEquals([105.5, -5.5], $lineFeature['geometry']['coordinates'][1]);
        $this->assertEquals([106.0, -5.0], $lineFeature['geometry']['coordinates'][2]);

        // Followed by 3 Point observation features
        $this->assertCount(4, $data['features']); // 1 line + 3 points
        $this->assertFalse($data['features'][1]['properties']['is_latest']);
        $this->assertFalse($data['features'][2]['properties']['is_latest']);
        $this->assertTrue($data['features'][3]['properties']['is_latest']);
    }

    public function test_vessel_track_does_not_connect_different_vessels(): void
    {
        $vesselA = Vessel::create(['name' => 'Vessel A', 'mmsi' => '111111111']);
        $vesselB = Vessel::create(['name' => 'Vessel B', 'mmsi' => '222222222']);

        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -6.0,
            'longitude' => 105.0,
            'observed_at' => now()->subHours(2),
            'received_at' => now(),
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.0,
            'longitude' => 106.0,
            'observed_at' => now()->subHour(),
            'received_at' => now(),
        ]);

        // Vessel B positions
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => 0.0,
            'longitude' => 120.0,
            'observed_at' => now()->subMinutes(30),
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->analyst)
            ->getJson("/api/vessels/{$vesselA->id}/track");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(2, $data['properties']['position_count']);
        $lineFeature = $data['features'][0];
        $this->assertCount(2, $lineFeature['geometry']['coordinates']);
        $this->assertEquals('111111111', $lineFeature['properties']['mmsi']);
    }

    public function test_vessel_track_query_by_mmsi_works(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM CAKRAWALA',
            'mmsi' => '525333444',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -4.0,
            'longitude' => 110.0,
            'observed_at' => now()->subHour(),
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->analyst)
            ->getJson('/api/vessels/525333444/track');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('525333444', $data['properties']['mmsi']);
        $this->assertEquals(1, $data['properties']['position_count']);
    }

    public function test_vessel_track_handles_invalid_coordinates_and_duplicate_timestamps(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM SENTOSA',
            'mmsi' => '525777888',
        ]);

        $time1 = now()->subHours(3);
        $time2 = now()->subHours(2);

        // Valid 1
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -7.0,
            'longitude' => 112.0,
            'observed_at' => $time1,
            'received_at' => now(),
        ]);

        // Duplicate timestamp for time1 - should be handled gracefully
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -7.001,
            'longitude' => 112.001,
            'observed_at' => $time1,
            'received_at' => now(),
        ]);

        // Invalid coords (out of bounds)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => 999.0,
            'longitude' => 999.0,
            'observed_at' => now()->subHours(2)->subMinutes(30),
            'received_at' => now(),
        ]);

        // Valid 2
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -6.5,
            'longitude' => 112.5,
            'observed_at' => $time2,
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->analyst)
            ->getJson("/api/vessels/{$vessel->id}/track");

        $response->assertStatus(200);
        $data = $response->json();

        // Should cleanly contain 2 valid positions (deduplicated timestamp and filtered out of bounds)
        $this->assertEquals('available', $data['properties']['track_status']);
        $this->assertEquals(2, $data['properties']['position_count']);
        $this->assertEquals('LineString', $data['features'][0]['geometry']['type']);
        $this->assertCount(2, $data['features'][0]['geometry']['coordinates']);
    }
}
