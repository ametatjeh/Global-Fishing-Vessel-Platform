<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DefaultFishingMapOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected SpatialQueryService $spatial;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::factory()->create([
            'name' => 'Map Officer',
            'email' => 'map.officer@gfvip.org',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->spatial = app(SpatialQueryService::class);
    }

    private function createVesselWithPosition(string $name, string $mmsi, string $type, float $lat, float $lng): Vessel
    {
        $vessel = Vessel::create([
            'name' => $name,
            'mmsi' => $mmsi,
            'vessel_type' => $type,
            'flag_state' => 'IDN',
            'verification_status' => 'verified',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'speed_knots' => 8.5,
            'course_degrees' => 120.0,
            'heading_degrees' => 120.0,
            'nav_status' => 'under_way_using_engine',
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
            'is_on_land' => false,
            'data_quality_score' => 1.0,
        ]);

        return $vessel;
    }

    /**
     * Test 01: Initial /api/layers/vessels call without params defaults strictly to fishing vessels.
     */
    public function test_01_api_defaults_strictly_to_fishing_vessels(): void
    {
        $this->createVesselWithPosition('KM LAUT FISHING', '525000001', 'fishing', -5.0, 106.0);
        $this->createVesselWithPosition('MV CARGO SHIP', '525000002', 'cargo', -5.2, 106.2);
        $this->createVesselWithPosition('MT TANKER SHIP', '525000003', 'tanker', -5.4, 106.4);
        $this->createVesselWithPosition('PASSENGER FERRY', '525000004', 'passenger', -5.6, 106.6);

        $response = $this->actingAs($this->admin)->getJson('/api/layers/vessels');

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertEquals('FeatureCollection', $json['type']);
        $this->assertCount(1, $json['features']);

        $feature = $json['features'][0];
        $this->assertEquals('KM LAUT FISHING', $feature['properties']['name']);
        $this->assertEquals('fishing', $feature['properties']['vessel_type']);

        // Assert non-fishing vessels are excluded
        $typesInResponse = collect($json['features'])->pluck('properties.vessel_type')->all();
        $this->assertNotContains('cargo', $typesInResponse);
        $this->assertNotContains('tanker', $typesInResponse);
        $this->assertNotContains('passenger', $typesInResponse);

        // Assert metadata indicates default filter applied
        $this->assertEquals(['fishing'], $json['metadata']['vessel_types']);
        $this->assertEquals('fishing', $json['metadata']['default_filter']);
    }

    /**
     * Test 02: User can explicitly query cargo or other single vessel types.
     */
    public function test_02_user_can_query_specific_vessel_type(): void
    {
        $this->createVesselWithPosition('KM LAUT FISHING', '525000001', 'fishing', -5.0, 106.0);
        $this->createVesselWithPosition('MV CARGO SHIP', '525000002', 'cargo', -5.2, 106.2);

        $response = $this->actingAs($this->admin)->getJson('/api/layers/vessels?vessel_type=cargo');
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(1, $json['features']);
        $this->assertEquals('MV CARGO SHIP', $json['features'][0]['properties']['name']);
        $this->assertEquals('cargo', $json['features'][0]['properties']['vessel_type']);
    }

    /**
     * Test 03: User can activate multiple vessel types simultaneously via comma-separated types parameter.
     */
    public function test_03_user_can_query_multiple_vessel_types(): void
    {
        $this->createVesselWithPosition('KM LAUT FISHING', '525000001', 'fishing', -5.0, 106.0);
        $this->createVesselWithPosition('MV CARGO SHIP', '525000002', 'cargo', -5.2, 106.2);
        $this->createVesselWithPosition('MT TANKER SHIP', '525000003', 'tanker', -5.4, 106.4);
        $this->createVesselWithPosition('PASSENGER FERRY', '525000004', 'passenger', -5.6, 106.6);

        $response = $this->actingAs($this->admin)->getJson('/api/layers/vessels?types=fishing,tanker');
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(2, $json['features']);
        $typesInResponse = collect($json['features'])->pluck('properties.vessel_type')->sort()->values()->all();
        $this->assertEquals(['fishing', 'tanker'], $typesInResponse);
        $this->assertNotContains('cargo', $typesInResponse);
        $this->assertNotContains('passenger', $typesInResponse);
    }

    /**
     * Test 04: User can select all vessel types using types=all.
     */
    public function test_04_user_can_select_all_vessel_types(): void
    {
        $this->createVesselWithPosition('KM LAUT FISHING', '525000001', 'fishing', -5.0, 106.0);
        $this->createVesselWithPosition('MV CARGO SHIP', '525000002', 'cargo', -5.2, 106.2);
        $this->createVesselWithPosition('MT TANKER SHIP', '525000003', 'tanker', -5.4, 106.4);

        $response = $this->actingAs($this->admin)->getJson('/api/layers/vessels?types=all');
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(3, $json['features']);
        $typesInResponse = collect($json['features'])->pluck('properties.vessel_type')->sort()->values()->all();
        $this->assertEquals(['cargo', 'fishing', 'tanker'], $typesInResponse);
    }

    /**
     * Test 05: Viewport-based bounding box filtering works together with vessel type filter.
     */
    public function test_05_viewport_bounding_box_filters_vessels_accurately(): void
    {
        // Vessel 1: Fishing INSIDE Java Sea bbox
        $this->createVesselWithPosition('KM JAVA FISHING', '525000010', 'fishing', -5.0, 106.0);

        // Vessel 2: Fishing OUTSIDE bbox (Pacific Ocean)
        $this->createVesselWithPosition('KM PACIFIC FISHING', '525000011', 'fishing', 20.0, 160.0);

        // Vessel 3: Cargo INSIDE Java Sea bbox (must still be excluded because default is fishing)
        $this->createVesselWithPosition('MV JAVA CARGO', '525000012', 'cargo', -5.1, 106.1);

        $bbox = '105.0,-6.0,108.0,-4.0';
        $response = $this->actingAs($this->admin)->getJson("/api/layers/vessels?bbox={$bbox}&zoom=7");

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(1, $json['features']);
        $this->assertEquals('KM JAVA FISHING', $json['features'][0]['properties']['name']);
    }

    /**
     * Test 06: Spatial query response caching distinguishes between different vessel type filters.
     */
    public function test_06_cache_is_isolated_between_different_filter_keys(): void
    {
        $this->createVesselWithPosition('KM LAUT FISHING', '525000001', 'fishing', -5.0, 106.0);
        $this->createVesselWithPosition('MV CARGO SHIP', '525000002', 'cargo', -5.2, 106.2);

        // Call 1: Default (Fishing) -> should return 1 vessel and populate cache
        $res1 = $this->actingAs($this->admin)->getJson('/api/layers/vessels');
        $this->assertCount(1, $res1->json()['features']);
        $this->assertEquals('fishing', $res1->json()['features'][0]['properties']['vessel_type']);

        // Call 2: Cargo -> must not return cached fishing result
        $res2 = $this->actingAs($this->admin)->getJson('/api/layers/vessels?types=cargo');
        $this->assertCount(1, $res2->json()['features']);
        $this->assertEquals('cargo', $res2->json()['features'][0]['properties']['vessel_type']);

        // Call 3: Call default again -> returns cached fishing result instantly
        $res3 = $this->actingAs($this->admin)->getJson('/api/layers/vessels');
        $this->assertCount(1, $res3->json()['features']);
        $this->assertEquals('fishing', $res3->json()['features'][0]['properties']['vessel_type']);
    }

    /**
     * Test 07: Map Blade template renders Fishing Only and Select All controls and checkboxes.
     */
    public function test_07_map_page_renders_fishing_only_filter_ui(): void
    {
        $response = $this->actingAs($this->admin)->get('/map#map');
        $response->assertStatus(200);

        // Quick action buttons
        $response->assertSee('btnFilterFishingOnly', false);
        $response->assertSee('Fishing Only', false);
        $response->assertSee('btnFilterSelectAllTypes', false);
        $response->assertSee('Select All', false);

        // Vessel type checkbox container
        $response->assertSee('vesselTypeCheckboxesContainer', false);
        $response->assertSee('vessel-type-checkbox', false);
    }
}
