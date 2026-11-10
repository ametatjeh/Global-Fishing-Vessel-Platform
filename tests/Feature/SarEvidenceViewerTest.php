<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SarEvidenceViewerTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $analyst;
    protected User $viewer;
    protected SatelliteScene $scene;
    protected SatelliteDetection $detection;
    protected string $testEvidenceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'name' => 'Chief Intelligence Officer',
        ]);

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Maritime Radar Analyst',
        ]);

        $this->viewer = User::factory()->create([
            'role' => 'viewer',
            'name' => 'Port Authority Viewer',
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
            'received_at' => now(),
            'tcr_db' => 15.85,
            'estimated_length_meters' => 226.3,
            'estimated_width_meters' => 84.9,
            'confidence_score' => 0.90,
            'correlation_status' => 'insufficient_data',
            'review_status' => SatelliteDetection::REVIEW_PENDING,
            'correlation_method' => '2D_CA_CFAR_GEOLOC_GRID',
            'review_metadata' => [
                'target_id' => 'SAR-TGT-0001',
                'pixel_count' => 12,
                'pixel_coordinates' => ['row' => 82.5, 'col' => 6276.5],
            ],
        ]);

        // Mock test evidence crop directory
        $this->testEvidenceDir = storage_path('app/sar_evidence/542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7/542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0001');
        if (!File::exists($this->testEvidenceDir)) {
            File::makeDirectory($this->testEvidenceDir, 0755, true, true);
        }

        // Create 1x1 test PNGs
        $dummyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        File::put("{$this->testEvidenceDir}/raw.png", $dummyPng);
        File::put("{$this->testEvidenceDir}/sigma0-linear.png", $dummyPng);
        File::put("{$this->testEvidenceDir}/sigma0-db.png", $dummyPng);

        // Create dummy metadata.json
        $meta = [
            'candidate_identifier' => '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0001',
            'target_id' => 'SAR-TGT-0001',
            'source_product' => 'S1C_EW_GRDM_1SSH_20261019T135212_20261019T135236_009954_013D8A_0AA8.SAFE',
            'source_measurement_file' => 's1c-ew-grd-hh-20261019t135212-20261019t135236-009954-013d8a-001.tiff',
            'radar_measurements' => [
                'peak_raw_dn' => 874.0,
                'peak_sigma0_db' => -3.70,
                'background_clutter_db' => -19.73,
                'tcr_db' => 15.85,
            ],
            'global_pixel_coordinates' => ['line_row' => 82, 'sample_col' => 6276],
            'crop_window' => ['width_px' => 61, 'height_px' => 61],
        ];
        File::put("{$this->testEvidenceDir}/metadata.json", json_encode($meta, JSON_PRETTY_PRINT));
    }

    public function test_api_sar_candidate_evidence_endpoint_returns_valid_structure_without_secrets(): void
    {
        $response = $this->actingAs($this->analyst)->getJson("/api/sar/candidates/{$this->detection->id}/evidence");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'candidate_id',
            'candidate_identifier',
            'scene_id',
            'source' => [
                'product_id',
                'platform',
                'instrument',
                'sensor_mode',
                'polarization',
                'acquisition_time_utc',
            ],
            'raster' => [
                'source_measurement',
                'source_crs',
                'output_crs',
                'pixel_spacing_meters',
                'effective_spatial_resolution_meters',
                'crop_window_px',
                'crop_extent_meters',
            ],
            'target' => [
                'latitude',
                'longitude',
                'tcr_db',
                'confidence_score',
                'estimated_length_meters',
                'estimated_width_meters',
                'pixel_count',
            ],
            'evidence_urls' => [
                'raw_amplitude',
                'sigma0_linear',
                'sigma0_db',
                'metadata_json',
            ],
            'indicators',
            'correlation' => [
                'status',
                'integrity_notice',
            ],
            'review',
            'disclaimer',
        ]);

        $content = $response->getContent();
        // Zero secret leak
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('token', $content);
        $this->assertStringNotContainsString('secret', $content);
    }

    public function test_authenticated_user_can_access_evidence_images(): void
    {
        // Unauthenticated access redirects to login
        $guestResp = $this->get(route('sar.evidence.image', ['candidate' => $this->detection->detection_identifier, 'type' => 'raw']));
        $guestResp->assertRedirect(route('login'));

        // Authenticated access succeeds
        $respRaw = $this->actingAs($this->analyst)
            ->get(route('sar.evidence.image', ['candidate' => $this->detection->detection_identifier, 'type' => 'raw']));
        $respRaw->assertStatus(200);
        $respRaw->assertHeader('content-type', 'image/png');

        $respLin = $this->actingAs($this->analyst)
            ->get(route('sar.evidence.image', ['candidate' => $this->detection->detection_identifier, 'type' => 'sigma0-linear']));
        $respLin->assertStatus(200);
        $respLin->assertHeader('content-type', 'image/png');

        $respDb = $this->actingAs($this->analyst)
            ->get(route('sar.evidence.image', ['candidate' => $this->detection->detection_identifier, 'type' => 'sigma0-db']));
        $respDb->assertStatus(200);
        $respDb->assertHeader('content-type', 'image/png');

        $respMeta = $this->actingAs($this->analyst)
            ->get(route('sar.evidence.image', ['candidate' => $this->detection->detection_identifier, 'type' => 'metadata']));
        $respMeta->assertStatus(200);
        $respMeta->assertHeader('content-type', 'application/json');
    }

    public function test_analyst_review_inspector_displays_all_six_panels(): void
    {
        $response = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.show', $this->detection->id));

        $response->assertStatus(200);
        // Panel 1: Candidate Summary
        $response->assertSee('Stage 8 Evidence-Based Inspector');
        $response->assertSee('Radar-object dimensions are estimated from SAR backscatter');
        // Panel 2: SAR Image Evidence
        $response->assertSee('Panel 2: Sentinel-1 SAR Evidence Viewer');
        $response->assertSee('Raw Amplitude');
        $response->assertSee('Sigma⁰ Linear');
        $response->assertSee('Sigma⁰ dB');
        // Panel 3: MapLibre Spatial Context
        $response->assertSee('Panel 3: MapLibre Spatial Context &amp; AIS Track', false);
        $response->assertSee('candidate-detail-map');
        // Panel 4: AIS Correlation
        $response->assertSee('Panel 4: Independent AIS Telemetry Correlation Evaluation');
        $response->assertSee('No AIS correlation available');
        $response->assertSee('Absence of AIS data does not independently establish that the target is a dark vessel');
        // Panel 5: Checklist
        $response->assertSee('Panel 5: Maritime Analyst Evidence Checklist');
        // Panel 6: Decision
        $response->assertSee('Panel 6: Analyst Decision &amp; Technical Rationale', false);
    }

    public function test_analyst_can_submit_review_with_checklist_and_audit_trail_records_it(): void
    {
        $payload = [
            'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
            'review_rationale' => 'Analisis mendalam terhadap crop citra SAR menunjukkan kontras tajam (TCR 15.85 dB) konsisten dengan target kapal laut lepas.',
            'evidence_notes' => 'Pola elips simetris pada citra raw amplitude, tidak ada bayangan es laut.',
            'limitation_notes' => 'Ketiadaan stasiun AIS di lintang tinggi Samudra Selatan.',
            'checklist' => [
                'vessel_signature' => 1,
                'additional_evidence_needed' => 1,
            ],
        ];

        $response = $this->actingAs($this->analyst)
            ->post(route('dark-vessels.review', $this->detection->id), $payload);

        $response->assertRedirect(route('dark-vessels.show', $this->detection->id));
        $response->assertSessionHas('success');

        $this->detection->refresh();
        $this->assertEquals(SatelliteDetection::REVIEW_POSSIBLE_VESSEL, $this->detection->review_status);
        $this->assertEquals($this->analyst->id, $this->detection->reviewed_by_user_id);

        $meta = $this->detection->review_metadata;
        $this->assertArrayHasKey('checklist', $meta);
        $this->assertEquals(1, $meta['checklist']['vessel_signature']);

        // Verify Audit Log has checklist
        $log = AuditLog::where('entity_type', SatelliteDetection::class)
            ->where('entity_id', $this->detection->id)
            ->where('action', 'sar_candidate_review')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('checklist', $log->details);
    }

    public function test_missing_sar_crop_is_handled_gracefully(): void
    {
        // Candidate with nonexistent evidence directory
        $orphan = SatelliteDetection::create([
            'satellite_scene_id' => $this->scene->id,
            'detection_identifier' => '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-9999',
            'latitude' => -58.000000,
            'longitude' => 82.000000,
            'detected_at' => now(),
            'correlation_status' => 'insufficient_data',
            'review_status' => SatelliteDetection::REVIEW_PENDING,
        ]);

        $response = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.show', $orphan->id));

        $response->assertStatus(200);
        $response->assertSee('SAR Raster Crop Unavailable');

        // Requesting missing image returns 404
        $imgResp = $this->actingAs($this->analyst)
            ->get(route('sar.evidence.image', ['candidate' => $orphan->detection_identifier, 'type' => 'raw']));
        $imgResp->assertStatus(404);
    }

    public function test_evidence_endpoint_accepts_compound_detection_identifier(): void
    {
        // Querying with non-UUID compound identifier like "542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0001"
        $response = $this->actingAs($this->analyst)->getJson("/api/sar/candidates/{$this->detection->detection_identifier}/evidence");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'candidate_identifier' => $this->detection->detection_identifier,
        ]);
    }

    public function test_track_endpoint_accepts_compound_detection_identifier(): void
    {
        $response = $this->actingAs($this->analyst)->getJson("/api/sar/candidates/{$this->detection->detection_identifier}/track?window=60");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertEquals(60, $data['properties']['window_minutes']);
    }

    public function test_evidence_endpoint_returns_404_for_unknown_identifier(): void
    {
        $response = $this->actingAs($this->analyst)->getJson('/api/sar/candidates/non-existent-candidate-identifier/evidence');

        $response->assertStatus(404);
        $response->assertJson([
            'status' => 'error',
        ]);
    }

    public function test_track_endpoint_returns_422_for_invalid_window(): void
    {
        $response = $this->actingAs($this->analyst)->getJson("/api/sar/candidates/{$this->detection->detection_identifier}/track?window=invalid_window");

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Invalid window parameter.',
        ]);
    }

    public function test_track_endpoint_returns_404_for_unknown_identifier(): void
    {
        $response = $this->actingAs($this->analyst)->getJson('/api/sar/candidates/non-existent-candidate-identifier/track');

        $response->assertStatus(404);
        $response->assertJson([
            'status' => 'error',
        ]);
    }
}
