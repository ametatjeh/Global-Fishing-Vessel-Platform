<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HistoricalTrackUnlimitedTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected SpatialQueryService $spatial;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::factory()->create([
            'name' => 'Maritime Surveillance Officer',
            'email' => 'surveillance.officer@gfvip.org',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->spatial = app(SpatialQueryService::class);
    }

    /**
     * Create a vessel record.
     */
    private function createVessel(string $name, string $mmsi, string $type = 'fishing'): Vessel
    {
        return Vessel::create([
            'name' => $name,
            'mmsi' => $mmsi,
            'vessel_type' => $type,
            'flag_state' => 'IDN',
            'verification_status' => 'verified',
        ]);
    }

    /**
     * Create an AIS position for a vessel at a specific observed time.
     */
    private function addPosition(
        Vessel $vessel,
        float $lat,
        float $lng,
        \Carbon\Carbon $observedAt,
        float $speed = 8.5,
        float $course = 120.0,
        bool $isGap = false
    ): VesselPosition {
        return VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'speed_knots' => $speed,
            'course_degrees' => $course,
            'heading_degrees' => $course,
            'nav_status' => 'under_way_using_engine',
            'observed_at' => $observedAt,
            'received_at' => $observedAt->copy()->addSeconds(5),
            'is_on_land' => false,
            'is_gap_indicator' => $isGap,
            'data_quality_score' => 1.0,
        ]);
    }

    /**
     * Test 01: Default track range is 7 days.
     */
    public function test_01_default_track_period_is_7_days(): void
    {
        $vessel = $this->createVessel('KM BINTANG SAMUDERA', '525990001', 'fishing');

        // Positions: 2 inside 7d, 1 outside 7d (10 days ago)
        $this->addPosition($vessel, -5.1, 106.1, now()->subDays(2), 7.0);
        $this->addPosition($vessel, -5.2, 106.2, now()->subDays(1), 8.0);
        $this->addPosition($vessel, -5.0, 106.0, now()->subDays(10), 6.5);

        $response = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertEquals(2, $data['properties']['position_count']);
        $this->assertEquals(2, $data['properties']['total_in_range']);
        $this->assertEquals('7d', $data['properties']['time_range']);
    }

    /**
     * Test 02: Fishing vessel supports unlimited history (24h, 48h, 72h, 14d, 30d, 90d, 180d, 1y, all).
     */
    public function test_02_fishing_vessel_supports_unlimited_history_across_all_periods(): void
    {
        $vessel = $this->createVessel('KM BAHARI PRIMA', '525990002', 'fishing');

        // Telemetry across timeline:
        $this->addPosition($vessel, -5.01, 106.01, now()->subHours(10)); // inside 24h
        $this->addPosition($vessel, -5.02, 106.02, now()->subHours(36)); // inside 48h
        $this->addPosition($vessel, -5.03, 106.03, now()->subHours(60)); // inside 72h
        $this->addPosition($vessel, -5.04, 106.04, now()->subDays(5));   // inside 7d
        $this->addPosition($vessel, -5.05, 106.05, now()->subDays(12));  // inside 14d
        $this->addPosition($vessel, -5.06, 106.06, now()->subDays(25));  // inside 30d
        $this->addPosition($vessel, -5.07, 106.07, now()->subDays(75));  // inside 90d
        $this->addPosition($vessel, -5.08, 106.08, now()->subDays(150)); // inside 180d
        $this->addPosition($vessel, -5.09, 106.09, now()->subDays(300)); // inside 1y
        $this->addPosition($vessel, -5.10, 106.10, now()->subDays(450)); // > 1 year (all available)

        // 24h: 1 point
        $res24 = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=24h&fresh=1");
        $res24->assertStatus(200);
        $this->assertEquals(1, $res24->json('properties.position_count'));

        // 48h: 2 points
        $res48 = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=48h&fresh=1");
        $res48->assertStatus(200);
        $this->assertEquals(2, $res48->json('properties.position_count'));

        // 72h: 3 points
        $res72 = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=72h&fresh=1");
        $res72->assertStatus(200);
        $this->assertEquals(3, $res72->json('properties.position_count'));

        // 7d: 4 points
        $res7d = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=7d&fresh=1");
        $res7d->assertStatus(200);
        $this->assertEquals(4, $res7d->json('properties.position_count'));

        // 30d: 6 points
        $res30d = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=30d&fresh=1");
        $res30d->assertStatus(200);
        $this->assertEquals(6, $res30d->json('properties.position_count'));

        // 90d: 7 points
        $res90d = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=90d&fresh=1");
        $res90d->assertStatus(200);
        $this->assertEquals(7, $res90d->json('properties.position_count'));

        // 180d: 8 points
        $res180d = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=180d&fresh=1");
        $res180d->assertStatus(200);
        $this->assertEquals(8, $res180d->json('properties.position_count'));

        // 1y: 9 points
        $res1y = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=1y&fresh=1");
        $res1y->assertStatus(200);
        $this->assertEquals(9, $res1y->json('properties.position_count'));

        // All: all 10 points including the 450-day-old observation!
        $resAll = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=all&fresh=1");
        $resAll->assertStatus(200);
        $this->assertEquals(10, $resAll->json('properties.position_count'));
        $this->assertEquals(10, $resAll->json('properties.total_in_range'));
    }

    /**
     * Test 03: Vessel with single day history and vessel with no history.
     */
    public function test_03_vessel_with_single_position_and_empty_vessel(): void
    {
        $vesselSingle = $this->createVessel('KM SATU TITIK', '525990003', 'fishing');
        $this->addPosition($vesselSingle, -6.1, 106.8, now()->subHours(2));

        $resSingle = $this->actingAs($this->admin)->getJson("/api/vessels/{$vesselSingle->id}/track?range=24h");
        $resSingle->assertStatus(200);
        $this->assertEquals(1, $resSingle->json('properties.position_count'));
        $this->assertEquals('single_point', $resSingle->json('properties.track_status'));

        $vesselEmpty = $this->createVessel('KM KOSONG', '525990004', 'cargo');
        $resEmpty = $this->actingAs($this->admin)->getJson("/api/vessels/{$vesselEmpty->id}/track?range=all");
        $resEmpty->assertStatus(200);
        $this->assertEquals(0, $resEmpty->json('properties.position_count'));
        $this->assertEquals('empty', $resEmpty->json('properties.track_status'));
    }

    /**
     * Test 04: Custom Date Range query and validation.
     */
    public function test_04_custom_date_range_query_and_validation(): void
    {
        $vessel = $this->createVessel('KM CUSTOM TRACK', '525990005', 'fishing');

        // Positions on Nov 10, Nov 12, Nov 15, Nov 18
        $this->addPosition($vessel, -5.0, 106.0, \Carbon\Carbon::parse('2026-11-10 10:00:00'));
        $this->addPosition($vessel, -5.1, 106.1, \Carbon\Carbon::parse('2026-11-12 12:00:00'));
        $this->addPosition($vessel, -5.2, 106.2, \Carbon\Carbon::parse('2026-11-15 14:00:00'));
        $this->addPosition($vessel, -5.3, 106.3, \Carbon\Carbon::parse('2026-11-18 16:00:00'));

        // Query custom range Nov 11 to Nov 16 -> should only return Nov 12 and Nov 15 (2 points)
        $res = $this->actingAs($this->admin)->getJson(
            "/api/vessels/{$vessel->id}/track?range=custom&start_date=2026-11-11&start_time=00:00:00&end_date=2026-11-16&end_time=23:59:59&fresh=1"
        );

        $res->assertStatus(200);
        $this->assertEquals(2, $res->json('properties.position_count'));

        // Test inverted start/end dates: graceful handling
        $resInverted = $this->actingAs($this->admin)->getJson(
            "/api/vessels/{$vessel->id}/track?range=custom&start_date=2026-11-16&end_date=2026-11-11&fresh=1"
        );
        $resInverted->assertStatus(200);
        $this->assertEquals(2, $resInverted->json('properties.position_count'));
    }

    /**
     * Test 05: AIS transmission gap detection and track line segmentation.
     */
    public function test_05_ais_gap_detection_and_segmentation(): void
    {
        $vessel = $this->createVessel('KM GAP DETECTOR', '525990006', 'fishing');

        // Segment 1: 3 positions spaced 1 hour apart
        $this->addPosition($vessel, -5.00, 106.00, now()->subHours(20), 8.0);
        $this->addPosition($vessel, -5.01, 106.01, now()->subHours(19), 8.2);
        $this->addPosition($vessel, -5.02, 106.02, now()->subHours(18), 8.1);

        // Gap of 8 hours (signal blackout)
        // Segment 2: 2 positions spaced 1 hour apart
        $this->addPosition($vessel, -5.08, 106.08, now()->subHours(10), 7.5);
        $this->addPosition($vessel, -5.09, 106.09, now()->subHours(9), 7.8);

        $res = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=24h&fresh=1");
        $res->assertStatus(200);

        $features = $res->json('features');
        $lines = array_filter($features, fn ($f) => $f['geometry']['type'] === 'LineString');

        // Expect 2 distinct LineString segments because of the 8-hour gap
        $this->assertCount(2, $lines);
        $this->assertGreaterThanOrEqual(1, $res->json('properties.statistics.gap_count'));
    }

    /**
     * Test 06: Analytical statistics calculation (distance, avg/max speed, time spans).
     */
    public function test_06_analytical_statistics_calculation(): void
    {
        $vessel = $this->createVessel('KM STATISTIK', '525990007', 'fishing');

        $t0 = now()->subHours(5);
        $this->addPosition($vessel, -5.00, 106.00, $t0, 10.0);
        $this->addPosition($vessel, -5.05, 106.05, $t0->copy()->addHour(), 12.0);
        $this->addPosition($vessel, -5.10, 106.10, $t0->copy()->addHours(2), 14.0);

        $res = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=24h&fresh=1");
        $res->assertStatus(200);

        $stats = $res->json('properties.statistics');
        $this->assertNotNull($stats);
        $this->assertEquals(12.0, $stats['average_speed_knots']);
        $this->assertEquals(14.0, $stats['maximum_speed_knots']);
        $this->assertGreaterThan(0.0, $stats['estimated_distance_nm']);
        $this->assertEquals(3, $stats['points_loaded']);
        $this->assertNotNull($stats['all_time_first_observation']);
        $this->assertNotNull($stats['all_time_last_observation']);
    }

    /**
     * Test 07: Cursor-based chunked pagination.
     */
    public function test_07_cursor_based_chunked_pagination(): void
    {
        $vessel = $this->createVessel('KM CURSOR PAGINATION', '525990008', 'fishing');

        for ($i = 0; $i < 5; $i++) {
            $this->addPosition(
                $vessel,
                -5.0 + ($i * 0.05),
                106.0 + ($i * 0.05),
                now()->subHours(10 - $i),
                8.0
            );
        }

        // Batch 1: limit = 2
        $resBatch1 = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=24h&limit=2&fresh=1");
        $resBatch1->assertStatus(200);
        $this->assertEquals(2, $resBatch1->json('properties.position_count'));
        $this->assertTrue($resBatch1->json('properties.has_more'));
        $nextCursor = $resBatch1->json('properties.next_cursor');
        $this->assertNotNull($nextCursor);

        // Batch 2: limit = 2 with cursor from batch 1
        $resBatch2 = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=24h&limit=2&cursor={$nextCursor}&fresh=1");
        $resBatch2->assertStatus(200);
        $this->assertEquals(2, $resBatch2->json('properties.position_count'));
        $this->assertTrue($resBatch2->json('properties.has_more'));
    }

    /**
     * Test 08: Export Track CSV streaming.
     */
    public function test_08_export_track_csv_streaming(): void
    {
        $vessel = $this->createVessel('KM EXPORT CSV', '525990009', 'fishing');
        $this->addPosition($vessel, -5.123, 106.456, now()->subHours(3), 9.2);

        $response = $this->actingAs($this->admin)->get("/api/vessels/{$vessel->id}/track/export?format=csv&range=all");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Position ID', $content);
        $this->assertStringContainsString('MMSI', $content);
        $this->assertStringContainsString('KM EXPORT CSV', $content);
        $this->assertStringContainsString('525990009', $content);
        $this->assertStringContainsString('-5.123', $content);
    }

    /**
     * Test 09: Export Track GeoJSON streaming.
     */
    public function test_09_export_track_geojson_streaming(): void
    {
        $vessel = $this->createVessel('KM EXPORT GEOJSON', '525990010', 'fishing');
        $this->addPosition($vessel, -5.234, 106.567, now()->subHours(2), 10.5);

        $response = $this->actingAs($this->admin)->get("/api/vessels/{$vessel->id}/track/export?format=geojson&range=all");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/geo+json; charset=utf-8');

        $content = $response->streamedContent();
        $json = json_decode($content, true);
        $this->assertEquals('FeatureCollection', $json['type']);
        $this->assertEquals($vessel->id, $json['properties']['vessel_id']);
        $this->assertEquals('525990010', $json['properties']['mmsi']);
    }

    /**
     * Test 10: Map blade page renders Track History UI and Playback Dock.
     */
    public function test_10_map_page_renders_track_history_and_playback_dock(): void
    {
        $response = $this->actingAs($this->admin)->get('/map#map');

        $response->assertStatus(200);
        $response->assertSee('D. Track History', false);
        $response->assertSee('historicalTrackPlaybackDock', false);
        $response->assertSee('trackPeriodButtonGrid', false);
        $response->assertSee('24 Hours', false);
        $response->assertSee('7 Days', false);
        $response->assertSee('30 Days', false);
        $response->assertSee('1 Year', false);
        $response->assertSee('trackLoadingIndicator', false);
        $response->assertSee('trackStatusMessage', false);
        $response->assertSee('dockTimelineSlider', false);
        $response->assertSee('btnExportTrackCsv', false);
        $response->assertSee('btnExportTrackGeoJson', false);
    }

    /**
     * Test 11: Vessel with single position outside requested 7d period shows clean notice without duplicate dates.
     */
    public function test_11_vessel_with_single_position_outside_period_shows_clean_notice_without_duplicate_dates(): void
    {
        $vessel = $this->createVessel('KM TUNGGAL TUA', '525990011', 'fishing');
        // Single position recorded 10 days ago (outside 7d window)
        $obsTime = now()->subDays(10);
        $this->addPosition($vessel, -5.55, 106.66, $obsTime, 9.0);

        $response = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=7d&fresh=1");
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(0, $data['properties']['position_count']);
        $this->assertEquals('empty', $data['properties']['track_status']);

        $notice = $data['properties']['notice'];
        $this->assertEquals('Historical data unavailable', $notice['title']);
        $this->assertStringContainsString('7 Days', $notice['description']);
        $this->assertStringContainsString('Tercatat 1 posisi AIS pada', $notice['range_text']);
        $this->assertEquals('all', $notice['suggestion']);

        // Must not contain identical start/end duplicate date range string
        $this->assertStringNotContainsString('Total history available from', $data['properties']['message']);
        $this->assertStringNotContainsString('hingga', $notice['range_text']);
    }

    /**
     * Test 12: Vessel with multiple positions outside requested period shows formatted range and suggestions.
     */
    public function test_12_vessel_with_multiple_positions_outside_period_shows_formatted_range_and_suggestion(): void
    {
        $vessel = $this->createVessel('KM HISTORI JAUH', '525990012', 'fishing');
        $this->addPosition($vessel, -5.1, 106.1, now()->subDays(20));
        $this->addPosition($vessel, -5.2, 106.2, now()->subDays(15));
        $this->addPosition($vessel, -5.3, 106.3, now()->subDays(10));

        // Query 24h -> 0 in range, but 3 all-time
        $response = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=24h&fresh=1");
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(0, $data['properties']['position_count']);

        $notice = $data['properties']['notice'];
        $this->assertEquals('Historical data unavailable', $notice['title']);
        $this->assertStringContainsString('24 Hours', $notice['description']);
        $this->assertStringContainsString('hingga', $notice['range_text']);
        $this->assertStringContainsString('3 posisi', $notice['range_text']);
        $this->assertEquals('all', $notice['suggestion']);
    }

    /**
     * Test 13: Vessel with zero positions shows empty history message.
     */
    public function test_13_vessel_with_zero_positions_shows_empty_history_message(): void
    {
        $vessel = $this->createVessel('KM TANPA POSISI', '525990013', 'cargo');

        $response = $this->actingAs($this->admin)->getJson("/api/vessels/{$vessel->id}/track?range=7d&fresh=1");
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(0, $data['properties']['position_count']);
        $this->assertEquals('Riwayat AIS belum tersedia untuk kapal ini.', $data['properties']['message']);
        $this->assertEquals('Riwayat AIS belum tersedia untuk kapal ini.', $data['properties']['notice']['description']);
        $this->assertNull($data['properties']['notice']['range_text']);
        $this->assertNull($data['properties']['notice']['suggestion']);
    }
}
