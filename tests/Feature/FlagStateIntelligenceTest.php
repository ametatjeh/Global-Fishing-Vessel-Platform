<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Maritime\FlagStateService;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlagStateIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_state_service_resolves_explicit_iso2_and_iso3_country(): void
    {
        $idRes = FlagStateService::resolveFromCodeAndMmsi('ID');
        $this->assertEquals('verified_explicit', $idRes['status']);
        $this->assertEquals('ID', $idRes['iso2']);
        $this->assertEquals('Indonesia', $idRes['country_name']);
        $this->assertEquals('🇮🇩', $idRes['flag_emoji']);
        $this->assertTrue($idRes['is_verified']);

        $esRes = FlagStateService::resolveFromCodeAndMmsi('ESP');
        $this->assertEquals('verified_explicit', $esRes['status']);
        $this->assertEquals('ES', $esRes['iso2']);
        $this->assertEquals('Spain', $esRes['country_name']);
        $this->assertEquals('🇪🇸', $esRes['flag_emoji']);
    }

    public function test_flag_state_service_resolves_mmsi_mid_administrative_clue_when_explicit_is_null(): void
    {
        // 503 is Australia
        $res = FlagStateService::resolveFromCodeAndMmsi(null, '503730800');
        $this->assertEquals('mid_administrative_indicator', $res['status']);
        $this->assertEquals('AU', $res['iso2']);
        $this->assertEquals('Australia', $res['country_name']);
        $this->assertEquals('🇦🇺', $res['flag_emoji']);
        $this->assertFalse($res['is_verified']); // Administrative clue, not definitive
        $this->assertStringContainsString('MID', $res['label']);
    }

    public function test_flag_state_service_detects_conflict_between_explicit_and_mid(): void
    {
        // MMSI 503 (Australia), but explicit flag is CN (China)
        $res = FlagStateService::resolveFromCodeAndMmsi('CN', '503730800');
        $this->assertEquals('conflict_detected', $res['status']);
        $this->assertTrue($res['has_conflict']);
        $this->assertFalse($res['is_verified']);
        $this->assertNotNull($res['conflict_note']);
    }

    public function test_flag_state_service_returns_unknown_when_no_data(): void
    {
        $res = FlagStateService::resolveFromCodeAndMmsi(null, null);
        $this->assertEquals('unknown', $res['status']);
        $this->assertEquals('UN', $res['iso2']);
        $this->assertEquals('Flag state unknown', $res['country_name']);
        $this->assertEquals('🌐', $res['flag_emoji']);
        $this->assertFalse($res['is_verified']);
    }

    public function test_vessel_model_exposes_flag_info_attribute(): void
    {
        $vessel = Vessel::create([
            'name' => 'PACIFIC VOYAGER',
            'mmsi' => '525123456', // Indonesia MID 525
            'vessel_type' => 'fishing',
        ]);

        $flagInfo = $vessel->flag_info;
        $this->assertIsArray($flagInfo);
        $this->assertEquals('ID', $flagInfo['iso2']);
        $this->assertEquals('Indonesia', $flagInfo['country_name']);
        $this->assertEquals('🇮🇩', $flagInfo['flag_emoji']);
        $this->assertEquals('mid_administrative_indicator', $flagInfo['status']);
    }

    public function test_spatial_query_service_includes_flag_properties_in_live_vessels(): void
    {
        $vessel = Vessel::create([
            'name' => 'F/V SOUTHERN OCEAN',
            'mmsi' => '503999888',
            'flag_state' => 'AU',
            'vessel_type' => 'fishing',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -10.5,
            'longitude' => 105.2,
            'observed_at' => now(),
            'received_at' => now(),
            'speed_knots' => 8.2,
            'course_degrees' => 180.0,
            'data_quality_score' => 0.95,
        ]);

        $service = app(SpatialQueryService::class);
        $fc = $service->getLiveVesselsFeatureCollection();

        $this->assertEquals('FeatureCollection', $fc['type']);
        $this->assertNotEmpty($fc['features']);

        $feature = $fc['features'][0];
        $this->assertArrayHasKey('flag_state', $feature['properties']);
        $this->assertArrayHasKey('flag_country', $feature['properties']);
        $this->assertArrayHasKey('flag_emoji', $feature['properties']);
        $this->assertArrayHasKey('flag_status', $feature['properties']);
        $this->assertEquals('AU', $feature['properties']['flag_state']);
        $this->assertEquals('Australia', $feature['properties']['flag_country']);
        $this->assertEquals('🇦🇺', $feature['properties']['flag_emoji']);
    }

    public function test_vessel_show_page_displays_flag_state_information(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'OCEAN QUEEN',
            'mmsi' => '412001122', // China MID 412
            'flag_state' => 'CN',
            'vessel_type' => 'fishing',
        ]);

        $response = $this->actingAs($user)->get("/vessels/{$vessel->id}");
        $response->assertStatus(200);
        $response->assertSee('China');
        $response->assertSee('CN');
        $response->assertSee('🇨🇳');
    }

    public function test_tracking_page_displays_vessel_flag_state_information(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $vessel = Vessel::create([
            'name' => 'TRACKING TESTER',
            'mmsi' => '503111222',
            'flag_state' => 'AU',
            'vessel_type' => 'fishing',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -12.0,
            'longitude' => 110.0,
            'observed_at' => now(),
            'received_at' => now(),
            'speed_knots' => 10.0,
            'course_degrees' => 90.0,
        ]);

        // Live tracking is consolidated into /api/layers/vessels for the Global Map
        $apiResponse = $this->actingAs($user)->getJson('/api/layers/vessels');
        $apiResponse->assertStatus(200);
        $apiResponse->assertJsonFragment([
            'flag_state' => 'AU',
        ]);

        // Redundant /tracking route is permanently removed (returns 404)
        $this->actingAs($user)->get('/tracking')->assertStatus(404);
    }
}
