<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SarAnalystReviewTest extends TestCase
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
            'name' => 'Chief Intelligence Officer',
        ]);

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'name' => 'Maritime Radar Analyst',
        ]);

        $this->viewer = User::factory()->create([
            'role' => 'viewer',
            'name' => 'External Port Viewer',
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
    }

    public function test_analyst_can_view_sar_candidate_list_and_detail(): void
    {
        $response = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.index'));

        $response->assertStatus(200);
        $response->assertSee('542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0001');
        $response->assertSee('15.9 dB');
        $response->assertSee('Pending Review');
        $response->assertSee('Insufficient Data');

        // Test Detail Page
        $detailResponse = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.show', $this->detection->id));

        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('Panel 2: Sentinel-1 SAR Evidence Viewer');
        $detailResponse->assertSee('Panel 4: Independent AIS Telemetry Correlation Evaluation');
        $detailResponse->assertSee('Panel 6: Analyst Decision &amp; Technical Rationale', false);
    }

    public function test_candidate_list_filters_by_review_status_and_tcr(): void
    {
        // Second detection with high TCR and possible_vessel
        $det2 = SatelliteDetection::create([
            'satellite_scene_id' => $this->scene->id,
            'detection_identifier' => '542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0002',
            'latitude' => -59.100000,
            'longitude' => 85.000000,
            'detected_at' => '2026-10-19 13:52:12',
            'tcr_db' => 22.50,
            'estimated_length_meters' => 150.0,
            'estimated_width_meters' => 50.0,
            'confidence_score' => 0.98,
            'correlation_status' => 'insufficient_data',
            'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
        ]);

        // Filter by review_status=possible_vessel
        $resp1 = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.index', ['review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL]));

        $resp1->assertStatus(200);
        $resp1->assertSee($det2->detection_identifier);
        $resp1->assertDontSee($this->detection->detection_identifier);

        // Filter by min_tcr=20.0
        $resp2 = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.index', ['min_tcr' => 20.0]));

        $resp2->assertStatus(200);
        $resp2->assertSee($det2->detection_identifier);
        $resp2->assertDontSee($this->detection->detection_identifier);
    }

    public function test_analyst_can_submit_review_decision_and_audit_log_is_recorded(): void
    {
        $payload = [
            'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
            'review_rationale' => 'Pola pantulan radar sangat tajam dengan kontras tinggi (TCR 15.85 dB) dan konsisten dengan superstruktur kapal nelayan laut lepas.',
            'evidence_notes' => 'Tunggal, simetris, elongasi sesuai orientasi lintasan radar.',
            'limitation_notes' => 'Tidak ada lapisan es laut padat berdasarkan data iklim regional.',
        ];

        $response = $this->actingAs($this->analyst)
            ->post(route('dark-vessels.review', $this->detection->id), $payload);

        $response->assertRedirect(route('dark-vessels.show', $this->detection->id));
        $response->assertSessionHas('success');

        $this->detection->refresh();
        $this->assertEquals(SatelliteDetection::REVIEW_POSSIBLE_VESSEL, $this->detection->review_status);
        $this->assertEquals($this->analyst->id, $this->detection->reviewed_by_user_id);
        $this->assertNotNull($this->detection->reviewed_at);
        $this->assertEquals($payload['review_rationale'], $this->detection->review_rationale);

        // Verify Audit Log entry
        $log = AuditLog::where('entity_type', SatelliteDetection::class)
            ->where('entity_id', $this->detection->id)
            ->where('action', 'sar_candidate_review')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->analyst->id, $log->user_id);
        $this->assertEquals(SatelliteDetection::REVIEW_PENDING, $log->details['previous_status']);
        $this->assertEquals(SatelliteDetection::REVIEW_POSSIBLE_VESSEL, $log->details['new_status']);
        $this->assertEquals($payload['review_rationale'], $log->details['rationale']);
    }

    public function test_viewer_is_forbidden_from_submitting_review(): void
    {
        $payload = [
            'review_status' => SatelliteDetection::REVIEW_POSSIBLE_VESSEL,
            'review_rationale' => 'Penilaian oleh akun penonton yang tidak berwenang.',
        ];

        $response = $this->actingAs($this->viewer)
            ->post(route('dark-vessels.review', $this->detection->id), $payload);

        $response->assertStatus(403);

        $this->detection->refresh();
        $this->assertEquals(SatelliteDetection::REVIEW_PENDING, $this->detection->review_status);
    }

    public function test_review_submission_validates_required_fields(): void
    {
        // Missing rationale & invalid status
        $response = $this->actingAs($this->analyst)
            ->post(route('dark-vessels.review', $this->detection->id), [
                'review_status' => 'invalid_status_value',
                'review_rationale' => '',
            ]);

        $response->assertSessionHasErrors(['review_status', 'review_rationale']);

        $this->detection->refresh();
        $this->assertEquals(SatelliteDetection::REVIEW_PENDING, $this->detection->review_status);
    }

    public function test_candidates_can_be_exported_to_csv_with_provenance(): void
    {
        $response = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.export'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Candidate ID', $content);
        $this->assertStringContainsString('Scene ID', $content);
        $this->assertStringContainsString('542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0001', $content);
        $this->assertStringContainsString('15.85', $content);
        $this->assertStringContainsString('insufficient_data', $content);

        // Verify audit log for export
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sar_candidate_export',
            'entity_type' => SatelliteDetection::class,
            'user_id' => $this->analyst->id,
        ]);
    }

    public function test_empty_state_rendered_when_no_candidates_match(): void
    {
        $response = $this->actingAs($this->analyst)
            ->get(route('dark-vessels.index', ['search' => 'NON_EXISTENT_IDENTIFIER']));

        $response->assertStatus(200);
        $response->assertSee('No SAR Detection Candidates Found');
    }
}
