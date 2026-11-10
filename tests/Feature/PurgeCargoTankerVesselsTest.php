<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\EncounterVessel;
use App\Models\ProhibitedVessel;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselIdentifier;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use App\Services\VesselPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeCargoTankerVesselsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-ais-key']);
    }

    public function test_cargo_tanker_and_pleasure_vessels_identified_correctly_in_inspection(): void
    {
        $purgeService = app(VesselPurgeService::class);

        // Seed 1 fishing, 1 cargo, 1 tanker, 1 pleasure
        $fishing = Vessel::create(['name' => 'KM NELAYAN 01', 'mmsi' => '525001001', 'vessel_type' => 'fishing']);
        $cargo = Vessel::create(['name' => 'MV CARGO CONTAINER', 'mmsi' => '525002002', 'vessel_type' => 'cargo']);
        $tanker = Vessel::create(['name' => 'MT OIL TANKER', 'mmsi' => '525003003', 'vessel_type' => 'tanker']);
        $pleasure = Vessel::create(['name' => 'MY LUXURY YACHT', 'mmsi' => '525004004', 'vessel_type' => 'pleasure']);

        VesselPosition::create(['vessel_id' => $fishing->id, 'latitude' => -5.0, 'longitude' => 106.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $cargo->id, 'latitude' => 1.0, 'longitude' => 104.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $tanker->id, 'latitude' => 2.0, 'longitude' => 105.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $pleasure->id, 'latitude' => 3.0, 'longitude' => 106.0, 'observed_at' => now(), 'received_at' => now()]);

        VesselIdentifier::create(['vessel_id' => $cargo->id, 'identifier_type' => 'mmsi', 'identifier_value' => '525002002']);
        VesselIdentifier::create(['vessel_id' => $tanker->id, 'identifier_type' => 'mmsi', 'identifier_value' => '525003003']);
        VesselIdentifier::create(['vessel_id' => $pleasure->id, 'identifier_type' => 'mmsi', 'identifier_value' => '525004004']);

        $inspection = $purgeService->inspectPurgeTargets();

        $this->assertEquals(1, $inspection['cargo']['count']);
        $this->assertEquals(1, $inspection['tanker']['count']);
        $this->assertEquals(1, $inspection['pleasure']['count']);
        $this->assertEquals(3, $inspection['total_target_vessels']);
        $this->assertEquals(3, $inspection['impacted_records']['vessel_positions']);
        $this->assertEquals(3, $inspection['impacted_records']['vessel_identifiers']);
        $this->assertEquals(1, $inspection['fishing_vessels_remaining']);
    }

    public function test_dry_run_does_not_delete_any_database_records(): void
    {
        $purgeService = app(VesselPurgeService::class);

        $cargo = Vessel::create(['name' => 'CARGO TEST', 'mmsi' => '525002002', 'vessel_type' => 'cargo']);
        VesselPosition::create(['vessel_id' => $cargo->id, 'latitude' => 1.0, 'longitude' => 104.0, 'observed_at' => now(), 'received_at' => now()]);

        $result = $purgeService->executePurge(dryRun: true);

        $this->assertEquals('dry_run', $result['status']);
        $this->assertEquals(1, Vessel::where('mmsi', '525002002')->count());
        $this->assertEquals(1, VesselPosition::where('vessel_id', $cargo->id)->count());
    }

    public function test_controlled_purge_deletes_non_fishing_leaving_fishing_intact(): void
    {
        $purgeService = app(VesselPurgeService::class);

        $fishing = Vessel::create(['name' => 'KM NELAYAN SAKTI', 'mmsi' => '525001001', 'vessel_type' => 'fishing']);
        $cargo = Vessel::create(['name' => 'EVER CARGO', 'mmsi' => '525002002', 'vessel_type' => 'cargo']);
        $tanker = Vessel::create(['name' => 'PACIFIC TANKER', 'mmsi' => '525003003', 'vessel_type' => 'tanker']);
        $pleasure = Vessel::create(['name' => 'OCEAN BREEZE', 'mmsi' => '525004004', 'vessel_type' => 'pleasure']);

        $posFish = VesselPosition::create(['vessel_id' => $fishing->id, 'latitude' => -5.0, 'longitude' => 106.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $cargo->id, 'latitude' => 1.0, 'longitude' => 104.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $tanker->id, 'latitude' => 2.0, 'longitude' => 105.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $pleasure->id, 'latitude' => 3.0, 'longitude' => 106.0, 'observed_at' => now(), 'received_at' => now()]);

        $result = $purgeService->executePurge(dryRun: false, adminUserId: 1);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(1, $result['deleted']['cargo_vessels']);
        $this->assertEquals(1, $result['deleted']['tanker_vessels']);
        $this->assertEquals(1, $result['deleted']['pleasure_vessels']);
        $this->assertEquals(3, $result['deleted']['total_vessels']);
        $this->assertEquals(3, $result['deleted']['positions']);

        // Assert cargo, tanker, and pleasure vessels are deleted
        $this->assertEquals(0, Vessel::where('mmsi', '525002002')->count());
        $this->assertEquals(0, Vessel::where('mmsi', '525003003')->count());
        $this->assertEquals(0, Vessel::where('mmsi', '525004004')->count());

        // Assert fishing vessel is 100% intact
        $this->assertEquals(1, Vessel::where('mmsi', '525001001')->count());
        $this->assertEquals(1, VesselPosition::where('id', $posFish->id)->count());

        // Assert MMSIs are registered in ProhibitedVessel registry
        $this->assertTrue(ProhibitedVessel::isProhibited('525002002'));
        $this->assertTrue(ProhibitedVessel::isProhibited('525003003'));
        $this->assertTrue(ProhibitedVessel::isProhibited('525004004'));

        // Assert audit log was recorded
        $this->assertTrue(AuditLog::where('action', 'purge_cargo_tanker_vessels')->exists());
    }

    public function test_purge_protects_cargo_or_pleasure_vessels_referenced_in_encounters(): void
    {
        $purgeService = app(VesselPurgeService::class);

        $fishing = Vessel::create(['name' => 'KM NELAYAN UTAMA', 'mmsi' => '525001001', 'vessel_type' => 'fishing']);
        $cargoEncountered = Vessel::create(['name' => 'CARGO RENDEZVOUS', 'mmsi' => '525002002', 'vessel_type' => 'cargo']);
        $cargoStandalone = Vessel::create(['name' => 'CARGO STANDALONE', 'mmsi' => '525005005', 'vessel_type' => 'cargo']);

        // Create an encounter involving cargoEncountered and fishing vessel
        $encounter = VesselEncounter::create([
            'encounter_code' => 'ENC-TEST-001',
            'vessel_1_id' => $fishing->id,
            'vessel_2_id' => $cargoEncountered->id,
            'category' => 'fishing_carrier',
            'status' => 'verified',
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'duration_minutes' => 60,
            'min_distance_meters' => 120.0,
            'latitude' => 0.5,
            'longitude' => 105.5,
            'detection_method' => 'native_ais',
            'parameters_used' => ['max_distance_meters' => 500],
        ]);

        $result = $purgeService->executePurge(dryRun: false, adminUserId: 1);

        $this->assertEquals('completed', $result['status']);
        // Standalone cargo was purged
        $this->assertEquals(0, Vessel::where('id', $cargoStandalone->id)->count());
        // Cargo with encounter reference was preserved to prevent cascade loss
        $this->assertEquals(1, Vessel::where('id', $cargoEncountered->id)->count());
        $this->assertEquals(1, VesselEncounter::where('id', $encounter->id)->count());

        // Both MMSIs are still registered as prohibited from future ingestion
        $this->assertTrue(ProhibitedVessel::isProhibited('525002002'));
        $this->assertTrue(ProhibitedVessel::isProhibited('525005005'));
    }

    public function test_subsequent_ingestion_accepts_cargo_tanker_pleasure_and_prohibited_mmsi(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // Pre-register an MMSI in prohibited vessel registry to test non-rejection
        ProhibitedVessel::create([
            'mmsi' => '525777001',
            'reason' => 'legacy_policy',
            'original_type' => 'cargo',
        ]);

        // 1. Ingestion of AIS Position Report with ShipType 70 (Cargo) is accepted
        $posCargo = $adapter->ingestPositionMessage([
            'mmsi' => '525777001',
            'latitude' => 1.2,
            'longitude' => 103.8,
            'ship_type' => 70, // Cargo
            'timestamp' => '2026-11-07T07:00:00Z',
        ]);
        $this->assertNotNull($posCargo);
        $this->assertEquals(1, Vessel::where('mmsi', '525777001')->count());
        $this->assertEquals('cargo', $posCargo->vessel->vessel_type);

        // 2. Ingestion of AIS Static Report with ShipType 80 (Tanker) is accepted
        $vesselTanker = $adapter->ingestStaticMessage([
            'mmsi' => '525888002',
            'vessel_name' => 'NEW TANKER',
            'ship_type' => 80, // Tanker
            'timestamp' => '2026-11-07T07:05:00Z',
        ]);
        $this->assertNotNull($vesselTanker);
        $this->assertEquals(1, Vessel::where('mmsi', '525888002')->count());
        $this->assertEquals('tanker', $vesselTanker->vessel_type);

        // 3. Ingestion of AIS Static Report with ShipType 37 (Pleasure) is accepted
        $vesselPleasure = $adapter->ingestStaticMessage([
            'mmsi' => '525999003',
            'vessel_name' => 'YACHT DREAM',
            'ship_type' => 37, // Pleasure
            'timestamp' => '2026-11-07T07:06:00Z',
        ]);
        $this->assertNotNull($vesselPleasure);
        $this->assertEquals(1, Vessel::where('mmsi', '525999003')->count());
        $this->assertEquals('pleasure', $vesselPleasure->vessel_type);

        // 4. Ingestion of AIS Position Report with ShipType 37 (Pleasure) is accepted
        $posPleasure = $adapter->ingestPositionMessage([
            'mmsi' => '525999004',
            'latitude' => 2.5,
            'longitude' => 108.0,
            'ship_type' => 37, // Pleasure
            'timestamp' => '2026-11-07T07:07:00Z',
        ]);
        $this->assertNotNull($posPleasure);
        $this->assertEquals(1, Vessel::where('mmsi', '525999004')->count());
        $this->assertEquals('pleasure', $posPleasure->vessel->vessel_type);

        // 5. Subsequent positions from already prohibited MMSI are accepted
        $prohibitedPos = $adapter->ingestPositionMessage([
            'mmsi' => '525777001', // Already prohibited MMSI
            'latitude' => 1.25,
            'longitude' => 103.85,
            'ship_type' => null, // Omitted ship_type
            'timestamp' => '2026-11-07T07:10:00Z',
        ]);
        $this->assertNotNull($prohibitedPos);
        $this->assertEquals(3, VesselPosition::count());

        // 6. Ingestion of legitimate Fishing vessel (ShipType 30) continues to work perfectly
        $posFishing = $adapter->ingestPositionMessage([
            'mmsi' => '525333003',
            'latitude' => -6.1,
            'longitude' => 106.8,
            'ship_type' => 30, // Fishing
            'vessel_name' => 'KM BAHARI SEJATI',
            'timestamp' => '2026-11-07T07:15:00Z',
        ]);
        $this->assertNotNull($posFishing);
        $this->assertEquals('fishing', $posFishing->vessel->vessel_type);
        $this->assertEquals(4, VesselPosition::count());
    }

    public function test_artisan_command_renders_dry_run_and_confirms_controlled_execution(): void
    {
        Vessel::create(['name' => 'CARGO SIMULATION', 'mmsi' => '525444004', 'vessel_type' => 'cargo']);
        Vessel::create(['name' => 'TANKER SIMULATION', 'mmsi' => '525444005', 'vessel_type' => 'tanker']);
        Vessel::create(['name' => 'PLEASURE SIMULATION', 'mmsi' => '525444006', 'vessel_type' => 'pleasure']);

        // Default: Dry run
        $this->artisan('vessels:purge-cargo-tanker')
            ->expectsOutputToContain('[MODE DRY-RUN AKTIF]')
            ->expectsOutputToContain('Cargo')
            ->expectsOutputToContain('Tanker')
            ->expectsOutputToContain('Pleasure Craft')
            ->assertSuccessful();

        $this->assertEquals(1, Vessel::where('mmsi', '525444004')->count());
        $this->assertEquals(1, Vessel::where('mmsi', '525444005')->count());
        $this->assertEquals(1, Vessel::where('mmsi', '525444006')->count());

        // Explicit Confirm: Actual execution
        $this->artisan('vessels:purge-cargo-tanker --confirm')
            ->expectsOutputToContain('PERINGATAN: MENJALANKAN PENGHAPUSAN AKTUAL')
            ->assertSuccessful();

        $this->assertEquals(0, Vessel::where('mmsi', '525444004')->count());
        $this->assertEquals(0, Vessel::where('mmsi', '525444005')->count());
        $this->assertEquals(0, Vessel::where('mmsi', '525444006')->count());
        $this->assertTrue(ProhibitedVessel::isProhibited('525444004'));
        $this->assertTrue(ProhibitedVessel::isProhibited('525444005'));
        $this->assertTrue(ProhibitedVessel::isProhibited('525444006'));
    }
}
