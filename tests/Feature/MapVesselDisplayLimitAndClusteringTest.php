<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MapVesselDisplayLimitAndClusteringTest extends TestCase
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

    public function test_api_defaults_to_5000_vessels_and_respects_max_limit(): void
    {
        // Create 25 vessels across various categories
        $categories = ['fishing', 'cargo', 'tanker', 'pleasure', 'passenger', 'tug', 'unknown'];
        for ($i = 1; $i <= 25; $i++) {
            $cat = $categories[($i - 1) % count($categories)];
            $vessel = Vessel::create([
                'name' => "Fleet Vessel {$i}",
                'mmsi' => sprintf('52500%04d', $i),
                'vessel_type' => $cat,
            ]);

            VesselPosition::create([
                'vessel_id' => $vessel->id,
                'latitude' => -5.0 - ($i * 0.05),
                'longitude' => 106.0 + ($i * 0.05),
                'observed_at' => Carbon::now()->subMinutes($i * 5),
                'received_at' => Carbon::now()->subMinutes($i * 5),
                'is_on_land' => false,
                'data_quality_score' => 1.0,
            ]);
        }

        // 1. Default request should fetch up to 5000 vessels (here all 25)
        $response = $this->actingAs($this->user)->getJson('/api/layers/vessels');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(25, $data['metadata']['count']);
        $this->assertEquals(5000, $data['metadata']['limit']);
        $this->assertEquals(25, $data['metadata']['rendered_count']);
        $this->assertArrayHasKey('total_vessels', $data['metadata']);
        $this->assertArrayHasKey('valid_positions', $data['metadata']);
        $this->assertArrayHasKey('active_vessels', $data['metadata']);
        $this->assertArrayHasKey('stale_vessels', $data['metadata']);

        // 2. Explicit limit request (e.g., limit=10)
        $responseLimited = $this->actingAs($this->user)->getJson('/api/layers/vessels?limit=10');
        $responseLimited->assertStatus(200);
        $this->assertCount(10, $responseLimited->json('features'));
        $this->assertEquals(10, $responseLimited->json('metadata.limit'));

        // 3. Explicit limit=5000 request
        $response5k = $this->actingAs($this->user)->getJson('/api/layers/vessels?limit=5000');
        $response5k->assertStatus(200);
        $this->assertEquals(5000, $response5k->json('metadata.limit'));

        // 4. Requests exceeding max limit 5000 are clamped to 5000
        $responseClamped = $this->actingAs($this->user)->getJson('/api/layers/vessels?limit=9999');
        $responseClamped->assertStatus(200);
        $this->assertEquals(5000, $responseClamped->json('metadata.limit'));
    }

    public function test_prioritizes_vessels_with_latest_ais_updates(): void
    {
        // Vessel A: observed 5 minutes ago (recent)
        $vesselA = Vessel::create(['name' => 'Vessel Recent', 'mmsi' => '525000001', 'vessel_type' => 'fishing']);
        VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => -5.1,
            'longitude' => 106.1,
            'observed_at' => Carbon::now()->subMinutes(5),
            'received_at' => Carbon::now()->subMinutes(5),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        // Vessel B: observed 3 hours ago (older)
        $vesselB = Vessel::create(['name' => 'Vessel Older', 'mmsi' => '525000002', 'vessel_type' => 'cargo']);
        VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => -5.2,
            'longitude' => 106.2,
            'observed_at' => Carbon::now()->subHours(3),
            'received_at' => Carbon::now()->subHours(3),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        // Request with limit 1 should return Vessel A first because it has the latest AIS update
        $result = $this->spatialService->getLiveVesselsFeatureCollection(null, null, 1);
        $this->assertCount(1, $result['features']);
        $this->assertEquals($vesselA->id, $result['features'][0]['properties']['vessel_id']);
    }

    public function test_all_vessel_categories_are_eligible_and_not_filtered(): void
    {
        $types = ['cargo', 'tanker', 'pleasure', 'fishing', 'passenger', 'tug', 'unknown'];
        foreach ($types as $idx => $type) {
            $vessel = Vessel::create([
                'name' => "Vessel {$type}",
                'mmsi' => sprintf('52599%04d', $idx + 1),
                'vessel_type' => $type,
            ]);

            VesselPosition::create([
                'vessel_id' => $vessel->id,
                'latitude' => -4.0 - ($idx * 0.1),
                'longitude' => 107.0 + ($idx * 0.1),
                'observed_at' => Carbon::now()->subMinutes(10 + $idx),
                'received_at' => Carbon::now()->subMinutes(10 + $idx),
                'is_on_land' => false,
                'data_quality_score' => 1.0,
            ]);
        }

        $result = $this->spatialService->getLiveVesselsFeatureCollection(null, null, 5000);
        $this->assertCount(7, $result['features']);

        $returnedTypes = collect($result['features'])->pluck('properties.vessel_type')->all();
        foreach ($types as $type) {
            $this->assertContains($type, $returnedTypes, "Vessel type '{$type}' was improperly excluded");
        }
    }

    public function test_invalid_land_and_zero_coordinates_are_strictly_excluded(): void
    {
        $vSea = Vessel::create(['name' => 'Sea Vessel', 'mmsi' => '525880001', 'vessel_type' => 'fishing']);
        VesselPosition::create([
            'vessel_id' => $vSea->id,
            'latitude' => -5.5,
            'longitude' => 106.5,
            'observed_at' => Carbon::now()->subMinutes(5),
            'received_at' => Carbon::now()->subMinutes(5),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        $vLand = Vessel::create(['name' => 'Land Position Vessel', 'mmsi' => '525880002', 'vessel_type' => 'cargo']);
        VesselPosition::create([
            'vessel_id' => $vLand->id,
            'latitude' => -6.2, // Jakarta center (land)
            'longitude' => 106.8,
            'observed_at' => Carbon::now()->subMinutes(2),
            'received_at' => Carbon::now()->subMinutes(2),
            'is_on_land' => true,
            'data_quality_score' => 0.0,
        ]);

        $vZero = Vessel::create(['name' => 'Null Island Vessel', 'mmsi' => '525880003', 'vessel_type' => 'tanker']);
        VesselPosition::create([
            'vessel_id' => $vZero->id,
            'latitude' => 0.0,
            'longitude' => 0.0,
            'observed_at' => Carbon::now()->subMinutes(1),
            'received_at' => Carbon::now()->subMinutes(1),
            'is_on_land' => false,
            'data_quality_score' => 0.0,
        ]);

        $result = $this->spatialService->getLiveVesselsFeatureCollection(null, null, 5000);
        $vesselIds = collect($result['features'])->pluck('properties.vessel_id')->all();

        $this->assertContains($vSea->id, $vesselIds);
        $this->assertNotContains($vLand->id, $vesselIds);
        $this->assertNotContains($vZero->id, $vesselIds);

        // Verify historical records still exist in database
        $this->assertDatabaseHas('vessel_positions', ['vessel_id' => $vLand->id, 'is_on_land' => true]);
        $this->assertDatabaseHas('vessel_positions', ['vessel_id' => $vZero->id, 'latitude' => 0.0]);
    }

    public function test_fleet_statistics_accurately_distinguish_total_valid_active_and_stale(): void
    {
        // Clear cache for test
        \Illuminate\Support\Facades\Cache::forget('vessel_spatial_statistics');

        // Vessel 1: Active sea position (< 2 hours ago)
        $v1 = Vessel::create(['name' => 'Active Vessel', 'mmsi' => '525770001', 'vessel_type' => 'fishing']);
        VesselPosition::create([
            'vessel_id' => $v1->id,
            'latitude' => -5.1,
            'longitude' => 106.1,
            'observed_at' => Carbon::now()->subMinutes(30),
            'received_at' => Carbon::now()->subMinutes(30),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        // Vessel 2: Stale sea position (> 2 hours ago)
        $v2 = Vessel::create(['name' => 'Stale Vessel', 'mmsi' => '525770002', 'vessel_type' => 'cargo']);
        VesselPosition::create([
            'vessel_id' => $v2->id,
            'latitude' => -5.2,
            'longitude' => 106.2,
            'observed_at' => Carbon::now()->subHours(5),
            'received_at' => Carbon::now()->subHours(5),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        // Vessel 3: Vessel with only land position
        $v3 = Vessel::create(['name' => 'Land Only Vessel', 'mmsi' => '525770003', 'vessel_type' => 'tanker']);
        VesselPosition::create([
            'vessel_id' => $v3->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
            'observed_at' => Carbon::now()->subMinutes(10),
            'received_at' => Carbon::now()->subMinutes(10),
            'is_on_land' => true,
            'data_quality_score' => 0.0,
        ]);

        // Vessel 4: Vessel with no position record at all
        Vessel::create(['name' => 'No Position Vessel', 'mmsi' => '525770004', 'vessel_type' => 'pleasure']);

        $stats = $this->spatialService->getVesselStatistics();

        $this->assertEquals(4, $stats['total_vessels']);
        $this->assertEquals(2, $stats['valid_positions']); // only v1 and v2
        $this->assertEquals(1, $stats['active_vessels']); // only v1
        $this->assertEquals(1, $stats['stale_vessels']);  // only v2
    }

    public function test_returns_up_to_5000_vessels_when_over_5000_candidates_exist(): void
    {
        // Batch insert 5,500 vessels with valid sea positions
        $vesselsData = [];
        $positionsData = [];
        $now = Carbon::now();

        for ($i = 1; $i <= 5500; $i++) {
            $vId = (string) \Illuminate\Support\Str::uuid();
            $pId = (string) \Illuminate\Support\Str::uuid();
            $obsTime = $now->copy()->subMinutes(6000 - $i)->format('Y-m-d H:i:s');

            $vesselsData[] = [
                'id' => $vId,
                'name' => "Batch Vessel {$i}",
                'mmsi' => sprintf('525%06d', $i),
                'vessel_type' => ($i % 3 === 0) ? 'cargo' : (($i % 3 === 1) ? 'fishing' : 'tanker'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];

            $positionsData[] = [
                'id' => $pId,
                'vessel_id' => $vId,
                'latitude' => -5.0 + (($i % 50) * 0.02),
                'longitude' => 106.0 + (($i % 50) * 0.02),
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

        // Fetch without explicit limit (defaults to 5000)
        $result = $this->spatialService->getLiveVesselsFeatureCollection();

        $this->assertCount(5000, $result['features']);
        $this->assertEquals(5000, $result['metadata']['count']);
        $this->assertEquals(5000, $result['metadata']['limit']);
        $this->assertEquals(5000, $result['metadata']['rendered_count']);

        // Verify no duplicate vessel IDs returned
        $returnedVesselIds = collect($result['features'])->pluck('properties.vessel_id')->all();
        $this->assertCount(5000, array_unique($returnedVesselIds));
    }
}
