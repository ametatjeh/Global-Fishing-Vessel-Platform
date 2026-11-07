<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\ProhibitedVessel;
use App\Models\Vessel;
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

    public function test_cargo_and_tanker_vessels_identified_correctly_in_inspection(): void
    {
        $purgeService = app(VesselPurgeService::class);

        // Seed 1 fishing, 1 cargo, 1 tanker
        $fishing = Vessel::create(['name' => 'KM NELAYAN 01', 'mmsi' => '525001001', 'vessel_type' => 'fishing']);
        $cargo = Vessel::create(['name' => 'MV CARGO CONTAINER', 'mmsi' => '525002002', 'vessel_type' => 'cargo']);
        $tanker = Vessel::create(['name' => 'MT OIL TANKER', 'mmsi' => '525003003', 'vessel_type' => 'tanker']);

        VesselPosition::create(['vessel_id' => $fishing->id, 'latitude' => -5.0, 'longitude' => 106.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $cargo->id, 'latitude' => 1.0, 'longitude' => 104.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $tanker->id, 'latitude' => 2.0, 'longitude' => 105.0, 'observed_at' => now(), 'received_at' => now()]);

        VesselIdentifier::create(['vessel_id' => $cargo->id, 'identifier_type' => 'mmsi', 'identifier_value' => '525002002']);
        VesselIdentifier::create(['vessel_id' => $tanker->id, 'identifier_type' => 'mmsi', 'identifier_value' => '525003003']);

        $inspection = $purgeService->inspectPurgeTargets();

        $this->assertEquals(1, $inspection['cargo']['count']);
        $this->assertEquals(1, $inspection['tanker']['count']);
        $this->assertEquals(2, $inspection['total_target_vessels']);
        $this->assertEquals(2, $inspection['impacted_records']['vessel_positions']);
        $this->assertEquals(2, $inspection['impacted_records']['vessel_identifiers']);
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

    public function test_controlled_purge_deletes_only_cargo_and_tanker_leaving_fishing_intact(): void
    {
        $purgeService = app(VesselPurgeService::class);

        $fishing = Vessel::create(['name' => 'KM NELAYAN SAKTI', 'mmsi' => '525001001', 'vessel_type' => 'fishing']);
        $cargo = Vessel::create(['name' => 'EVER CARGO', 'mmsi' => '525002002', 'vessel_type' => 'cargo']);
        $tanker = Vessel::create(['name' => 'PACIFIC TANKER', 'mmsi' => '525003003', 'vessel_type' => 'tanker']);

        $posFish = VesselPosition::create(['vessel_id' => $fishing->id, 'latitude' => -5.0, 'longitude' => 106.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $cargo->id, 'latitude' => 1.0, 'longitude' => 104.0, 'observed_at' => now(), 'received_at' => now()]);
        VesselPosition::create(['vessel_id' => $tanker->id, 'latitude' => 2.0, 'longitude' => 105.0, 'observed_at' => now(), 'received_at' => now()]);

        $result = $purgeService->executePurge(dryRun: false, adminUserId: 1);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(1, $result['deleted']['cargo_vessels']);
        $this->assertEquals(1, $result['deleted']['tanker_vessels']);
        $this->assertEquals(2, $result['deleted']['positions']);

        // Assert cargo and tanker vessels are deleted
        $this->assertEquals(0, Vessel::where('mmsi', '525002002')->count());
        $this->assertEquals(0, Vessel::where('mmsi', '525003003')->count());

        // Assert fishing vessel is 100% intact
        $this->assertEquals(1, Vessel::where('mmsi', '525001001')->count());
        $this->assertEquals(1, VesselPosition::where('id', $posFish->id)->count());

        // Assert MMSIs are registered in ProhibitedVessel registry
        $this->assertTrue(ProhibitedVessel::isProhibited('525002002'));
        $this->assertTrue(ProhibitedVessel::isProhibited('525003003'));

        // Assert audit log was recorded
        $this->assertTrue(AuditLog::where('action', 'purge_cargo_tanker_vessels')->exists());
    }

    public function test_subsequent_ingestion_rejects_cargo_and_tanker_and_prohibited_mmsi(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // 1. Ingestion of AIS Position Report with ShipType 70 (Cargo) is rejected
        $posCargo = $adapter->ingestPositionMessage([
            'mmsi' => '525777001',
            'latitude' => 1.2,
            'longitude' => 103.8,
            'ship_type' => 70, // Cargo
            'timestamp' => '2026-11-07T07:00:00Z',
        ]);
        $this->assertNull($posCargo);
        $this->assertEquals(0, Vessel::where('mmsi', '525777001')->count());
        // Prohibited entry auto-registered
        $this->assertTrue(ProhibitedVessel::isProhibited('525777001'));

        // 2. Ingestion of AIS Static Report with ShipType 80 (Tanker) is rejected
        $vesselTanker = $adapter->ingestStaticMessage([
            'mmsi' => '525888002',
            'vessel_name' => 'NEW TANKER',
            'ship_type' => 80, // Tanker
            'timestamp' => '2026-11-07T07:05:00Z',
        ]);
        $this->assertNull($vesselTanker);
        $this->assertEquals(0, Vessel::where('mmsi', '525888002')->count());
        $this->assertTrue(ProhibitedVessel::isProhibited('525888002'));

        // 3. New positions from already prohibited MMSI are strictly blocked even if ship_type is omitted
        $blockedAttempt = $adapter->ingestPositionMessage([
            'mmsi' => '525777001', // Already prohibited MMSI
            'latitude' => 1.25,
            'longitude' => 103.85,
            'ship_type' => null, // Omitted ship_type
            'timestamp' => '2026-11-07T07:10:00Z',
        ]);
        $this->assertNull($blockedAttempt);
        $this->assertEquals(0, VesselPosition::count());

        // 4. Ingestion of legitimate Fishing vessel (ShipType 30) continues to work perfectly
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
        $this->assertEquals(1, VesselPosition::count());
    }

    public function test_artisan_command_renders_dry_run_and_confirms_controlled_execution(): void
    {
        Vessel::create(['name' => 'CARGO SIMULATION', 'mmsi' => '525444004', 'vessel_type' => 'cargo']);

        // Default: Dry run
        $this->artisan('vessels:purge-cargo-tanker')
            ->expectsOutputToContain('[MODE DRY-RUN AKTIF]')
            ->expectsOutputToContain('Cargo')
            ->assertSuccessful();

        $this->assertEquals(1, Vessel::where('mmsi', '525444004')->count());

        // Explicit Confirm: Actual execution
        $this->artisan('vessels:purge-cargo-tanker --confirm')
            ->expectsOutputToContain('PERINGATAN: MENJALANKAN PENGHAPUSAN AKTUAL')
            ->assertSuccessful();

        $this->assertEquals(0, Vessel::where('mmsi', '525444004')->count());
        $this->assertTrue(ProhibitedVessel::isProhibited('525444004'));
    }
}

