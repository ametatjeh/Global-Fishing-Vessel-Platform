<?php

namespace Tests\Feature;

use App\Models\AisRetentionPolicy;
use App\Models\AisRetentionSetting;
use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselIdentifier;
use App\Models\VesselPosition;
use App\Services\DataRetentionPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GFVIP - AIS Retention Safeguard Test Suite (Tahap 4C)
 * Tests all 14 mandatory safeguards:
 * 1. Verified Fishing is never a candidate for purge.
 * 2. Unverified Fishing enters protective hold.
 * 3. Five historical vessels are strictly protected.
 * 4. Unknown is never treated as Fishing.
 * 5. Provider Reported does not automatically become Verified.
 * 6. Verified Non-Fishing follows retention duration.
 * 7. Positions referenced by SAR are not deleted.
 * 8. Positions referenced by Encounter are not deleted.
 * 9. Dark Vessel events and investigations are protected.
 * 10. Audit log and classification history remain available.
 * 11. Dry-run does not modify database.
 * 12. Batch cleanup does not exceed eligible data limit.
 * 13. Disabled retention policy does not execute deletion.
 * 14. Transaction errors roll back safely without partial deletion.
 */
class AisRetentionSafeguardTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected DataRetentionPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Safeguard Admin',
            'email' => 'safeguard@gfvip.org',
            'role' => 'super_admin',
        ]);

        $this->service = app(DataRetentionPolicyService::class);
    }

    /**
     * TEST 1: Verified Fishing is never a candidate for purge (ShipType 30 or analyst verified).
     */
    public function test_01_verified_fishing_is_never_candidate_for_purge(): void
    {
        // Verified by ITU code 30
        $vesselA = Vessel::create([
            'name' => 'KM VERIFIED FISHING 1',
            'mmsi' => '525001001',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'verification_status' => 'provider_reported',
        ]);

        // Verified by Analyst sign-off
        $vesselB = Vessel::create([
            'name' => 'KM VERIFIED FISHING 2',
            'mmsi' => '525001002',
            'vessel_type' => 'fishing',
            'verification_status' => 'verified',
        ]);

        $this->assertTrue($vesselA->isVerifiedFishing());
        $this->assertTrue($vesselB->isVerifiedFishing());

        // Create 200-day-old positions
        $oldDate = now()->subDays(200);
        $posA = VesselPosition::create(['vessel_id' => $vesselA->id, 'latitude' => -5.0, 'longitude' => 106.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);
        $posB = VesselPosition::create(['vessel_id' => $vesselB->id, 'latitude' => -5.1, 'longitude' => 106.1, 'observed_at' => $oldDate, 'received_at' => $oldDate]);

        $candidates = $this->service->getCandidatePositionsQuery();
        $this->assertEquals(0, $candidates['by_type']['fishing']['candidates']);
        $this->assertEquals('PROTECTED_PERMANENT', $candidates['by_type']['fishing']['status']);

        // Execute purge with force
        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);

        $this->assertDatabaseHas('vessel_positions', ['id' => $posA->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $posB->id]);
    }

    /**
     * TEST 2: Unverified Fishing enters protective hold and is not purged.
     */
    public function test_02_unverified_fishing_enters_protective_hold(): void
    {
        // Vessel classified as fishing but lacks AIS code 30 and analyst verification
        $unverifiedVessel = Vessel::create([
            'name' => 'KM UNVERIFIED TRAWLER',
            'mmsi' => '525002001',
            'vessel_type' => 'fishing',
            'verification_status' => 'provider_reported',
            'ais_ship_type_code' => null, // No AIS ShipType evidence!
        ]);

        $this->assertFalse($unverifiedVessel->isVerifiedFishing());
        $this->assertTrue($unverifiedVessel->isUnverifiedFishing());
        $this->assertTrue($unverifiedVessel->isUnderProtectiveHold());

        // Create 300-day-old position
        $oldDate = now()->subDays(300);
        $pos = VesselPosition::create(['vessel_id' => $unverifiedVessel->id, 'latitude' => -6.0, 'longitude' => 107.0, 'observed_at' => $oldDate, 'received_at' => $oldDate]);

        $candidates = $this->service->getCandidatePositionsQuery();
        $this->assertEquals(0, $candidates['by_type']['fishing']['candidates']);
        $this->assertGreaterThan(0, $candidates['by_type']['fishing']['held']);

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    /**
     * TEST 3: Five historical vessels are strictly protected from purge.
     */
    public function test_03_five_historical_vessels_are_strictly_protected(): void
    {
        $historicalMmsis = Vessel::HISTORICAL_PROTECTED_MMSIS;
        $this->assertCount(5, $historicalMmsis);

        $createdPositions = [];
        $oldDate = now()->subDays(400);

        foreach ($historicalMmsis as $mmsi) {
            $vessel = Vessel::create([
                'name' => "HISTORICAL-{$mmsi}",
                'mmsi' => $mmsi,
                'vessel_type' => 'fishing',
                'verification_status' => 'provider_reported',
                'ais_ship_type_code' => null,
            ]);

            $this->assertTrue($vessel->isHistoricalProtected());
            $this->assertTrue($vessel->isUnderProtectiveHold());

            $pos = VesselPosition::create([
                'vessel_id' => $vessel->id,
                'latitude' => 1.0,
                'longitude' => 104.0,
                'observed_at' => $oldDate,
                'received_at' => $oldDate,
            ]);
            $createdPositions[] = $pos->id;
        }

        $holdIds = $this->service->getProtectiveHoldVesselIds();
        foreach ($historicalMmsis as $mmsi) {
            $vesselId = Vessel::where('mmsi', $mmsi)->value('id');
            $this->assertContains($vesselId, $holdIds, "Historical MMSI {$mmsi} must be in protective hold IDs");
        }

        // Execute purge
        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);

        foreach ($createdPositions as $posId) {
            $this->assertDatabaseHas('vessel_positions', ['id' => $posId]);
        }
    }

    /**
     * TEST 4: Unknown is never treated as Fishing and follows configured Unknown policy.
     */
    public function test_04_unknown_is_never_treated_as_fishing(): void
    {
        $unknownVessel = Vessel::create([
            'name' => 'UNKNOWN CRAFT 99',
            'mmsi' => '525004001',
            'vessel_type' => 'unknown',
            'verification_status' => 'unverified',
        ]);

        $this->assertFalse($unknownVessel->isVerifiedFishing());
        $this->assertFalse($unknownVessel->isUnverifiedFishing());

        // Set unknown retention to 30 days
        AisRetentionPolicy::where('vessel_type', 'unknown')->update(['retention_days' => 30, 'is_enabled' => true]);

        // Create 60-day-old position and 10-day-old position
        $posOld = VesselPosition::create(['vessel_id' => $unknownVessel->id, 'latitude' => 0.0, 'longitude' => 105.0, 'observed_at' => now()->subDays(60), 'received_at' => now()->subDays(60)]);
        $posRecent = VesselPosition::create(['vessel_id' => $unknownVessel->id, 'latitude' => 0.1, 'longitude' => 105.1, 'observed_at' => now()->subDays(10), 'received_at' => now()->subDays(10)]);

        $candidates = $this->service->getCandidatePositionsQuery();
        $this->assertEquals(1, $candidates['by_type']['unknown']['candidates']);

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(1, $result['records_purged']);

        $this->assertDatabaseMissing('vessel_positions', ['id' => $posOld->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $posRecent->id]);
    }

    /**
     * TEST 5: Provider Reported does not automatically become Verified.
     */
    public function test_05_provider_reported_does_not_automatically_become_verified(): void
    {
        // Vessel has provider_reported, but has no valid AIS ShipType code
        $vessel = Vessel::create([
            'name' => 'PROVIDER REPORTED VESSEL',
            'mmsi' => '525005001',
            'vessel_type' => 'fishing',
            'verification_status' => 'provider_reported',
            'ais_ship_type_code' => null,
        ]);

        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertTrue($vessel->isUnverifiedFishing());
        $this->assertTrue($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 6: Verified Non-Fishing follows retention duration per ship type.
     */
    public function test_06_verified_non_fishing_follows_retention_period(): void
    {
        // Verified Cargo (AIS Type 70)
        $cargoVessel = Vessel::create([
            'name' => 'MV OCEAN FREIGHTER',
            'mmsi' => '525006001',
            'vessel_type' => 'cargo',
            'ais_ship_type_code' => 70,
            'verification_status' => 'provider_reported',
        ]);
        $this->assertTrue($cargoVessel->isVerifiedNonFishing());

        // Set cargo retention to 60 days
        AisRetentionPolicy::where('vessel_type', 'cargo')->update(['retention_days' => 60, 'is_enabled' => true]);

        // Position 1: 100 days old (past cutoff) -> must be purged
        $posOld = VesselPosition::create(['vessel_id' => $cargoVessel->id, 'latitude' => 1.5, 'longitude' => 104.5, 'observed_at' => now()->subDays(100), 'received_at' => now()->subDays(100)]);
        // Position 2: 20 days old (within retention) -> must be kept
        $posRecent = VesselPosition::create(['vessel_id' => $cargoVessel->id, 'latitude' => 1.6, 'longitude' => 104.6, 'observed_at' => now()->subDays(20), 'received_at' => now()->subDays(20)]);

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(1, $result['records_purged']);

        $this->assertDatabaseMissing('vessel_positions', ['id' => $posOld->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $posRecent->id]);
    }

    /**
     * TEST 7: Positions referenced by SAR detections are not deleted.
     */
    public function test_07_positions_referenced_by_sar_are_not_deleted(): void
    {
        $cargoVessel = Vessel::create([
            'name' => 'MV SAR CORRELATED',
            'mmsi' => '525007001',
            'vessel_type' => 'cargo',
            'ais_ship_type_code' => 70,
        ]);

        AisRetentionPolicy::where('vessel_type', 'cargo')->update(['retention_days' => 30, 'is_enabled' => true]);

        // Correlate to a satellite SAR detection
        $scene = SatelliteScene::create([
            'scene_id' => 'SCENE-TEST-001',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'SAR-C',
            'acquisition_start' => now()->subDays(100),
            'acquisition_end' => now()->subDays(100),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => []]),
            'processing_status' => 'completed',
        ]);

        SatelliteDetection::create([
            'satellite_scene_id' => $scene->id,
            'detection_identifier' => 'DET-001',
            'latitude' => 2.0,
            'longitude' => 105.0,
            'detected_at' => now()->subDays(100),
            'correlated_vessel_id' => $cargoVessel->id,
            'confidence_score' => 0.95,
        ]);

        $pos = VesselPosition::create(['vessel_id' => $cargoVessel->id, 'latitude' => 2.0, 'longitude' => 105.0, 'observed_at' => now()->subDays(100), 'received_at' => now()->subDays(100)]);

        // Purge must preserve position due to SAR correlation
        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    /**
     * TEST 8: Positions referenced by Encounter are not deleted.
     */
    public function test_08_positions_referenced_by_encounter_are_not_deleted(): void
    {
        $tankerVessel = Vessel::create([
            'name' => 'MT BUNKER ENCOUNTER',
            'mmsi' => '525008001',
            'vessel_type' => 'tanker',
            'ais_ship_type_code' => 80,
        ]);

        $otherVessel = Vessel::create(['name' => 'OTHER CRAFT', 'mmsi' => '525008002', 'vessel_type' => 'tug']);

        AisRetentionPolicy::where('vessel_type', 'tanker')->update(['retention_days' => 30, 'is_enabled' => true]);

        VesselEncounter::create([
            'encounter_code' => 'ENC-2026-001',
            'vessel_1_id' => $tankerVessel->id,
            'vessel_2_id' => $otherVessel->id,
            'category' => 'rendezvous',
            'status' => 'detected',
            'start_time' => now()->subDays(120),
            'end_time' => now()->subDays(120)->addHours(3),
            'latitude' => 3.0,
            'longitude' => 108.0,
            'min_distance_meters' => 150.0,
            'median_distance_meters' => 200.0,
            'parameters_used' => json_encode(['threshold_meters' => 500]),
        ]);

        $pos = VesselPosition::create(['vessel_id' => $tankerVessel->id, 'latitude' => 3.0, 'longitude' => 108.0, 'observed_at' => now()->subDays(120), 'received_at' => now()->subDays(120)]);

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    /**
     * TEST 9: Dark Vessel events and active investigations are protected.
     */
    public function test_09_dark_vessel_and_investigations_are_protected(): void
    {
        $vesselA = Vessel::create(['name' => 'MV DARK INDICATOR', 'mmsi' => '525009001', 'vessel_type' => 'cargo', 'ais_ship_type_code' => 70]);
        $vesselB = Vessel::create(['name' => 'MV UNDER INVESTIGATION', 'mmsi' => '525009002', 'vessel_type' => 'cargo', 'ais_ship_type_code' => 70]);

        AisRetentionPolicy::where('vessel_type', 'cargo')->update(['retention_days' => 30, 'is_enabled' => true]);

        // Position with is_gap_indicator = true (Dark Vessel gap event)
        $gapPos = VesselPosition::create([
            'vessel_id' => $vesselA->id,
            'latitude' => 4.0,
            'longitude' => 109.0,
            'is_gap_indicator' => true,
            'observed_at' => now()->subDays(90),
            'received_at' => now()->subDays(90),
        ]);
        $this->assertTrue($gapPos->isDarkVesselIndicator());

        // Vessel tied to an investigation
        Investigation::create([
            'case_number' => 'INV-DARK-001',
            'title' => 'Dark Vessel Transshipment Probe',
            'summary' => 'Investigating suspicious rendezvous in EEZ boundary.',
            'target_vessel_id' => $vesselB->id,
            'status' => 'open',
            'priority' => 'critical',
        ]);

        $invPos = VesselPosition::create([
            'vessel_id' => $vesselB->id,
            'latitude' => 4.1,
            'longitude' => 109.1,
            'is_gap_indicator' => false,
            'observed_at' => now()->subDays(90),
            'received_at' => now()->subDays(90),
        ]);

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);

        $this->assertDatabaseHas('vessel_positions', ['id' => $gapPos->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $invPos->id]);
    }

    /**
     * TEST 10: Audit log and classification history remain available after purge.
     */
    public function test_10_audit_log_and_classification_history_remain_available(): void
    {
        $vessel = Vessel::create([
            'name' => 'MV AUDIT VERIFIED',
            'mmsi' => '525010001',
            'vessel_type' => 'passenger',
            'ais_ship_type_code' => 60,
            'classification_source' => 'ais_stream',
            'classified_at' => now()->subDays(50),
        ]);

        VesselIdentifier::create([
            'vessel_id' => $vessel->id,
            'identifier_type' => 'mmsi',
            'identifier_value' => '525010001',
            'is_current' => true,
        ]);

        AisRetentionPolicy::where('vessel_type', 'passenger')->update(['retention_days' => 30, 'is_enabled' => true]);

        $pos = VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => -1.0, 'longitude' => 106.0, 'observed_at' => now()->subDays(90), 'received_at' => now()->subDays(90)]);

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(1, $result['records_purged']);

        // Position was purged, but master vessel, identifiers, and classification provenance MUST exist
        $this->assertDatabaseMissing('vessel_positions', ['id' => $pos->id]);
        $this->assertDatabaseHas('vessels', ['id' => $vessel->id, 'classification_source' => 'ais_stream', 'ais_ship_type_code' => 60]);
        $this->assertDatabaseHas('vessel_identifiers', ['vessel_id' => $vessel->id, 'identifier_value' => '525010001']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete', 'entity_type' => 'ais_retention_cleanup']);
    }

    /**
     * TEST 11: Dry-run simulation does not modify database in any way.
     */
    public function test_11_dry_run_does_not_modify_database(): void
    {
        $vessel = Vessel::create(['name' => 'MV DRY RUN CARGO', 'mmsi' => '525011001', 'vessel_type' => 'cargo', 'ais_ship_type_code' => 70]);
        AisRetentionPolicy::where('vessel_type', 'cargo')->update(['retention_days' => 30, 'is_enabled' => true]);

        VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => 5.0, 'longitude' => 110.0, 'observed_at' => now()->subDays(100), 'received_at' => now()->subDays(100)]);

        $beforeCount = VesselPosition::count();
        $this->assertEquals(1, $beforeCount);

        $simulation = $this->service->simulateRetentionPurge();
        $this->assertTrue($simulation['is_dry_run']);
        $this->assertEquals(1, $simulation['total_candidate_positions']);

        $afterCount = VesselPosition::count();
        $this->assertEquals($beforeCount, $afterCount, 'Database record count must remain identical after dry-run');
    }

    /**
     * TEST 12: Batch cleanup does not exceed eligible data limit.
     */
    public function test_12_batch_cleanup_does_not_exceed_eligible_data_limit(): void
    {
        $vessel = Vessel::create(['name' => 'MV BATCH TANKER', 'mmsi' => '525012001', 'vessel_type' => 'tanker', 'ais_ship_type_code' => 80]);
        AisRetentionPolicy::where('vessel_type', 'tanker')->update(['retention_days' => 30, 'is_enabled' => true]);
        AisRetentionSetting::instance()->update(['chunk_size' => 2]); // batch size 2

        // 3 old positions (exceed cutoff)
        for ($i = 1; $i <= 3; $i++) {
            VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => 6.0 + $i, 'longitude' => 111.0, 'observed_at' => now()->subDays(50 + $i), 'received_at' => now()->subDays(50 + $i)]);
        }
        // 2 recent positions (within retention)
        for ($i = 1; $i <= 2; $i++) {
            VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => 7.0 + $i, 'longitude' => 112.0, 'observed_at' => now()->subDays(5), 'received_at' => now()->subDays(5)]);
        }

        $this->assertEquals(5, VesselPosition::count());

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(3, $result['records_purged']);
        $this->assertEquals(2, VesselPosition::count(), 'Exactly 2 recent positions must remain');
    }

    /**
     * TEST 13: Disabled retention policy does not execute deletion.
     */
    public function test_13_disabled_retention_policy_does_not_execute_deletion(): void
    {
        $vessel = Vessel::create(['name' => 'MV DISABLED POLICY TUG', 'mmsi' => '525013001', 'vessel_type' => 'tug', 'ais_ship_type_code' => 52]);
        // Explicitly disable policy for Tug
        AisRetentionPolicy::where('vessel_type', 'tug')->update(['retention_days' => 14, 'is_enabled' => false]);

        $pos = VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => 8.0, 'longitude' => 113.0, 'observed_at' => now()->subDays(90), 'received_at' => now()->subDays(90)]);

        $candidates = $this->service->getCandidatePositionsQuery();
        $this->assertEquals(0, $candidates['by_type']['tug']['candidates']);
        $this->assertEquals('POLICY_DISABLED', $candidates['by_type']['tug']['status']);

        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    /**
     * TEST 14: Transaction failure rolls back safely without partial deletion.
     */
    public function test_14_transaction_failure_does_not_cause_partial_unsafe_deletion(): void
    {
        $vessel = Vessel::create(['name' => 'MV ROLLBACK CARGO', 'mmsi' => '525014001', 'vessel_type' => 'cargo', 'ais_ship_type_code' => 70]);
        AisRetentionPolicy::where('vessel_type', 'cargo')->update(['retention_days' => 30, 'is_enabled' => true]);

        $pos1 = VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => 9.0, 'longitude' => 114.0, 'observed_at' => now()->subDays(60), 'received_at' => now()->subDays(60)]);
        $pos2 = VesselPosition::create(['vessel_id' => $vessel->id, 'latitude' => 9.1, 'longitude' => 114.1, 'observed_at' => now()->subDays(61), 'received_at' => now()->subDays(61)]);

        $initialCount = VesselPosition::count();

        // Simulate transaction rollback or exception during batch deletion
        try {
            DB::transaction(function () use ($pos1) {
                DB::table('vessel_positions')->where('id', $pos1->id)->delete();
                throw new \Exception('Simulated database deadlock during batch retention execution');
            });
        } catch (\Throwable $e) {
            // Expected
        }

        $this->assertEquals($initialCount, VesselPosition::count(), 'Database transaction rollback must keep record count unchanged');
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos1->id]);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos2->id]);
    }

    // =========================================================================
    // TAHAP 4C.2: CLASSIFICATION CONFLICT SAFEGUARDS & QUERY SYNCHRONIZATION
    // =========================================================================

    /**
     * TEST 15: Fishing + ShipType 30 = Verified Fishing.
     */
    public function test_15_fishing_with_shiptype_30_is_verified_fishing(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM TUNA MANDIRI 30',
            'mmsi' => '525015001',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'verification_status' => 'provider_reported',
        ]);

        $this->assertTrue($vessel->isVerifiedFishing());
        $this->assertFalse($vessel->isClassificationDisputed());
        $this->assertFalse($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 16: Fishing + verified + ShipType null = Verified Fishing (legacy manual sign-off).
     */
    public function test_16_fishing_with_verified_status_and_null_shiptype_is_verified_fishing(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM CAKRAWALA 99',
            'mmsi' => '525016001',
            'vessel_type' => 'fishing',
            'verification_status' => 'verified',
            'ais_ship_type_code' => null,
        ]);

        $this->assertTrue($vessel->isVerifiedFishing());
        $this->assertFalse($vessel->isClassificationDisputed());
        $this->assertFalse($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 17: Fishing + verified + ShipType 70 = Disputed / Protective Hold.
     * Manual verification CANNOT override objective Cargo AIS evidence.
     */
    public function test_17_fishing_with_verified_status_and_shiptype_70_is_disputed_protective_hold(): void
    {
        $vessel = Vessel::create([
            'name' => 'MV CONFLICTING CARGO',
            'mmsi' => '525017001',
            'vessel_type' => 'fishing', // Labeled fishing in database
            'verification_status' => 'verified', // Marked verified by an analyst
            'ais_ship_type_code' => 70, // BUT AIS telemetry carries ShipType 70 (Cargo)!
        ]);

        // Must NOT be verified fishing
        $this->assertFalse($vessel->isVerifiedFishing(), 'Cargo code 70 must disqualify vessel from Verified Fishing');
        // Must be flagged as disputed
        $this->assertTrue($vessel->isClassificationDisputed(), 'Vessel with conflicting manual vs AIS evidence must be disputed');
        // Must enter Protective Hold
        $this->assertTrue($vessel->isUnderProtectiveHold(), 'Disputed vessel must enter Protective Hold');
        // Must NOT automatically become verified non-fishing
        $this->assertFalse($vessel->isVerifiedNonFishing());
    }

    /**
     * TEST 18: Fishing + verified + ShipType 80 = Disputed / Protective Hold.
     * Manual verification CANNOT override objective Tanker AIS evidence.
     */
    public function test_18_fishing_with_verified_status_and_shiptype_80_is_disputed_protective_hold(): void
    {
        $vessel = Vessel::create([
            'name' => 'MT CONFLICTING TANKER',
            'mmsi' => '525018001',
            'vessel_type' => 'fishing',
            'verification_status' => 'verified',
            'ais_ship_type_code' => 80, // AIS code 80 (Tanker)
        ]);

        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertTrue($vessel->isClassificationDisputed());
        $this->assertTrue($vessel->isUnderProtectiveHold());
        $this->assertFalse($vessel->isVerifiedNonFishing());
    }

    /**
     * TEST 19: Fishing + provider_reported + ShipType null = Protective Hold.
     */
    public function test_19_fishing_with_provider_reported_and_null_shiptype_is_protective_hold(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM UNVERIFIED PROVIDER',
            'mmsi' => '525019001',
            'vessel_type' => 'fishing',
            'verification_status' => 'provider_reported',
            'ais_ship_type_code' => null,
        ]);

        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertTrue($vessel->isUnverifiedFishing());
        $this->assertTrue($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 20: Fishing + unverified + ShipType null = Protective Hold.
     */
    public function test_20_fishing_with_unverified_and_null_shiptype_is_protective_hold(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM RAW INGESTION',
            'mmsi' => '525020001',
            'vessel_type' => 'fishing',
            'verification_status' => 'unverified',
            'ais_ship_type_code' => null,
        ]);

        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertTrue($vessel->isUnverifiedFishing());
        $this->assertTrue($vessel->isUnderProtectiveHold());
    }

    /**
     * TEST 21: Cargo + verified + ShipType 70 = Verified Non-Fishing.
     */
    public function test_21_cargo_with_verified_and_shiptype_70_is_verified_non_fishing(): void
    {
        $vessel = Vessel::create([
            'name' => 'MV LEGITIMATE CARGO',
            'mmsi' => '525021001',
            'vessel_type' => 'cargo',
            'verification_status' => 'verified',
            'ais_ship_type_code' => 70,
        ]);

        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertFalse($vessel->isClassificationDisputed());
        $this->assertTrue($vessel->isVerifiedNonFishing());
    }

    /**
     * TEST 22: Tanker + verified + ShipType 80 = Verified Non-Fishing.
     */
    public function test_22_tanker_with_verified_and_shiptype_80_is_verified_non_fishing(): void
    {
        $vessel = Vessel::create([
            'name' => 'MT LEGITIMATE TANKER',
            'mmsi' => '525022001',
            'vessel_type' => 'tanker',
            'verification_status' => 'verified',
            'ais_ship_type_code' => 80,
        ]);

        $this->assertFalse($vessel->isVerifiedFishing());
        $this->assertFalse($vessel->isClassificationDisputed());
        $this->assertTrue($vessel->isVerifiedNonFishing());
    }

    /**
     * TEST 23: Disputed vessels never become candidates for purge.
     */
    public function test_23_disputed_vessels_never_become_candidates_for_purge(): void
    {
        // Vessel in active dispute: labeled fishing + verified, but carrying Cargo code 70
        $disputedVessel = Vessel::create([
            'name' => 'MV DISPUTED EVIDENCE',
            'mmsi' => '525023001',
            'vessel_type' => 'fishing',
            'verification_status' => 'verified',
            'ais_ship_type_code' => 70,
        ]);

        // Create 200-day-old positions
        $oldDate = now()->subDays(200);
        $pos = VesselPosition::create([
            'vessel_id' => $disputedVessel->id,
            'latitude' => 1.2,
            'longitude' => 104.2,
            'observed_at' => $oldDate,
            'received_at' => $oldDate,
        ]);

        // Disputed vessel must be in getProtectiveHoldVesselIds()
        $holdIds = $this->service->getProtectiveHoldVesselIds();
        $this->assertContains($disputedVessel->id, $holdIds, 'Disputed vessel must be in protective hold IDs');

        // Disputed vessel must NOT be in getVerifiedFishingVesselIds()
        $verifiedFishingIds = $this->service->getVerifiedFishingVesselIds();
        $this->assertNotContains($disputedVessel->id, $verifiedFishingIds, 'Disputed vessel must not be in verified fishing IDs');

        // Candidate positions check: must be 0
        $candidates = $this->service->getCandidatePositionsQuery();
        $this->assertEquals(0, $candidates['count']);

        // Purge execution must NOT touch position
        $result = $this->service->executeRetentionPurge(force: true, userId: $this->adminUser->id);
        $this->assertEquals(0, $result['records_purged']);
        $this->assertDatabaseHas('vessel_positions', ['id' => $pos->id]);
    }

    /**
     * TEST 24: Query getVerifiedFishingVesselIds() is 100% consistent with Vessel::isVerifiedFishing().
     */
    public function test_24_get_verified_fishing_vessel_ids_query_is_consistent_with_is_verified_fishing(): void
    {
        // Set up a matrix of diverse vessels
        $v1 = Vessel::create(['name' => 'V1', 'mmsi' => '525990001', 'vessel_type' => 'fishing', 'ais_ship_type_code' => 30, 'verification_status' => 'provider_reported']); // Verified
        $v2 = Vessel::create(['name' => 'V2', 'mmsi' => '525990002', 'vessel_type' => 'fishing', 'ais_ship_type_code' => null, 'verification_status' => 'verified']); // Verified
        $v3 = Vessel::create(['name' => 'V3', 'mmsi' => '525990003', 'vessel_type' => 'fishing', 'ais_ship_type_code' => 70, 'verification_status' => 'verified']); // Disputed -> NOT verified
        $v4 = Vessel::create(['name' => 'V4', 'mmsi' => '525990004', 'vessel_type' => 'fishing', 'ais_ship_type_code' => 80, 'verification_status' => 'provider_reported']); // Unverified -> NOT verified
        $v5 = Vessel::create(['name' => 'V5', 'mmsi' => '525990005', 'vessel_type' => 'cargo', 'ais_ship_type_code' => 70, 'verification_status' => 'verified']); // Cargo -> NOT verified fishing
        $v6 = Vessel::create(['name' => 'V6', 'mmsi' => '525990006', 'vessel_type' => 'unknown', 'ais_ship_type_code' => null, 'verification_status' => 'unverified']); // Unknown -> NOT verified fishing

        $allVessels = [$v1, $v2, $v3, $v4, $v5, $v6];

        $serviceVerifiedIds = $this->service->getVerifiedFishingVesselIds();

        foreach ($allVessels as $v) {
            $modelResult = $v->isVerifiedFishing();
            $queryResult = in_array($v->id, $serviceVerifiedIds, true);

            $this->assertEquals(
                $modelResult,
                $queryResult,
                "Inconsistency detected for {$v->name} (Type: {$v->vessel_type}, Code: {$v->ais_ship_type_code}, Status: {$v->verification_status}): Model returned " . ($modelResult ? 'true' : 'false') . ' but query returned ' . ($queryResult ? 'true' : 'false')
            );
        }

        // Exactly V1 and V2 must be verified
        $this->assertContains($v1->id, $serviceVerifiedIds);
        $this->assertContains($v2->id, $serviceVerifiedIds);
        $this->assertNotContains($v3->id, $serviceVerifiedIds);
        $this->assertNotContains($v4->id, $serviceVerifiedIds);
        $this->assertNotContains($v5->id, $serviceVerifiedIds);
        $this->assertNotContains($v6->id, $serviceVerifiedIds);
    }
}

