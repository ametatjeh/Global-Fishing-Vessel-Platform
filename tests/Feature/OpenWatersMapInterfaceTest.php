<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenWatersMapInterfaceTest extends TestCase
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

    public function test_map_interface_renders_open_waters_elements(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('map.index'));

        $response->assertOk();
        // Base elements
        $response->assertSee('id="global-map-canvas"', false);
        $response->assertSee('OpenFreeMap Liberty');
        $response->assertSee('ESRI Dark Gray');

        // Open Waters Side Panel & Telemetry
        $response->assertSee('id="vesselDetailPanel"', false);
        $response->assertSee('id="panelVesselName"', false);
        $response->assertSee('id="panelVesselFlagEmoji"', false);
        $response->assertSee('id="panelVesselSog"', false);
        $response->assertSee('id="panelVesselCog"', false);
        $response->assertSee('id="panelVesselHeading"', false);
        $response->assertSee('id="panelVesselNavStatus"', false);
        $response->assertSee('id="panelVesselCoords"', false);
        $response->assertSee('id="panelVesselMmsi"', false);
        $response->assertSee('id="panelVesselProfileLink"', false);
        $response->assertSee('id="panelBtnShareUrl"', false);

        // Open Waters Track Range Controls (1h, 6h, 24h, 7d)
        $response->assertSee('data-range="1h"', false);
        $response->assertSee('data-range="6h"', false);
        $response->assertSee('data-range="24h"', false);
        $response->assertSee('data-range="7d"', false);
        $response->assertSee('1 Jam');
        $response->assertSee('6 Jam');
        $response->assertSee('24 Jam');
        $response->assertSee('7 Hari');

        // Basemap switcher and Cursor coordinates
        $response->assertSee('id="btnToggleBasemapMenu"', false);
        $response->assertSee('id="cursorCoordinates"', false);
    }

    public function test_historical_vessel_track_endpoint_supports_time_ranges_and_gap_avoidance(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM SAHABAT REZEKI 22',
            'mmsi' => '525801421',
            'vessel_type' => 'fishing',
            'flag_state' => 'ID',
        ]);

        // Position 1: 5 hours ago (Segment 1)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => 1.60000,
            'longitude' => 102.28000,
            'speed_knots' => 8.2,
            'course_degrees' => 140.0,
            'heading_degrees' => 142.0,
            'observed_at' => now()->subHours(5),
            'received_at' => now()->subHours(5),
        ]);

        // Position 2: 4.8 hours ago (Segment 1 continued)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => 1.60200,
            'longitude' => 102.28200,
            'speed_knots' => 8.4,
            'course_degrees' => 141.0,
            'heading_degrees' => 141.0,
            'observed_at' => now()->subHours(4)->subMinutes(48),
            'received_at' => now()->subHours(4)->subMinutes(48),
        ]);

        // Position 3: 30 minutes ago (> 4 hours gap from position 2 -> triggers gap segmentation!)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => 1.60898,
            'longitude' => 102.28512,
            'speed_knots' => 9.0,
            'course_degrees' => 145.0,
            'heading_degrees' => 145.0,
            'observed_at' => now()->subMinutes(30),
            'received_at' => now()->subMinutes(30),
        ]);

        // Position 4: 10 minutes ago (Segment 2 continued)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => 1.60950,
            'longitude' => 102.28580,
            'speed_knots' => 9.1,
            'course_degrees' => 146.0,
            'heading_degrees' => 146.0,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
        ]);

        // 1. Test 24h range query: returns 4 points, segmented into 2 LineStrings due to > 4h gap!
        $response24h = $this->actingAs($this->analyst)->getJson("/api/vessels/{$vessel->mmsi}/track?range=24h");
        $response24h->assertOk();
        $data24h = $response24h->json();

        $this->assertEquals('FeatureCollection', $data24h['type']);
        $this->assertEquals(4, $data24h['properties']['position_count']);

        $lines = array_filter($data24h['features'], fn($f) => $f['geometry']['type'] === 'LineString');
        $points = array_filter($data24h['features'], fn($f) => $f['geometry']['type'] === 'Point');

        // Due to the > 4 hour gap between pos 2 and pos 3, it must NOT connect pos 2 to pos 3.
        // It should have 2 distinct LineString segments!
        $this->assertCount(2, $lines, 'Track with > 4h gap must be segmented to prevent connecting across missing data.');
        $this->assertCount(4, $points);

        // Verify start and latest flags
        $startPoints = array_filter($points, fn($p) => ($p['properties']['is_start'] ?? false) === true);
        $latestPoints = array_filter($points, fn($p) => ($p['properties']['is_latest'] ?? false) === true);
        $this->assertCount(1, $startPoints);
        $this->assertCount(1, $latestPoints);

        // 2. Test 1h range query: only returns points from the last 1 hour (2 points)
        $response1h = $this->actingAs($this->analyst)->getJson("/api/vessels/{$vessel->mmsi}/track?range=1h");
        $response1h->assertOk();
        $data1h = $response1h->json();
        $this->assertEquals(2, $data1h['properties']['position_count']);
    }

    public function test_vessel_track_returns_empty_collection_gracefully_when_no_positions(): void
    {
        $vessel = Vessel::create([
            'name' => 'KAPAL TANPA POSISI',
            'mmsi' => '999999999',
            'vessel_type' => 'unknown',
        ]);

        $response = $this->actingAs($this->analyst)->getJson("/api/vessels/{$vessel->mmsi}/track");
        $response->assertOk();
        $data = $response->json();

        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertEmpty($data['features']);
        $this->assertEquals('empty', $data['properties']['track_status']);
        $this->assertEquals(0, $data['properties']['position_count']);
    }

    public function test_live_vessels_endpoint_respects_bounding_box_and_filters(): void
    {
        $vesselInside = Vessel::create([
            'name' => 'INSIDE BBOX VESSEL',
            'mmsi' => '111222333',
            'vessel_type' => 'fishing',
            'flag_state' => 'ID',
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselInside->id,
            'latitude' => 1.60898,
            'longitude' => 102.28512,
            'speed_knots' => 5.0,
            'course_degrees' => 90.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        $vesselOutside = Vessel::create([
            'name' => 'OUTSIDE BBOX VESSEL',
            'mmsi' => '444555666',
            'vessel_type' => 'fishing',
            'flag_state' => 'ID',
        ]);
        VesselPosition::create([
            'vessel_id' => $vesselOutside->id,
            'latitude' => -45.0,
            'longitude' => -120.0,
            'speed_knots' => 5.0,
            'course_degrees' => 90.0,
            'observed_at' => now(),
            'received_at' => now(),
        ]);

        // Query with bbox covering Malacca Strait [100, 0, 105, 5]
        $response = $this->actingAs($this->analyst)->getJson('/api/layers/vessels?bbox=100.0,0.0,105.0,5.0');
        $response->assertOk();
        $data = $response->json();

        $mmsis = array_map(fn($f) => $f['properties']['mmsi'], $data['features']);
        $this->assertContains('111222333', $mmsis);
        $this->assertNotContains('444555666', $mmsis);
    }
}
