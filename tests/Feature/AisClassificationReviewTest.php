<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselPosition;
use App\Services\DataRetentionPolicyService;
use App\Services\Providers\AisStreamAdapter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AisClassificationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $analystUser;
    protected DataRetentionPolicyService $retentionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Admin Reviewer',
            'email' => 'admin.reviewer@gfvip.org',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->analystUser = User::factory()->create([
            'name' => 'Analyst Reviewer',
            'email' => 'analyst.reviewer@gfvip.org',
            'role' => 'analyst',
            'is_active' => true,
        ]);

        $this->retentionService = app(DataRetentionPolicyService::class);
    }

    /**
     * TEST 01: Admin can open Classification Review page.
     */
    public function test_01_admin_can_open_classification_review(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('vessels.classification-review.index'));

        $response->assertStatus(200);
        $response->assertSee('Classification Review &amp; Reconciliation', false);
        $response->assertSee('Analyst Review Desk');
    }

    /**
     * TEST 02: Unauthorized users cannot open Classification Review page.
     */
    public function test_02_unauthorized_user_cannot_open_classification_review(): void
    {
        // Unauthenticated
        $guestResponse = $this->get(route('vessels.classification-review.index'));
        $guestResponse->assertRedirect(route('login'));

        // Inactive user
        $inactiveUser = User::factory()->create([
            'role' => 'analyst',
            'is_active' => false,
        ]);
        $inactiveResponse = $this->actingAs($inactiveUser)->get(route('vessels.classification-review.index'));
        $inactiveResponse->assertStatus(403);
    }

    /**
     * TEST 03: MMSI search works accurately on server-side query.
     */
    public function test_03_mmsi_search_works(): void
    {
        $v1 = Vessel::create(['name' => 'TARGET VESSEL A', 'mmsi' => '525111222', 'vessel_type' => 'fishing']);
        $v2 = Vessel::create(['name' => 'OTHER VESSEL B', 'mmsi' => '525999888', 'vessel_type' => 'cargo']);

        $response = $this->actingAs($this->adminUser)->get(route('vessels.classification-review.index', ['search' => '525111222']));

        $response->assertStatus(200);
        $response->assertSee('TARGET VESSEL A');
        $response->assertSee('525111222');
        $response->assertDontSee('OTHER VESSEL B');
    }

    /**
     * TEST 04: Filter Disputed works and isolates vessels with active conflict.
     */
    public function test_04_filter_disputed_works(): void
    {
        // Disputed: Fishing claimed, but AIS 70
        $disputedVessel = Vessel::create([
            'name' => 'KM CONFLICT 70',
            'mmsi' => '525000770',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 70,
            'verification_status' => 'unverified',
        ]);

        // Clean: Cargo claimed, AIS 70
        $cleanVessel = Vessel::create([
            'name' => 'MV CLEAN CARGO',
            'mmsi' => '525000771',
            'vessel_type' => 'cargo',
            'ais_ship_type_code' => 70,
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('vessels.classification-review.index', ['conflict_status' => 'disputed_only']));

        $response->assertStatus(200);
        $response->assertSee('KM CONFLICT 70');
        $response->assertDontSee('MV CLEAN CARGO');
    }

    /**
     * TEST 05: Fishing with AIS ShipType 30 can be verified.
     */
    public function test_05_fishing_with_shiptype_30_can_be_verified(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM SEGAR SAMUDRA',
            'mmsi' => '525100030',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'verification_status' => 'unverified',
        ]);

        $response = $this->actingAs($this->analystUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_fishing',
            'reason' => 'Konfirmasi fisik dan telemetri AIS ShipType 30 valid dari dermaga Muara Baru.',
            'evidence' => 'Laporan inspeksi pelabuhan No. REG-MB-2026-088.',
        ]);

        $response->assertRedirect(route('vessels.classification-review.show', $vessel->id));
        $response->assertSessionHas('success');

        $vessel->refresh();
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals('verified', $vessel->verification_status);
        $this->assertEquals('ANALYST_VERIFICATION', $vessel->classification_source);
        $this->assertTrue($vessel->isVerifiedFishing());
    }

    /**
     * TEST 06: Fishing with null AIS ShipType can be verified based on valid evidence & analyst decision.
     */
    public function test_06_fishing_with_null_shiptype_can_be_verified_by_analyst(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM TRADISIONAL 01',
            'mmsi' => '525100000',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => null,
            'verification_status' => 'unverified',
        ]);

        $response = $this->actingAs($this->analystUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_fishing',
            'reason' => 'Verifikasi izin tangkap KKP SIMKP dan buku kapal sah.',
            'evidence' => 'SIUP Perikanan Tangkap No. 523/KKP/2026.',
        ]);

        $response->assertRedirect(route('vessels.classification-review.show', $vessel->id));

        $vessel->refresh();
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals('verified', $vessel->verification_status);
        $this->assertEquals('ANALYST_VERIFICATION', $vessel->classification_source);
        $this->assertTrue($vessel->isVerifiedFishing());
    }

    /**
     * TEST 07: Fishing with AIS ShipType 70 remains Disputed and verify_fishing action is strictly rejected.
     */
    public function test_07_fishing_with_shiptype_70_remains_disputed_and_verify_fishing_rejected(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM FAKE FISHING 70',
            'mmsi' => '525100070',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 70,
            'verification_status' => 'unverified',
        ]);

        $this->assertTrue($vessel->isClassificationDisputed());

        $response = $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_fishing',
            'reason' => 'Mencoba memverifikasi fishing padahal kode AIS adalah 70.',
            'evidence' => 'Dokumen meragukan.',
        ]);

        $response->assertSessionHasErrors('action');

        $vessel->refresh();
        $this->assertNotEquals('verified', $vessel->verification_status);
        $this->assertTrue($vessel->isClassificationDisputed());
        $this->assertFalse($vessel->isVerifiedFishing());
    }

    /**
     * TEST 08: Fishing with AIS ShipType 80 remains Disputed and verify_fishing action is strictly rejected.
     */
    public function test_08_fishing_with_shiptype_80_remains_disputed_and_verify_fishing_rejected(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM TANKER LABELED FISHING 80',
            'mmsi' => '525100080',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 80,
            'verification_status' => 'unverified',
        ]);

        $this->assertTrue($vessel->isClassificationDisputed());

        $response = $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_fishing',
            'reason' => 'Mencoba memverifikasi tanker sebagai fishing.',
            'evidence' => 'Tanpa dasar.',
        ]);

        $response->assertSessionHasErrors('action');

        $vessel->refresh();
        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertTrue($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 09: Disputed vessels never become purge candidates.
     */
    public function test_09_disputed_vessels_never_enter_purge_candidates(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM DISPUTED PURGE TEST',
            'mmsi' => '525100099',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 70,
            'verification_status' => 'verified', // even if marked verified
        ]);

        $this->assertTrue($vessel->isClassificationDisputed());
        $this->assertTrue($vessel->isUnderProtectiveHold());

        $oldDate = now()->subDays(180);
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -6.0,
            'longitude' => 106.8,
            'observed_at' => $oldDate,
            'received_at' => $oldDate,
        ]);

        $candidates = $this->retentionService->getCandidatePositionsQuery();

        $this->assertEquals(0, $candidates['count']);
        $this->assertGreaterThan(0, $candidates['total_held']);
    }

    /**
     * TEST 10: Unverified Historical vessels remain in Protective Hold.
     */
    public function test_10_unverified_historical_remains_protective_hold(): void
    {
        $vessel = Vessel::create([
            'name' => 'WESERLOTSE',
            'mmsi' => '211233660', // One of the 5 historical MMSIs
            'vessel_type' => 'fishing',
            'verification_status' => 'unverified_historical',
        ]);

        $this->assertTrue($vessel->isHistoricalProtected());
        $this->assertTrue($vessel->isUnderProtectiveHold());
        $this->assertFalse($vessel->isVerifiedFishing());
    }

    /**
     * TEST 11: Provider Reported without evidence does not automatically become Verified.
     */
    public function test_11_provider_reported_without_evidence_does_not_automatically_become_verified(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM UNVERIFIED RAW FEED',
            'mmsi' => '525111000',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => null,
            'verification_status' => 'provider_reported',
        ]);

        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertTrue($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 12: Decisions without meaningful reason are rejected.
     */
    public function test_12_decision_without_reason_is_rejected(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM TEST REASON',
            'mmsi' => '525222000',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
        ]);

        // Empty reason
        $res1 = $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_fishing',
            'reason' => '',
            'evidence' => 'Some evidence',
        ]);
        $res1->assertSessionHasErrors('reason');

        // Short reason (< 5 chars)
        $res2 = $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_fishing',
            'reason' => 'ok',
            'evidence' => 'Some evidence',
        ]);
        $res2->assertSessionHasErrors('reason');
    }

    /**
     * TEST 13: Classification decision creates an append-only audit log.
     */
    public function test_13_classification_decision_creates_audit_log(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM AUDIT LOG TEST',
            'mmsi' => '525333000',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_non_fishing',
            'target_vessel_type' => 'cargo',
            'reason' => 'Identifikasi sertifikat IMO dan manifest barang pelabuhan Tanjung Priok.',
            'evidence' => 'B/L No. 99281-JKT.',
        ]);

        $log = AuditLog::where('entity_id', (string) $vessel->id)->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertEquals('vessel_classification_review', $log->action);
        $this->assertEquals($this->adminUser->id, $log->user_id);
        $this->assertEquals('verify_non_fishing', $log->details['action']);
        $this->assertEquals('unknown', $log->details['previous_vessel_type']);
        $this->assertEquals('cargo', $log->details['new_vessel_type']);
        $this->assertEquals('unverified', $log->details['previous_verification_status']);
        $this->assertEquals('verified', $log->details['new_verification_status']);
    }

    /**
     * TEST 14: Historical decisions are never deleted (append-only history preserved).
     */
    public function test_14_historical_decisions_are_not_deleted(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM RECLASSIFY CHAIN',
            'mmsi' => '525444000',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        // Decision 1: Verify non-fishing as cargo
        $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_non_fishing',
            'target_vessel_type' => 'cargo',
            'reason' => 'Penetapan awal kargo kontainer.',
            'evidence' => 'Bukti 1.',
        ]);

        // Decision 2: Reclassify to tug
        $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'reclassify',
            'target_vessel_type' => 'tug',
            'reason' => 'Koreksi: Kapal adalah kapal tunda pemandu kargo.',
            'evidence' => 'Sertifikat klasifikasi Biro Klasifikasi Indonesia.',
        ]);

        $vessel->refresh();
        $history = $vessel->classification_history;

        $this->assertCount(2, $history);
        $this->assertEquals('verify_non_fishing', $history[0]['action']);
        $this->assertEquals('reclassify', $history[1]['action']);
        $this->assertEquals('cargo', $history[1]['previous_vessel_type']);
        $this->assertEquals('tug', $history[1]['new_vessel_type']);

        $auditCount = AuditLog::where('entity_id', (string) $vessel->id)->count();
        $this->assertEquals(2, $auditCount);
    }

    /**
     * TEST 15: Older AIS metadata does not overwrite newer analyst decision.
     */
    public function test_15_older_ais_metadata_does_not_overwrite_newer_analyst_decision(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM PROTECTED ANALYST',
            'mmsi' => '525555000',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'verification_status' => 'verified',
            'classification_source' => 'ANALYST_VERIFICATION',
            'classified_at' => now()->subHours(2),
        ]);

        $adapter = app(AisStreamAdapter::class);
        $staleObservedAt = now()->subHours(10); // Older message

        // Ingest stale static message directly
        $adapter->ingestStaticMessage([
            'mmsi' => '525555000',
            'ship_type' => 70, // Contradictory old cargo code
            'observed_at' => $staleObservedAt,
            'classification_source' => 'AIS_TYPE_5',
            'vessel_name' => 'OLD AIS NAME',
        ]);

        $vessel->refresh();
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals('verified', $vessel->verification_status);
        $this->assertEquals('ANALYST_VERIFICATION', $vessel->classification_source);
    }

    /**
     * TEST 16: Contradictory newer AIS metadata triggers review without erasing previous analyst decision.
     */
    public function test_16_contradictory_newer_ais_metadata_triggers_review(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM VERIFIED ANALYST',
            'mmsi' => '525666000',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'verification_status' => 'verified',
            'classification_source' => 'ANALYST_VERIFICATION',
            'classified_at' => now()->subHours(5),
        ]);

        $adapter = app(AisStreamAdapter::class);
        $newerObservedAt = now()->subHours(1); // Newer message

        $adapter->ingestStaticMessage([
            'mmsi' => '525666000',
            'ship_type' => 70, // Contradictory new Cargo code
            'observed_at' => $newerObservedAt,
            'classification_source' => 'AIS_TYPE_5',
            'vessel_name' => 'NEW CARGO AIS NAME',
        ]);

        $vessel->refresh();
        // Previous analyst classification 'fishing' is preserved, but status moves to under_review
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals('under_review', $vessel->verification_status);
        $this->assertEquals(70, $vessel->ais_ship_type_code);
        $this->assertTrue($vessel->isClassificationDisputed());
        $this->assertTrue($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 17: Classification change does not delete AIS positions.
     */
    public function test_17_classification_change_does_not_delete_ais_positions(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM POSITIONS RETENTION TEST',
            'mmsi' => '525777000',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        for ($i = 0; $i < 5; $i++) {
            VesselPosition::create([
                'vessel_id' => $vessel->id,
                'latitude' => -5.0 + ($i * 0.1),
                'longitude' => 106.0 + ($i * 0.1),
                'observed_at' => now()->subDays($i + 1),
                'received_at' => now()->subDays($i + 1),
            ]);
        }

        $this->assertEquals(5, $vessel->positions()->count());

        $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
            'action' => 'verify_non_fishing',
            'target_vessel_type' => 'cargo',
            'reason' => 'Perubahan klasifikasi ke kargo.',
            'evidence' => 'Valid port clearance.',
        ]);

        $this->assertEquals(5, $vessel->positions()->count());
    }

    /**
     * TEST 18: Classification change does not delete SAR, Encounter, and investigation evidence.
     */
    public function test_18_classification_change_does_not_delete_sar_encounter_and_investigation_evidence(): void
    {
        $v1 = Vessel::create(['name' => 'KM OPERATIONAL EVIDENCE 1', 'mmsi' => '525888001', 'vessel_type' => 'fishing']);
        $v2 = Vessel::create(['name' => 'KM OPERATIONAL EVIDENCE 2', 'mmsi' => '525888002', 'vessel_type' => 'cargo']);

        // 1. Encounter
        $encounter = VesselEncounter::create([
            'encounter_code' => 'ENC-TEST-001',
            'vessel_1_id' => $v1->id,
            'vessel_2_id' => $v2->id,
            'category' => 'rendezvous',
            'status' => 'confirmed',
            'start_time' => now()->subDays(5),
            'end_time' => now()->subDays(5)->addHours(2),
            'latitude' => -5.2,
            'longitude' => 106.2,
            'min_distance_meters' => 30,
            'median_distance_meters' => 50,
            'parameters_used' => json_encode(['threshold_meters' => 500]),
        ]);

        // 2. Investigation
        $investigation = Investigation::create([
            'case_number' => 'INV-DARK-001',
            'title' => 'Investigasi IUU KM 1',
            'target_vessel_id' => $v1->id,
            'status' => 'open',
            'priority' => 'critical',
            'summary' => 'Investigasi transshipment ilegal.',
            'created_by_user_id' => $this->adminUser->id,
        ]);

        // 3. SAR Detection
        $scene = SatelliteScene::create([
            'scene_id' => 'SCENE-TEST-001',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'SAR-C',
            'acquisition_start' => now()->subDays(2),
            'acquisition_end' => now()->subDays(2),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'processing_status' => 'completed',
        ]);

        $sar = SatelliteDetection::create([
            'satellite_scene_id' => $scene->id,
            'detection_identifier' => 'DET-001',
            'correlated_vessel_id' => $v1->id,
            'confidence_score' => 0.95,
            'latitude' => -5.5,
            'longitude' => 106.5,
            'detected_at' => now()->subDays(2),
        ]);

        // Reclassify v1
        $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $v1->id), [
            'action' => 'reclassify',
            'target_vessel_type' => 'tanker',
            'reason' => 'Koreksi intelijen: Kapal tanker pengisi bahan bakar liar.',
            'evidence' => 'Rekaman video patroli maritim.',
        ]);

        $this->assertDatabaseHas('vessel_encounters', ['id' => $encounter->id]);
        $this->assertDatabaseHas('investigations', ['id' => $investigation->id]);
        $this->assertDatabaseHas('satellite_detections', ['id' => $sar->id]);
    }

    /**
     * TEST 19: Audit log failure rolls back the entire decision transaction.
     */
    public function test_19_audit_log_failure_rolls_back_decision_transaction(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM TRANSACTION ROLLBACK TEST',
            'mmsi' => '525999000',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        // Drop or temporarily sabotage audit_logs or trigger error in AuditLog create
        // We simulate this by mocking AuditLog or using a temporary DB trigger/failing event
        AuditLog::saving(function () {
            throw new \RuntimeException('Database storage error on audit_logs table simulation');
        });

        try {
            $this->actingAs($this->adminUser)->post(route('vessels.classification-review.decision', $vessel->id), [
                'action' => 'verify_non_fishing',
                'target_vessel_type' => 'cargo',
                'reason' => 'Uji coba rollback transaksi.',
                'evidence' => 'Bukti transaksi.',
            ]);
        } catch (\Throwable $e) {
            // Caught runtime exception
        }

        $vessel->refresh();
        // State must remain unverified and unknown because transaction rolled back!
        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertEquals('unverified', $vessel->verification_status);
    }

    /**
     * TEST 20: Model scopes and retention service queries remain consistent.
     */
    public function test_20_model_scopes_and_retention_service_queries_remain_consistent(): void
    {
        $v1 = Vessel::create(['name' => 'F1', 'mmsi' => '525000001', 'vessel_type' => 'fishing', 'ais_ship_type_code' => 30]);
        $v2 = Vessel::create(['name' => 'F2', 'mmsi' => '525000002', 'vessel_type' => 'fishing', 'verification_status' => 'verified']);
        $v3 = Vessel::create(['name' => 'F3', 'mmsi' => '525000003', 'vessel_type' => 'fishing', 'ais_ship_type_code' => 70]); // Disputed
        $v4 = Vessel::create(['name' => 'C1', 'mmsi' => '525000004', 'vessel_type' => 'cargo', 'ais_ship_type_code' => 70, 'verification_status' => 'verified']);

        $serviceVerified = $this->retentionService->getVerifiedFishingVesselIds();
        $serviceHold = $this->retentionService->getProtectiveHoldVesselIds();

        $this->assertTrue($v1->isVerifiedFishing());
        $this->assertContains($v1->id, $serviceVerified);

        $this->assertTrue($v2->isVerifiedFishing());
        $this->assertContains($v2->id, $serviceVerified);

        $this->assertFalse($v3->isVerifiedFishing());
        $this->assertNotContains($v3->id, $serviceVerified);
        $this->assertTrue($v3->isUnderProtectiveHold());
        $this->assertContains($v3->id, $serviceHold);

        $this->assertFalse($v4->isVerifiedFishing());
        $this->assertNotContains($v4->id, $serviceVerified);
    }
}
