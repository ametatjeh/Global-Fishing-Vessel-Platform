<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\ComplianceRule;
use App\Models\Investigation;
use App\Models\ProhibitedVessel;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselAuthorization;
use App\Models\VesselIdentifier;
use App\Services\Research\PrivateInvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PrivateInvestigationResearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $researchLeadNgo;
    protected User $researchLeadUniv;
    protected User $researcherA;
    protected User $researcherB;
    protected User $institutionalResearcher;
    protected User $deactivatedResearcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'name' => 'Chief Intelligence Director',
            'email' => 'director@gfvip-research.org',
            'role' => 'super_admin',
            'organization' => 'GFVIP Directorate',
            'is_active' => true,
        ]);

        $this->researchLeadNgo = User::factory()->create([
            'name' => 'Dr. Maya Lestari',
            'email' => 'maya.lestari@ocean-ngo.org',
            'role' => 'research_lead',
            'organization' => 'Ocean Guardian NGO',
            'is_active' => true,
        ]);

        $this->researchLeadUniv = User::factory()->create([
            'name' => 'Prof. Budi Santoso',
            'email' => 'budi.santoso@maritime-univ.ac.id',
            'role' => 'research_lead',
            'organization' => 'Maritime University Center',
            'is_active' => true,
        ]);

        $this->researcherA = User::factory()->create([
            'name' => 'Rina Wijaya',
            'email' => 'rina.wijaya@ocean-ngo.org',
            'role' => 'researcher',
            'organization' => 'Ocean Guardian NGO',
            'is_active' => true,
        ]);

        $this->researcherB = User::factory()->create([
            'name' => 'Agus Prasetyo',
            'email' => 'agus.p@maritime-univ.ac.id',
            'role' => 'researcher',
            'organization' => 'Maritime University Center',
            'is_active' => true,
        ]);

        $this->institutionalResearcher = User::factory()->create([
            'name' => 'External Academic Fellow',
            'email' => 'fellow@partner-institute.org',
            'role' => 'institutional_researcher',
            'organization' => 'Partner Institute',
            'is_active' => true,
        ]);

        $this->deactivatedResearcher = User::factory()->create([
            'name' => 'Deactivated Researcher',
            'email' => 'revoked@partner-institute.org',
            'role' => 'researcher',
            'organization' => 'Partner Institute',
            'is_active' => false,
        ]);
    }

    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $response = $this->get('/investigations');
        $response->assertRedirect('/login');

        $responseDetail = $this->get('/investigations/1');
        $responseDetail->assertRedirect('/login');
    }

    public function test_deactivated_or_viewer_user_cannot_access_investigations(): void
    {
        $this->actingAs($this->deactivatedResearcher);
        $response = $this->get('/investigations');
        // Middleware EnsureUserRole redirects or denies inactive users
        $this->assertTrue($response->isRedirection() || $response->isForbidden());
    }

    public function test_object_level_isolation_between_unrelated_researchers(): void
    {
        // Researcher A creates an investigation
        $investigation = Investigation::create([
            'case_number' => 'INV-TEST-0001',
            'title' => 'Investigasi Privat Tim NGO',
            'priority' => 'high',
            'status' => 'open',
            'confidentiality_level' => 'private',
            'organization' => 'Ocean Guardian NGO',
            'summary' => 'Dossier terbatas tim NGO.',
            'created_by_user_id' => $this->researcherA->id,
            'assigned_to_user_id' => $this->researcherA->id,
        ]);

        // Researcher B (from another organization) attempts to view Researcher A's investigation
        $this->actingAs($this->researcherB);
        $response = $this->get("/investigations/{$investigation->id}");
        $response->assertStatus(403);

        // In listing, Researcher B should NOT see Researcher A's investigation
        $listResponse = $this->get('/investigations');
        $listResponse->assertStatus(200);
        $listResponse->assertDontSee('INV-TEST-0001');

        // Researcher A can access their own investigation
        $this->actingAs($this->researcherA);
        $ownerResponse = $this->get("/investigations/{$investigation->id}");
        $ownerResponse->assertStatus(200);
        $ownerResponse->assertSee('INV-TEST-0001');
    }

    public function test_collaborator_can_access_investigation(): void
    {
        $investigation = Investigation::create([
            'case_number' => 'INV-TEST-0002',
            'title' => 'Kolaborasi Bersama Kasus ZEE',
            'priority' => 'medium',
            'status' => 'open',
            'confidentiality_level' => 'restricted',
            'organization' => 'Ocean Guardian NGO',
            'summary' => 'Kasus lintas lembaga.',
            'created_by_user_id' => $this->researcherA->id,
            'assigned_to_user_id' => $this->researcherA->id,
            'collaborator_ids' => [$this->researcherB->id],
        ]);

        // Researcher B is an explicit collaborator, so access is granted
        $this->actingAs($this->researcherB);
        $response = $this->get("/investigations/{$investigation->id}");
        $response->assertStatus(200);
        $response->assertSee('INV-TEST-0002');
    }

    public function test_research_lead_can_access_investigations_within_own_organization(): void
    {
        // Investigation by Researcher A in Ocean Guardian NGO
        $ngoInvestigation = Investigation::create([
            'case_number' => 'INV-NGO-0003',
            'title' => 'Kasus Internal NGO',
            'priority' => 'high',
            'status' => 'open',
            'confidentiality_level' => 'private',
            'organization' => 'Ocean Guardian NGO',
            'summary' => 'Kasus kepatuhan armada.',
            'created_by_user_id' => $this->researcherA->id,
            'assigned_to_user_id' => $this->researcherA->id,
        ]);

        // Lead of Ocean Guardian NGO has access
        $this->actingAs($this->researchLeadNgo);
        $ngoResponse = $this->get("/investigations/{$ngoInvestigation->id}");
        $ngoResponse->assertStatus(200);

        // Lead of Maritime University does NOT have access to NGO investigation
        $this->actingAs($this->researchLeadUniv);
        $univResponse = $this->get("/investigations/{$ngoInvestigation->id}");
        $univResponse->assertStatus(403);
    }

    public function test_institutional_researcher_restricted_to_explicitly_assigned_cases(): void
    {
        $unassignedInv = Investigation::create([
            'case_number' => 'INV-UNASSIGNED-0004',
            'title' => 'Dossier Kampus Tanpa Fellow',
            'priority' => 'low',
            'status' => 'open',
            'confidentiality_level' => 'restricted',
            'organization' => 'Partner Institute',
            'summary' => 'Kasus institusi.',
            'created_by_user_id' => $this->researchLeadUniv->id,
            'assigned_to_user_id' => $this->researchLeadUniv->id,
        ]);

        $assignedInv = Investigation::create([
            'case_number' => 'INV-ASSIGNED-0005',
            'title' => 'Dossier Khusus Fellow',
            'priority' => 'medium',
            'status' => 'open',
            'confidentiality_level' => 'restricted',
            'organization' => 'Partner Institute',
            'summary' => 'Kasus fellow.',
            'created_by_user_id' => $this->researchLeadUniv->id,
            'assigned_to_user_id' => $this->institutionalResearcher->id,
        ]);

        $this->actingAs($this->institutionalResearcher);

        // Cannot view unassigned case
        $this->get("/investigations/{$unassignedInv->id}")->assertStatus(403);

        // Can view assigned case
        $this->get("/investigations/{$assignedInv->id}")->assertStatus(200);
    }

    public function test_super_admin_has_unrestricted_supervisory_access(): void
    {
        $inv = Investigation::create([
            'case_number' => 'INV-SECRET-0006',
            'title' => 'Top Confidential Operation',
            'priority' => 'urgent',
            'status' => 'open',
            'confidentiality_level' => 'strictly_confidential',
            'organization' => 'Special Unit',
            'summary' => 'Strict access.',
            'created_by_user_id' => $this->researcherA->id,
            'assigned_to_user_id' => $this->researcherA->id,
        ]);

        $this->actingAs($this->superAdmin);
        $response = $this->get("/investigations/{$inv->id}");
        $response->assertStatus(200);
        $response->assertSee('INV-SECRET-0006');
    }

    public function test_forensic_audit_trail_recorded_on_view_and_findings_update(): void
    {
        $inv = Investigation::create([
            'case_number' => 'INV-AUDIT-0007',
            'title' => 'Audit Trail Validation Case',
            'priority' => 'high',
            'status' => 'open',
            'confidentiality_level' => 'private',
            'organization' => 'Ocean Guardian NGO',
            'summary' => 'Validasi audit log.',
            'created_by_user_id' => $this->researcherA->id,
            'assigned_to_user_id' => $this->researcherA->id,
        ]);

        $this->actingAs($this->researcherA);

        // 1. View investigation
        $this->get("/investigations/{$inv->id}")->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'view_investigation',
            'entity_type' => 'investigation',
            'entity_id' => (string) $inv->id,
        ]);

        // 2. Update findings
        $updateResponse = $this->patch("/investigations/{$inv->id}/findings", [
            'verdict_status' => 'confirmed',
            'status' => 'in_progress',
            'findings' => 'Ditemukan bukti fisik AIS gap di perbatasan ZEE.',
            'recommendation' => 'Laporkan kepada instansi pengawas berwenang.',
            'new_evidence_title' => 'SAR Sentinel-1 Co-location Match',
            'new_evidence_notes' => 'Scene S1A_IW_GRDH korelasi jarak 1.2 km.',
        ]);

        $updateResponse->assertRedirect("/investigations/{$inv->id}");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update_investigation_findings',
            'entity_type' => 'investigation',
            'entity_id' => (string) $inv->id,
        ]);

        $inv->refresh();
        $this->assertSame('confirmed', $inv->verdict_status);
        $this->assertCount(1, $inv->evidence_matrix);
        $this->assertSame('SAR Sentinel-1 Co-location Match', $inv->evidence_matrix[0]['title']);
    }

    public function test_focus_a_identity_and_flag_hopping_analysis(): void
    {
        $vessel = Vessel::create([
            'name' => 'PACIFIC EXPLORER',
            'mmsi' => '525112233',
            'imo' => '9123456',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        // Prior flag record
        VesselIdentifier::create([
            'vessel_id' => $vessel->id,
            'identifier_type' => 'flag',
            'identifier_value' => 'PA',
            'is_current' => false,
            'valid_from' => Carbon::now()->subMonths(12),
            'valid_to' => Carbon::now()->subMonths(2),
            'metadata_json' => ['provenance' => 'IHS Maritime Historical Register'],
        ]);

        // Current flag record
        VesselIdentifier::create([
            'vessel_id' => $vessel->id,
            'identifier_type' => 'flag',
            'identifier_value' => 'ID',
            'is_current' => true,
            'valid_from' => Carbon::now()->subMonths(2),
            'metadata_json' => ['provenance' => 'Indonesian Fleet Registration Book'],
        ]);

        $service = app(PrivateInvestigationService::class);
        $analysis = $service->analyzeIdentityAndFlagHistory($vessel);

        $this->assertSame('vessel_identity_and_flag_hopping', $analysis['focus']);
        $this->assertTrue($analysis['flag_hopping_detected']);
        $this->assertContains('PA', $analysis['observed_flags']);
        $this->assertContains('ID', $analysis['observed_flags']);
        $this->assertCount(2, $analysis['identity_timeline']);
    }

    public function test_focus_b_corporate_network_and_beneficial_ownership(): void
    {
        $targetVessel = Vessel::create([
            'name' => 'OCEAN HARVEST 01',
            'mmsi' => '525990001',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
            'owner' => 'PT Samudera Bahari Raya',
            'operator' => 'Samudera Fleet Management Ltd',
            'metadata' => [
                'beneficial_owner' => 'Hendrik Tan (Verified Shareholder)',
                'ownership_confidence' => 'verified_legal_docs',
                'ownership_verified_at' => '2026-05-15',
            ],
        ]);

        $sisterVessel = Vessel::create([
            'name' => 'OCEAN HARVEST 02',
            'mmsi' => '525990002',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
            'owner' => 'PT Samudera Bahari Raya',
            'operator' => 'Samudera Fleet Management Ltd',
        ]);

        $service = app(PrivateInvestigationService::class);
        $analysis = $service->analyzeCorporateNetwork($targetVessel);

        $this->assertSame('corporate_network_and_beneficial_ownership', $analysis['focus']);
        $this->assertSame('PT Samudera Bahari Raya', $analysis['registered_owner']);
        $this->assertSame('Hendrik Tan (Verified Shareholder)', $analysis['beneficial_owner']);
        $this->assertSame('verified_legal_docs', $analysis['confidence_level']);
        $this->assertSame(1, $analysis['related_fleet_count']);
        $this->assertSame('OCEAN HARVEST 02', $analysis['related_sister_vessels'][0]['name']);
        $this->assertStringContainsString('bukan kemiripan nama', $analysis['investigative_disclaimer']);
    }

    public function test_focus_c_eez_compliance_analysis(): void
    {
        $vessel = Vessel::create([
            'name' => 'TARGET ANOMALY 99',
            'mmsi' => '525888999',
            'flag_state' => 'VN',
            'vessel_type' => 'fishing',
        ]);

        $rule = ComplianceRule::create([
            'rule_name' => 'Indonesian EEZ Spatial Entry',
            'rule_code' => 'RULE_EEZ_001',
            'jurisdiction' => 'Sovereign State',
            'category' => 'spatial_entry',
            'severity' => 'critical',
            'rule_logic' => ['zone' => 'IDN_EEZ'],
            'is_active' => true,
        ]);

        ComplianceEvent::create([
            'event_code' => 'EVT-EEZ-TEST-001',
            'vessel_id' => $vessel->id,
            'compliance_rule_id' => $rule->id,
            'severity' => 'critical',
            'status' => 'suspected',
            'event_time' => Carbon::now()->subHours(5),
            'latitude' => 5.2345,
            'longitude' => 108.1234,
            'evidence_summary' => 'Masuk ZEE tanpa transmisi izin',
            'supporting_data' => ['zone' => 'IDN_EEZ'],
        ]);

        $service = app(PrivateInvestigationService::class);
        $analysis = $service->analyzeEezCompliance($vessel);

        $this->assertSame('eez_boundary_compliance', $analysis['focus']);
        $this->assertSame(1, $analysis['anomalies_detected_count']);
        $this->assertSame('Indikasi Anomali / Perlu Telaah', $analysis['boundary_events'][0]['classification']);
        $this->assertStringContainsString('membedakan secara tegas', $analysis['methodology_note']);
    }

    public function test_focus_d_iuu_and_authorizations_five_tier_classification(): void
    {
        $service = app(PrivateInvestigationService::class);

        // Case 1: Prohibited/Blacklisted vessel -> verified_violation
        $blacklisted = Vessel::create([
            'name' => 'PIRATE SHIP ONE',
            'mmsi' => '999111222',
            'flag_state' => 'BL',
            'vessel_type' => 'fishing',
        ]);

        ProhibitedVessel::create([
            'mmsi' => '999111222',
            'original_name' => 'PIRATE SHIP ONE',
            'reason' => 'IUU Fishing Conviction RFMO',
        ]);

        $analysis1 = $service->analyzeIuuAndAuthorization($blacklisted);
        $this->assertSame('verified_violation', $analysis1['verdict_category']);
        $this->assertSame('BLACKLISTED', $analysis1['iuu_blacklist_status']);

        // Case 2: Expired authorization -> suspected_violation
        $expiredVessel = Vessel::create([
            'name' => 'EXPIRED PERMIT VESSEL',
            'mmsi' => '525333444',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        VesselAuthorization::create([
            'vessel_id' => $expiredVessel->id,
            'authorization_code' => 'SIPI-EXP-2025',
            'permit_type' => 'SIPI',
            'issuing_authority' => 'KKP RI',
            'status' => 'expired',
            'valid_from' => Carbon::now()->subYears(2),
            'valid_to' => Carbon::now()->subMonths(1),
        ]);

        $analysis2 = $service->analyzeIuuAndAuthorization($expiredVessel);
        $this->assertSame('suspected_violation', $analysis2['verdict_category']);

        // Case 3: Permit expiring in < 30 days -> potential_risk
        $expiringVessel = Vessel::create([
            'name' => 'EXPIRING SOON VESSEL',
            'mmsi' => '525555666',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        VesselAuthorization::create([
            'vessel_id' => $expiringVessel->id,
            'authorization_code' => 'SIPI-VALID-NEAR',
            'permit_type' => 'SIPI',
            'issuing_authority' => 'KKP RI',
            'status' => 'active',
            'valid_from' => Carbon::now()->subMonths(5),
            'valid_to' => Carbon::now()->addDays(15),
        ]);

        $analysis3 = $service->analyzeIuuAndAuthorization($expiringVessel);
        $this->assertSame('potential_risk', $analysis3['verdict_category']);

        // Case 4: Long valid permit -> no_evidence_found
        $validVessel = Vessel::create([
            'name' => 'FULLY LICENSED VESSEL',
            'mmsi' => '525777888',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        VesselAuthorization::create([
            'vessel_id' => $validVessel->id,
            'authorization_code' => 'SIPI-VALID-LONG',
            'permit_type' => 'SIPI',
            'issuing_authority' => 'KKP RI',
            'status' => 'active',
            'valid_from' => Carbon::now()->subMonths(1),
            'valid_to' => Carbon::now()->addMonths(11),
        ]);

        $analysis4 = $service->analyzeIuuAndAuthorization($validVessel);
        $this->assertSame('no_evidence_found', $analysis4['verdict_category']);
    }

    public function test_export_dossier_contains_confidentiality_watermark_and_provenance(): void
    {
        $vessel = Vessel::create([
            'name' => 'RESTRICTED VESSEL EXPORT',
            'mmsi' => '525123999',
            'flag_state' => 'ID',
            'vessel_type' => 'fishing',
        ]);

        $inv = Investigation::create([
            'case_number' => 'INV-EXPORT-8888',
            'title' => 'Export Confidentiality Test Case',
            'priority' => 'high',
            'status' => 'open',
            'confidentiality_level' => 'strictly_confidential',
            'organization' => 'Ocean Guardian NGO',
            'summary' => 'Dossier untuk diuji ekspor.',
            'target_vessel_id' => $vessel->id,
            'created_by_user_id' => $this->researcherA->id,
            'assigned_to_user_id' => $this->researcherA->id,
        ]);

        // Researcher A has 'investigations.export' permission
        $this->actingAs($this->researcherA);

        $response = $this->get("/investigations/{$inv->id}/export");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('X-Confidentiality-Level', 'strictly_confidential');

        $json = $response->json();
        $this->assertArrayHasKey('dossier_metadata', $json);
        $this->assertArrayHasKey('case_overview', $json);
        $this->assertArrayHasKey('investigative_analyses', $json);
        $this->assertSame('RESTRICTED — RESEARCH TEAM ONLY — DO NOT DISTRIBUTE', $json['dossier_metadata']['watermark']);
        $this->assertStringContainsString('Rina Wijaya', $json['dossier_metadata']['exported_by']);

        // Verify forensic audit log for export
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'export_investigation',
            'entity_type' => 'investigation',
            'entity_id' => (string) $inv->id,
        ]);
    }
}
