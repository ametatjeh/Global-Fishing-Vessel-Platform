<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DynamicViewportVesselLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected SpatialQueryService $spatialService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);

        $this->spatialService = app(SpatialQueryService::class);
    }

    public function test_global_mode_enforces_3000_max_cap_and_prioritizes_latest_ais(): void
    {
        $now = Carbon::now();
        $vesselsData = [];
        $positionsData = [];

        // Create 3,200 vessels globally
        for ($i = 1; $i <= 3200; $i++) {
            $vId = (string) Str::uuid();
            $obsTime = $now->copy()->subMinutes($i);

            $vesselsData[] = [
                'id' => $vId,
                'name' => "Global Vessel {$i}",
                'mmsi' => sprintf('5251%05d', $i),
                'vessel_type' => 'fishing',
                'flag_state' => 'IDN',
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];

            $positionsData[] = [
                'id' => (string) Str::uuid(),
                'vessel_id' => $vId,
                'latitude' => -10.0 + (($i % 100) * 0.1),
                'longitude' => 100.0 + (($i % 100) * 0.1),
                'observed_at' => $obsTime,
                'received_at' => $obsTime,
                'is_on_land' => false,
                'data_quality_score' => 1.0,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($vesselsData, 1000) as $chunk) {
            Vessel::insert($chunk);
        }
        foreach (array_chunk($positionsData, 1000) as $chunk) {
            VesselPosition::insert($chunk);
        }

        // Test with zoom <= 3.0 (Global mode)
        $response = $this->actingAs($this->user)->getJson('/api/layers/vessels?zoom=2.5');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals('global', $data['metadata']['mode']);
        $this->assertEquals(3000, $data['metadata']['count']);
        $this->assertEquals(3000, $data['metadata']['limit']);
        $this->assertCount(3000, $data['features']);
        $this->assertFalse($data['metadata']['has_more']);
    }

    public function test_viewport_mode_loads_2900_vessels_across_batches_without_global_cap(): void
    {
        $now = Carbon::now();
        $vesselsData = [];
        $positionsData = [];

        // Insert exactly 2,900 vessels inside viewport [95.0, -10.0, 105.0, 0.0]
        for ($i = 1; $i <= 2900; $i++) {
            $vId = (string) Str::uuid();
            $obsTime = $now->copy()->subSeconds($i * 5);

            $vesselsData[] = [
                'id' => $vId,
                'name' => "Sunda Strait Vessel {$i}",
                'mmsi' => sprintf('5252%05d', $i),
                'vessel_type' => $i % 3 === 0 ? 'fishing' : ($i % 3 === 1 ? 'cargo' : 'tanker'),
                'flag_state' => 'IDN',
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];

            $positionsData[] = [
                'id' => (string) Str::uuid(),
                'vessel_id' => $vId,
                'latitude' => -5.0 + (($i % 50) * 0.05),
                'longitude' => 100.0 + (($i % 50) * 0.05),
                'observed_at' => $obsTime,
                'received_at' => $obsTime,
                'is_on_land' => false,
                'data_quality_score' => 1.0,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($vesselsData, 1000) as $chunk) {
            Vessel::insert($chunk);
        }
        foreach (array_chunk($positionsData, 1000) as $chunk) {
            VesselPosition::insert($chunk);
        }

        // Viewport parameters via west, south, east, north
        $qs = 'mode=viewport&west=95.0&south=-10.0&east=105.0&north=0.0&limit=1000&page=1';
        $res1 = $this->actingAs($this->user)->getJson("/api/layers/vessels?{$qs}");
        $res1->assertStatus(200);
        $d1 = $res1->json();

        $this->assertEquals('viewport', $d1['metadata']['mode']);
        $this->assertEquals(2900, $d1['metadata']['total_in_viewport']);
        $this->assertEquals(1000, $d1['metadata']['count']);
        $this->assertEquals(3, $d1['metadata']['total_pages']);
        $this->assertTrue($d1['metadata']['has_more']);

        // Batch 2
        $res2 = $this->actingAs($this->user)->getJson("/api/layers/vessels?{$qs}&page=2&total_in_viewport=2900");
        $res2->assertStatus(200);
        $d2 = $res2->json();
        $this->assertEquals(1000, $d2['metadata']['count']);
        $this->assertTrue($d2['metadata']['has_more']);

        // Batch 3
        $res3 = $this->actingAs($this->user)->getJson("/api/layers/vessels?{$qs}&page=3&total_in_viewport=2900");
        $res3->assertStatus(200);
        $d3 = $res3->json();
        $this->assertEquals(900, $d3['metadata']['count']);
        $this->assertFalse($d3['metadata']['has_more']);

        // Total loaded equals all 2,900 vessels
        $totalCollected = array_merge($d1['features'], $d2['features'], $d3['features']);
        $this->assertCount(2900, $totalCollected);
        $uniqueIds = array_unique(array_column(array_column($totalCollected, 'properties'), 'vessel_id'));
        $this->assertCount(2900, $uniqueIds);
    }

    public function test_viewport_mode_respects_vessel_type_and_flag_filters(): void
    {
        $v1 = Vessel::create(['name' => 'Fishing IDN', 'mmsi' => '525000010', 'vessel_type' => 'fishing', 'flag_state' => 'IDN']);
        VesselPosition::create([
            'vessel_id' => $v1->id,
            'latitude' => -5.0,
            'longitude' => 106.0,
            'observed_at' => Carbon::now()->subMinutes(5),
            'received_at' => Carbon::now()->subMinutes(5),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        $v2 = Vessel::create(['name' => 'Cargo IDN', 'mmsi' => '525000011', 'vessel_type' => 'cargo', 'flag_state' => 'IDN']);
        VesselPosition::create([
            'vessel_id' => $v2->id,
            'latitude' => -5.1,
            'longitude' => 106.1,
            'observed_at' => Carbon::now()->subMinutes(10),
            'received_at' => Carbon::now()->subMinutes(10),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        $v3 = Vessel::create(['name' => 'Fishing SGP', 'mmsi' => '563000012', 'vessel_type' => 'fishing', 'flag_state' => 'SGP']);
        VesselPosition::create([
            'vessel_id' => $v3->id,
            'latitude' => -5.2,
            'longitude' => 106.2,
            'observed_at' => Carbon::now()->subMinutes(15),
            'received_at' => Carbon::now()->subMinutes(15),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        // Filter: vessel_type=fishing in viewport
        $resFilterType = $this->actingAs($this->user)->getJson('/api/layers/vessels?mode=viewport&bbox=105.0,-6.0,107.0,-4.0&vessel_type=fishing');
        $resFilterType->assertStatus(200);
        $this->assertEquals(2, $resFilterType->json('metadata.total_in_viewport'));
        $types = collect($resFilterType->json('features'))->pluck('properties.vessel_type')->all();
        $this->assertContains('fishing', $types);
        $this->assertNotContains('cargo', $types);

        // Filter: vessel_type=fishing AND flag=IDN
        $resFilterBoth = $this->actingAs($this->user)->getJson('/api/layers/vessels?mode=viewport&bbox=105.0,-6.0,107.0,-4.0&vessel_type=fishing&flag=IDN');
        $resFilterBoth->assertStatus(200);
        $this->assertEquals(1, $resFilterBoth->json('metadata.total_in_viewport'));
        $this->assertEquals('Fishing IDN', $resFilterBoth->json('features.0.properties.name'));
    }
}
