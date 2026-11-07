<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Sar\SarAisCorrelationService;
use App\Services\Sar\SarEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SarMultiSourceIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $analyst;
    protected User $viewer;
    protected SatelliteScene $scene;
    protected SatelliteDetection $detection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'name' => 'Intelligence Director',
        ]);

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Radar Correlation Analyst',
        ]);

        $this->viewer = User::factory()->create([
            'role' => 'viewer',
            'name' => 'Maritime Observer',
        ]);

        $this->scene = SatelliteScene::create([
            'scene_id' => '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7',
            'platform_name' => 'Sentinel-1C',
            'instrument' => 'SAR-C',
            'sensor_mode' => 'EW',
            'polarization' => 'HH',
            'acquisition_start' => '2026-10-19 13:52:12',
            'acquisition_end' => '2026-10-19 13:52:36',
            'footprint_geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[80.0, -58.0], [87.0, -58.0], [87.0, -60.0], [80.0, -60.0], [80.0, -58.0]]]
            ]),
            'processing_status' => 'detection_completed',
        ]);

        $this->detection = SatelliteDetection::create([
            'satellite_scene_id' => $this->scene->id,
            'detection_identifier' => '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0001',
            'latitude' => -59.583912,
            'longitude' => 84.866561,
            'detected_at' => '2026-10-19 13:52:12',
            'tcr_db' => 15.85,
            'estimated_length_meters' => 226.3,
            'estimated_width_meters' => 84.9,
            'confidence_score' => 0.90,
            'correlation_status' => 'insufficient_data',
            'review_status' => SatelliteDetection::REVIEW_PENDING,
            'correlation_method' => '2D_CA_CFAR_GEOLOC_GRID',
        ]);
    }

    /**
     * Test 1: Historical AIS track retrieval returns GeoJSON LineString and Points.
     */
    public function test_historical_ais_track_retrieval_returns_geojson_linestring_and_points(): void
    {
        $vessel = Vessel::create([
            'mmsi' => '412000111',
            'name' => 'OCEAN EXPLORER',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $sarTime = $this->detection->detected_at;

        // Position 1: 15 min before SAR (-59.590, 84.850)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -59.590000,
            'longitude' => 84.850000,
            'speed_knots' => 10.5,
            'course_degrees' => 45.0,
            'heading_degrees' => 46.0,
            'observed_at' => $sarTime->copy()->subMinutes(15),
            'received_at' => $sarTime->copy()->subMinutes(15),
        ]);

        // Position 2: Closest position at 2 min after SAR (-59.584, 84.867)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -59.584000,
            'longitude' => 84.867000,
            'speed_knots' => 10.2,
            'course_degrees' => 44.0,
            'heading_degrees' => 45.0,
            'observed_at' => $sarTime->copy()->addMinutes(2),
            'received_at' => $sarTime->copy()->addMinutes(2),
        ]);

        // Position 3: 20 min after SAR (-59.578, 84.885)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -59.578000,
            'longitude' => 84.885000,
            'speed_knots' => 10.4,
            'course_degrees' => 43.0,
            'heading_degrees' => 44.0,
            'observed_at' => $sarTime->copy()->addMinutes(20),
            'received_at' => $sarTime->copy()->addMinutes(20),
        ]);

        $response = $this->actingAs($this->analyst)->getJson("/api/sar/candidates/{$this->detection->id}/track?window=60");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'type',
            'features',
            'properties' => [
                'window_minutes',
                'position_count',
                'track_status',
            ],
        ]);

        $data = $response->json();
        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertEquals(3, $data['properties']['position_count']);
        $this->assertEquals('available', $data['properties']['track_status']);

        // Check for LineString feature
        $lineFeatures = array_filter($data['features'], fn($f) => $f['geometry']['type'] === 'LineString');
        $this->assertNotEmpty($lineFeatures);

        // Check point features include before_sar, nearest, and after_sar
        $roles = array_column(array_column($data['features'], 'properties'), 'role');
        $this->assertContains('nearest', $roles);
        $this->assertContains('before_sar', $roles);
        $this->assertContains('after_sar', $roles);
    }

    /**
     * Test 2: SAR-AIS spatial correlation evaluates distance.
     */
    public function test_sar_ais_spatial_correlation_evaluates_distance(): void
    {
        $vessel = Vessel::create([
            'mmsi' => '412000222',
            'name' => 'PACIFIC VOYAGER',
        ]);

        // Position ~120m away from candidate
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $this->detection->latitude + 0.001,
            'longitude' => $this->detection->longitude,
            'speed_knots' => 8.0,
            'course_degrees' => 90.0,
            'observed_at' => $this->detection->detected_at->copy()->addMinutes(1),
            'received_at' => $this->detection->detected_at->copy()->addMinutes(1),
        ]);

        $service = app(SarAisCorrelationService::class);
        $enhanced = $service->calculateEnhancedCorrelation($this->detection, 60);

        $this->assertNotNull($enhanced['spatial']['nearest_ais_distance_meters']);
        $this->assertLessThan(200.0, $enhanced['spatial']['nearest_ais_distance_meters']);
        $this->assertNotNull($enhanced['correlation_evidence_score']);
        $this->assertGreaterThan(0.70, $enhanced['correlation_evidence_score']);
        $this->assertEquals('2.0', $enhanced['provenance']['version']);
    }

    /**
     * Test 3: Temporal correlation computes accurate time delta.
     */
    public function test_temporal_correlation_computes_accurate_time_delta(): void
    {
        $vessel = Vessel::create(['mmsi' => '412000333', 'name' => 'TEMPORAL TEST']);

        $timeOffsetMinutes = 14;
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $this->detection->latitude + 0.002,
            'longitude' => $this->detection->longitude + 0.002,
            'observed_at' => $this->detection->detected_at->copy()->subMinutes($timeOffsetMinutes),
            'received_at' => $this->detection->detected_at->copy()->subMinutes($timeOffsetMinutes),
        ]);

        $service = app(SarAisCorrelationService::class);
        $enhanced = $service->calculateEnhancedCorrelation($this->detection, 60);

        $this->assertEquals(14.0, $enhanced['temporal']['time_difference_minutes']);
        $this->assertEquals(840, $enhanced['temporal']['time_difference_seconds']);
    }

    /**
     * Test 4: Motion and heading consistency evaluation.
     */
    public function test_motion_and_heading_consistency_evaluation(): void
    {
        $vessel = Vessel::create(['mmsi' => '412000444', 'name' => 'MOTION TEST']);

        // Heading matching course within 5 degrees -> consistent
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => $this->detection->latitude + 0.005,
            'longitude' => $this->detection->longitude + 0.005,
            'speed_knots' => 12.0,
            'course_degrees' => 180.0,
            'heading_degrees' => 182.0,
            'observed_at' => $this->detection->detected_at->copy()->addMinutes(5),
            'received_at' => $this->detection->detected_at->copy()->addMinutes(5),
        ]);

        $service = app(SarAisCorrelationService::class);
        $enhanced = $service->calculateEnhancedCorrelation($this->detection, 60);

        $this->assertEquals('consistent', $enhanced['motion']['heading_consistency']);
        $this->assertEquals(12.0, $enhanced['motion']['sog_difference_knots']);
        $this->assertNotNull($enhanced['motion']['cog_difference_degrees']);
    }

    /**
     * Test 5: Missing AIS data returns null score and no_relevant_track.
     */
    public function test_missing_ais_data_returns_null_score_and_no_relevant_track(): void
    {
        // Zero AIS positions in database
        $service = app(SarAisCorrelationService::class);
        $enhanced = $service->calculateEnhancedCorrelation($this->detection, 60);

        $this->assertNull($enhanced['correlation_evidence_score']);
        $this->assertNull($enhanced['spatial']['nearest_ais_distance_meters']);
        $this->assertNull($enhanced['temporal']['time_difference_minutes']);
        $this->assertEquals('no_relevant_track', $enhanced['track_continuity']['trajectory']);
        $this->assertEquals('not_available', $enhanced['motion']['heading_consistency']);
    }

    /**
     * Test 6: GFW events 403 Forbidden is classified as ACCESS NOT AUTHORIZED.
     */
    public function test_gfw_events_403_forbidden_is_classified_as_access_not_authorized(): void
    {
        $service = app(SarEvidenceService::class);
        $evidence = $service->getEvidenceForDetection($this->detection);

        $this->assertEquals('FORBIDDEN', $evidence['gfw_status']['status']);
        $this->assertEquals(403, $evidence['gfw_status']['http_code']);
        $this->assertEquals('ACCESS NOT AUTHORIZED', $evidence['gfw_status']['label']);
        $this->assertStringContainsString('Ketiadaan data otorisasi bukan bukti ketiadaan kapal', $evidence['gfw_status']['description']);
    }

    /**
     * Test 7: Multi-source evidence matrix contains all nine operational sources.
     */
    public function test_multi_source_evidence_matrix_contains_all_nine_operational_sources(): void
    {
        $service = app(SarEvidenceService::class);
        $matrix = $service->getEvidenceMatrix($this->detection, false);

        $this->assertCount(9, $matrix);
        $sources = array_column($matrix, 'source');

        $this->assertContains('Sentinel-1 SAR', $sources);
        $this->assertContains('SAR Geolocation', $sources);
        $this->assertContains('SAR Signature', $sources);
        $this->assertContains('AIS Terrestrial', $sources);
        $this->assertContains('Satellite AIS', $sources);
        $this->assertContains('GFW Events', $sources);
        $this->assertContains('Sea Ice', $sources);
        $this->assertContains('Optical', $sources);
        $this->assertContains('Analyst Review', $sources);

        // Verify Sea Ice and Optical are strictly marked NOT AVAILABLE
        $seaIceRow = collect($matrix)->firstWhere('source', 'Sea Ice');
        $this->assertEquals('NOT AVAILABLE', $seaIceRow['status']);

        $opticalRow = collect($matrix)->firstWhere('source', 'Optical');
        $this->assertEquals('NOT AVAILABLE', $opticalRow['status']);
    }

    /**
     * Test 8: Three confidence levels separation and integrity.
     */
    public function test_three_confidence_levels_separation_and_integrity(): void
    {
        $response = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.show', $this->detection->id));

        $response->assertStatus(200);
        $response->assertSee('Level 1: Detection Confidence');
        $response->assertSee('Level 2: Correlation Evidence');
        $response->assertSee('Level 3: Analyst Assessment');
        $response->assertSee('Detection ≠ Vessel Confirmation');
        $response->assertSee('No AIS ≠ Dark Vessel');
        $response->assertSee('Possible Vessel ≠ Confirmed Vessel');
    }

    /**
     * Test 9: Viewer cannot submit review and gets 403.
     */
    public function test_viewer_cannot_submit_review_and_gets_403(): void
    {
        $payload = [
            'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
            'review_rationale' => 'Peninjauan oleh akun viewer yang tidak memiliki hak otorisasi peran analis maritim.',
        ];

        $response = $this->actingAs($this->viewer)
            ->post(route('dark-vessels.review', $this->detection->id), $payload);

        $response->assertStatus(403);
        $this->detection->refresh();
        $this->assertEquals(SatelliteDetection::REVIEW_PENDING, $this->detection->review_status);
    }

    /**
     * Test 10: Analyst review rationale requires minimum 20 characters.
     */
    public function test_analyst_review_rationale_requires_minimum_20_characters(): void
    {
        // 10 characters -> fails validation
        $responseShort = $this->actingAs($this->analyst)
            ->post(route('dark-vessels.review', $this->detection->id), [
                'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
                'review_rationale' => 'Terlalu pendek',
            ]);

        $responseShort->assertSessionHasErrors('review_rationale');

        // >= 20 characters -> succeeds
        $validRationale = 'Pola hamburan gelombang radar konsisten dengan target lambung kapal laut lepas (TCR 15.85 dB).';
        $responseValid = $this->actingAs($this->analyst)
            ->post(route('dark-vessels.review', $this->detection->id), [
                'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
                'review_rationale' => $validRationale,
            ]);

        $responseValid->assertRedirect(route('dark-vessels.show', $this->detection->id));
        $this->detection->refresh();
        $this->assertEquals(SatelliteDetection::REVIEW_POSSIBLE_VESSEL, $this->detection->review_status);
        $this->assertEquals($validRationale, $this->detection->review_rationale);
    }

    /**
     * Test 11: Analyst review checklist supports Yes / No / Unknown options.
     */
    public function test_analyst_review_checklist_supports_yes_no_unknown(): void
    {
        $checklist = [
            'vessel_signature' => 'yes',
            'dimensions_plausible' => 'yes',
            'tcr_sufficient' => 'yes',
            'sea_clutter_considered' => 'yes',
            'sea_ice_considered' => 'no',
            'ais_coverage_assessed' => 'yes',
            'ais_track_assessed' => 'unknown',
            'alternative_explanation_considered' => 'yes',
            'optical_verification_required' => 'unknown',
        ];

        $response = $this->actingAs($this->analyst)
            ->post(route('dark-vessels.review', $this->detection->id), [
                'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
                'review_rationale' => 'Evaluasi checklist forensik multikriteria membuktikan anomali radar layak dikaji lebih lanjut.',
                'checklist' => $checklist,
            ]);

        $response->assertRedirect(route('dark-vessels.show', $this->detection->id));
        $this->detection->refresh();

        $savedMeta = $this->detection->review_metadata;
        $this->assertArrayHasKey('checklist', $savedMeta);
        $this->assertEquals('yes', $savedMeta['checklist']['vessel_signature']);
        $this->assertEquals('no', $savedMeta['checklist']['sea_ice_considered']);
        $this->assertEquals('unknown', $savedMeta['checklist']['ais_track_assessed']);
    }

    /**
     * Test 12: Forensic audit trail records versioned provenance.
     */
    public function test_forensic_audit_trail_records_versioned_provenance(): void
    {
        $rationale = 'Evaluasi komprehensif peninjauan kandidat SAR dengan pencatatan jejak audit forensik permanen.';
        $this->actingAs($this->analyst)
            ->post(route('dark-vessels.review', $this->detection->id), [
                'review_status' => SatelliteDetection::REVIEW_POSSIBLE_ICE_CLUTTER,
                'review_rationale' => $rationale,
            ]);

        $log = AuditLog::where('entity_type', SatelliteDetection::class)
            ->where('entity_id', $this->detection->id)
            ->where('action', 'sar_candidate_review')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('2.0', $log->details['provenance_version']);
        $this->assertEquals($this->analyst->name, $log->details['reviewer_name']);
        $this->assertEquals(SatelliteDetection::REVIEW_POSSIBLE_ICE_CLUTTER, $log->details['new_status']);
    }

    /**
     * Test 13: Expanded CSV export separates algorithm from human analyst decision.
     */
    public function test_expanded_csv_export_separates_algorithm_from_human_analyst(): void
    {
        $response = $this->actingAs($this->analyst)->get(route('dark-vessels.export'));

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Algorithm columns
        $this->assertStringContainsString('Detection Confidence (Algorithm)', $content);
        $this->assertStringContainsString('AIS Correlation Status (Algorithm)', $content);
        $this->assertStringContainsString('Correlation Evidence Score (Algorithm)', $content);
        $this->assertStringContainsString('AIS Track Trajectory (Algorithm)', $content);

        // Provider columns
        $this->assertStringContainsString('GFW Events Status (Provider)', $content);
        $this->assertStringContainsString('Sea-Ice Status (Provider)', $content);
        $this->assertStringContainsString('Optical Verification Status (Provider)', $content);

        // Human analyst columns
        $this->assertStringContainsString('Analyst Review Status (Human Decision)', $content);
        $this->assertStringContainsString('Reviewer Name (Human Decision)', $content);
        $this->assertStringContainsString('Review Rationale (Human Decision)', $content);
    }

    /**
     * Test 14: MapLibre SAR layer and AIS track endpoints regression test.
     */
    public function test_maplibre_sar_layer_and_ais_track_regression(): void
    {
        // 1. SAR scenes layer
        $sceneResp = $this->actingAs($this->analyst)->getJson('/api/layers/sar-scenes');
        $sceneResp->assertStatus(200);
        $sceneResp->assertJsonPath('type', 'FeatureCollection');

        // 2. Dark vessels / SAR candidates layer
        $darkResp = $this->actingAs($this->analyst)->getJson('/api/layers/dark-vessels');
        $darkResp->assertStatus(200);
        $darkResp->assertJsonPath('type', 'FeatureCollection');

        // 3. AIS Track endpoint
        $trackResp = $this->getJson("/api/sar/candidates/{$this->detection->detection_identifier}/track?window=60");
        $trackResp->assertStatus(200);
        $trackResp->assertJsonPath('type', 'FeatureCollection');
    }
}
