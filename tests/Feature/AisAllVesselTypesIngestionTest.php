<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\ProhibitedVessel;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AisAllVesselTypesIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected AisStreamAdapter $adapter;
    protected string $token = 'test-ingest-token';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'maritime.providers.ais_stream.api_key' => 'test-api-key',
            'maritime.providers.ais_stream.ingest_token' => $this->token,
        ]);
        $this->adapter = app(AisStreamAdapter::class);
    }

    /**
     * Scenario 1: Cargo Ship (type 70-79) accepted & saved in vessels and vessel_positions.
     */
    public function test_01_cargo_ships_are_accepted_and_saved(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525701001',
            'latitude' => 1.25,
            'longitude' => 103.85,
            'speed_over_ground' => 14.5,
            'course_over_ground' => 88.0,
            'ship_type' => 70, // Cargo
            'vessel_name' => 'EVER CARGO 1',
            'timestamp' => '2026-11-10T12:00:00Z',
        ]);

        $this->assertNotNull($pos);
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525701001',
            'vessel_type' => 'cargo',
            'ais_ship_type_code' => 70,
        ]);
        $this->assertDatabaseHas('vessel_positions', [
            'id' => $pos->id,
            'vessel_id' => $pos->vessel_id,
            'latitude' => 1.25,
            'longitude' => 103.85,
        ]);
    }

    /**
     * Scenario 2: Tanker (type 80-89) accepted & saved in vessels and vessel_positions.
     */
    public function test_02_tanker_ships_are_accepted_and_saved(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525801002',
            'latitude' => 2.15,
            'longitude' => 104.50,
            'speed_over_ground' => 11.2,
            'course_over_ground' => 120.0,
            'ship_type' => 80, // Tanker
            'vessel_name' => 'PETRO TANKER 2',
            'timestamp' => '2026-11-10T12:05:00Z',
        ]);

        $this->assertNotNull($pos);
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525801002',
            'vessel_type' => 'tanker',
            'ais_ship_type_code' => 80,
        ]);
        $this->assertDatabaseHas('vessel_positions', [
            'id' => $pos->id,
            'vessel_id' => $pos->vessel_id,
            'latitude' => 2.15,
            'longitude' => 104.50,
        ]);
    }

    /**
     * Scenario 3: Pleasure Craft (type 37) accepted & saved in vessels and vessel_positions.
     */
    public function test_03_pleasure_craft_is_accepted_and_saved(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525371003',
            'latitude' => -8.70,
            'longitude' => 115.20,
            'speed_over_ground' => 8.0,
            'course_over_ground' => 210.0,
            'ship_type' => 37, // Pleasure craft
            'vessel_name' => 'BALI YACHT 3',
            'timestamp' => '2026-11-10T12:10:00Z',
        ]);

        $this->assertNotNull($pos);
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525371003',
            'vessel_type' => 'pleasure',
            'ais_ship_type_code' => 37,
        ]);
        $this->assertDatabaseHas('vessel_positions', [
            'id' => $pos->id,
            'vessel_id' => $pos->vessel_id,
        ]);
    }

    /**
     * Scenario 4: Fishing Vessel (type 30) accepted & processed normally.
     */
    public function test_04_fishing_vessel_is_accepted_and_saved(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525301004',
            'latitude' => -6.50,
            'longitude' => 112.40,
            'speed_over_ground' => 5.5,
            'course_over_ground' => 330.0,
            'ship_type' => 30, // Fishing
            'vessel_name' => 'KM BAHARI 4',
            'timestamp' => '2026-11-10T12:15:00Z',
        ]);

        $this->assertNotNull($pos);
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525301004',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
        ]);
        $this->assertDatabaseHas('vessel_positions', [
            'id' => $pos->id,
            'vessel_id' => $pos->vessel_id,
        ]);
    }

    /**
     * Scenario 5: Passenger Ship (type 60-69) accepted & saved.
     */
    public function test_05_passenger_ship_is_accepted_and_saved(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525601005',
            'latitude' => -5.80,
            'longitude' => 105.75,
            'speed_over_ground' => 16.0,
            'course_over_ground' => 45.0,
            'ship_type' => 60, // Passenger
            'vessel_name' => 'FERRY SELAT SUNDA',
            'timestamp' => '2026-11-10T12:20:00Z',
        ]);

        $this->assertNotNull($pos);
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525601005',
            'vessel_type' => 'passenger',
            'ais_ship_type_code' => 60,
        ]);
        $this->assertDatabaseHas('vessel_positions', [
            'id' => $pos->id,
            'vessel_id' => $pos->vessel_id,
        ]);
    }

    /**
     * Scenario 6: Vessel with ship_type null or unknown accepted & saved without rejection.
     */
    public function test_06_unknown_or_null_ship_type_accepted_and_saved(): void
    {
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525999006',
            'latitude' => 0.50,
            'longitude' => 107.00,
            'speed_over_ground' => 7.0,
            'course_over_ground' => 180.0,
            'ship_type' => null,
            'vessel_name' => 'MYSTERY VESSEL 6',
            'timestamp' => '2026-11-10T12:25:00Z',
        ]);

        $this->assertNotNull($pos);
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525999006',
            'vessel_type' => 'unknown',
        ]);
        $this->assertDatabaseHas('vessel_positions', [
            'id' => $pos->id,
            'vessel_id' => $pos->vessel_id,
        ]);
    }

    /**
     * Scenario 7: MMSI previously in prohibited list can still ingest positions without rejection.
     */
    public function test_07_prohibited_registry_mmsi_can_still_ingest_positions(): void
    {
        ProhibitedVessel::create([
            'mmsi' => '525777007',
            'reason' => 'historical_policy',
            'original_type' => 'cargo',
            'original_name' => 'BLOCKED CARGO',
        ]);

        $this->assertTrue(ProhibitedVessel::isProhibited('525777007'));

        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525777007',
            'latitude' => 3.00,
            'longitude' => 105.00,
            'speed_over_ground' => 12.0,
            'course_over_ground' => 90.0,
            'ship_type' => 70,
            'vessel_name' => 'BLOCKED CARGO RESUMED',
            'timestamp' => '2026-11-10T12:30:00Z',
        ]);

        $this->assertNotNull($pos, 'Positions from previously prohibited MMSI must NOT be rejected');
        $this->assertDatabaseHas('vessel_positions', [
            'id' => $pos->id,
            'vessel_id' => $pos->vessel_id,
        ]);
    }

    /**
     * Scenario 8: Raw PositionReport (Type 1/2/3) frame successfully processed via parseAndIngestRawFrame.
     */
    public function test_08_position_report_raw_frame_processed(): void
    {
        $rawFrame = [
            'MessageType' => 'PositionReport',
            'MetaData' => [
                'MMSI' => '525123008',
                'ShipName' => 'CONTAINER ALPHA',
                'latitude' => -5.20,
                'longitude' => 106.30,
                'time_utc' => '2026-11-10T12:35:00Z',
            ],
            'Message' => [
                'PositionReport' => [
                    'UserID' => 525123008,
                    'Latitude' => -5.20,
                    'Longitude' => 106.30,
                    'Sog' => 15.2,
                    'Cog' => 270.0,
                    'TrueHeading' => 268,
                    'NavigationalStatus' => 0,
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($rawFrame);

        $this->assertEquals('inserted', $res['status']);
        $this->assertEquals('PositionReport', $res['type']);
        $this->assertNotNull($res['model']);
        $this->assertDatabaseHas('vessel_positions', [
            'latitude' => -5.20,
            'longitude' => 106.30,
        ]);
    }

    /**
     * Scenario 9: Raw StandardClassBPositionReport (Type 18) frame successfully processed.
     */
    public function test_09_standard_class_b_position_report_raw_frame_processed(): void
    {
        $rawFrame = [
            'MessageType' => 'StandardClassBPositionReport',
            'MetaData' => [
                'MMSI' => '525123009',
                'ShipName' => 'CLASS B BOAT',
                'latitude' => -6.10,
                'longitude' => 106.90,
                'time_utc' => '2026-11-10T12:40:00Z',
            ],
            'Message' => [
                'StandardClassBPositionReport' => [
                    'UserID' => 525123009,
                    'Latitude' => -6.10,
                    'Longitude' => 106.90,
                    'Sog' => 9.5,
                    'Cog' => 135.0,
                    'TrueHeading' => 511, // sentinel
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($rawFrame);

        $this->assertEquals('inserted', $res['status']);
        $this->assertEquals('StandardClassBPositionReport', $res['type']);
        $this->assertNotNull($res['model']);
        $this->assertNull($res['model']->heading_degrees, 'Sentinel heading 511 should be filtered to null');
    }

    /**
     * Scenario 10: Raw ExtendedClassBPositionReport (Type 19) frame successfully processed.
     */
    public function test_10_extended_class_b_position_report_raw_frame_processed(): void
    {
        $rawFrame = [
            'MessageType' => 'ExtendedClassBPositionReport',
            'MetaData' => [
                'MMSI' => '525123010',
                'ShipName' => 'EXPLORER 19',
                'latitude' => -7.20,
                'longitude' => 112.75,
                'time_utc' => '2026-11-10T12:45:00Z',
            ],
            'Message' => [
                'ExtendedClassBPositionReport' => [
                    'UserID' => 525123010,
                    'Latitude' => -7.20,
                    'Longitude' => 112.75,
                    'Sog' => 10.0,
                    'Cog' => 45.0,
                    'Type' => 52, // Tug
                    'Name' => 'EXPLORER 19',
                    'Dimension' => ['A' => 20, 'B' => 10, 'C' => 5, 'D' => 5],
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($rawFrame);

        $this->assertEquals('inserted', $res['status']);
        $this->assertEquals('ExtendedClassBPositionReport', $res['type']);
        $this->assertDatabaseHas('vessels', [
            'mmsi' => '525123010',
            'vessel_type' => 'tug',
            'length_meters' => 30.0,
            'beam_meters' => 10.0,
        ]);
        $this->assertDatabaseHas('vessel_positions', [
            'latitude' => -7.20,
            'longitude' => 112.75,
        ]);
    }

    /**
     * Scenario 11: ShipStaticData (Type 5) updates name/dimensions/callsign/type without erasing positions.
     */
    public function test_11_ship_static_data_type_5_updates_vessel_without_affecting_positions(): void
    {
        // 1. Initial position creates vessel
        $pos = $this->adapter->ingestPositionMessage([
            'mmsi' => '525123011',
            'latitude' => -3.50,
            'longitude' => 114.50,
            'timestamp' => '2026-11-10T12:00:00Z',
        ]);
        $this->assertNotNull($pos);

        // 2. Static frame arrives
        $rawStatic = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => [
                'MMSI' => '525123011',
                'ShipName' => 'BARITO TANKER',
                'time_utc' => '2026-11-10T12:10:00Z',
            ],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => 525123011,
                    'Name' => 'BARITO TANKER',
                    'CallSign' => 'PKBT',
                    'ImoNumber' => 9123456,
                    'Type' => 80, // Tanker
                    'Dimension' => ['A' => 60, 'B' => 40, 'C' => 10, 'D' => 10],
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($rawStatic);
        $this->assertEquals('inserted', $res['status']);

        $vessel = Vessel::where('mmsi', '525123011')->first();
        $this->assertEquals('BARITO TANKER', $vessel->name);
        $this->assertEquals('PKBT', $vessel->callsign);
        $this->assertEquals('9123456', $vessel->imo);
        $this->assertEquals('tanker', $vessel->vessel_type);
        $this->assertEquals(100.0, (float) $vessel->length_meters);
        $this->assertEquals(20.0, (float) $vessel->beam_meters);

        // Historical position still exists
        $this->assertEquals(1, VesselPosition::where('vessel_id', $vessel->id)->count());
    }

    /**
     * Scenario 12: StaticDataReport (Type 24) updates vessel metadata.
     */
    public function test_12_static_data_report_type_24_updates_metadata(): void
    {
        $rawStaticReport = [
            'MessageType' => 'StaticDataReport',
            'MetaData' => [
                'MMSI' => '525123012',
                'ShipName' => 'CATAMARAN SPEED',
                'time_utc' => '2026-11-10T12:50:00Z',
            ],
            'Message' => [
                'StaticDataReport' => [
                    'UserID' => 525123012,
                    'ReportA' => ['Name' => 'CATAMARAN SPEED'],
                    'ReportB' => [
                        'CallSign' => 'YBCS',
                        'ShipType' => 40, // High Speed Craft
                        'Dimension' => ['A' => 15, 'B' => 10, 'C' => 4, 'D' => 4],
                    ],
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($rawStaticReport);
        $this->assertEquals('inserted', $res['status']);

        $vessel = Vessel::where('mmsi', '525123012')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('CATAMARAN SPEED', $vessel->name);
        $this->assertEquals('YBCS', $vessel->callsign);
        $this->assertEquals('high_speed_craft', $vessel->vessel_type);
        $this->assertEquals(25.0, (float) $vessel->length_meters);
    }

    /**
     * Scenario 13: Older position does not regress newer position pointer (idempotency and order guard).
     */
    public function test_13_older_timestamp_does_not_overwrite_newer_last_seen(): void
    {
        $mmsi = '525123013';
        $newerTime = Carbon::parse('2026-11-10T12:00:00Z');
        $olderTime = Carbon::parse('2026-11-10T10:00:00Z');

        // Ingest newer first
        $posNew = $this->adapter->ingestPositionMessage([
            'mmsi' => $mmsi,
            'latitude' => 1.0,
            'longitude' => 104.0,
            'timestamp' => $newerTime->toIso8601String(),
        ]);
        $this->assertNotNull($posNew);

        $vessel = Vessel::where('mmsi', $mmsi)->first();
        $this->assertEquals($newerTime->timestamp, $vessel->last_seen_at->timestamp);

        // Ingest out-of-order older position
        $posOld = $this->adapter->ingestPositionMessage([
            'mmsi' => $mmsi,
            'latitude' => 0.5,
            'longitude' => 103.5,
            'timestamp' => $olderTime->toIso8601String(),
        ]);
        $this->assertNotNull($posOld);

        $vessel->refresh();
        $this->assertEquals($newerTime->timestamp, $vessel->last_seen_at->timestamp, 'last_seen_at must not be regressed by older packet');
        $this->assertEquals(2, VesselPosition::where('vessel_id', $vessel->id)->count(), 'Both positions preserved in history');
    }

    /**
     * Scenario 14: Invalid coordinates or extreme future timestamps safely rejected or clamped.
     */
    public function test_14_invalid_coordinates_and_future_timestamps_handled_safely(): void
    {
        // Out-of-bounds latitude
        $pos1 = $this->adapter->ingestPositionMessage([
            'mmsi' => '525123014',
            'latitude' => 999.0,
            'longitude' => 100.0,
        ]);
        $this->assertNull($pos1, 'Latitude 999 must be rejected');

        // Out-of-bounds longitude
        $pos2 = $this->adapter->ingestPositionMessage([
            'mmsi' => '525123014',
            'latitude' => 10.0,
            'longitude' => -999.0,
        ]);
        $this->assertNull($pos2, 'Longitude -999 must be rejected');

        // Null coordinates
        $pos3 = $this->adapter->ingestPositionMessage([
            'mmsi' => '525123014',
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->assertNull($pos3, 'Null coordinates must be rejected');

        // Extreme future timestamp clamped safely
        $farFuture = now()->addDays(10);
        $pos4 = $this->adapter->ingestPositionMessage([
            'mmsi' => '525123014',
            'latitude' => 1.0,
            'longitude' => 104.0,
            'timestamp' => $farFuture->toIso8601String(),
        ]);
        $this->assertNotNull($pos4);
        $this->assertTrue($pos4->observed_at->lte(now()->addMinutes(1)));
    }

    /**
     * Scenario 15: Batch endpoint /api/telemetry/ais/batch ingests mixed fleet of fishing, cargo, tanker, pleasure, unknown.
     */
    public function test_15_batch_endpoint_ingests_mixed_fleet(): void
    {
        $batchPayload = [
            'messages' => [
                [
                    'MessageType' => 'PositionReport',
                    'MetaData' => ['MMSI' => '525000001', 'ShipName' => 'FLEET FISHING', 'time_utc' => '2026-11-10T13:00:00Z'],
                    'Message' => ['PositionReport' => ['UserID' => 525000001, 'Latitude' => -5.0, 'Longitude' => 106.0, 'ShipType' => 30]],
                ],
                [
                    'MessageType' => 'PositionReport',
                    'MetaData' => ['MMSI' => '525000002', 'ShipName' => 'FLEET CARGO', 'time_utc' => '2026-11-10T13:00:00Z'],
                    'Message' => ['PositionReport' => ['UserID' => 525000002, 'Latitude' => 1.0, 'Longitude' => 104.0, 'ShipType' => 70]],
                ],
                [
                    'MessageType' => 'PositionReport',
                    'MetaData' => ['MMSI' => '525000003', 'ShipName' => 'FLEET TANKER', 'time_utc' => '2026-11-10T13:00:00Z'],
                    'Message' => ['PositionReport' => ['UserID' => 525000003, 'Latitude' => 2.0, 'Longitude' => 105.0, 'ShipType' => 80]],
                ],
                [
                    'MessageType' => 'PositionReport',
                    'MetaData' => ['MMSI' => '525000004', 'ShipName' => 'FLEET PLEASURE', 'time_utc' => '2026-11-10T13:00:00Z'],
                    'Message' => ['PositionReport' => ['UserID' => 525000004, 'Latitude' => -8.0, 'Longitude' => 115.0, 'ShipType' => 37]],
                ],
                [
                    'MessageType' => 'PositionReport',
                    'MetaData' => ['MMSI' => '525000005', 'ShipName' => 'FLEET UNKNOWN', 'time_utc' => '2026-11-10T13:00:00Z'],
                    'Message' => ['PositionReport' => ['UserID' => 525000005, 'Latitude' => 0.0, 'Longitude' => 107.0]],
                ],
            ],
        ];

        $response = $this->withHeader('X-AIS-Token', $this->token)
            ->postJson('/api/telemetry/ais/batch', $batchPayload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'received' => 5,
                'inserted' => 5,
                'skipped' => 0,
            ]);

        $this->assertEquals(5, Vessel::count());
        $this->assertEquals(5, VesselPosition::count());

        $this->assertEquals('fishing', Vessel::where('mmsi', '525000001')->first()->vessel_type);
        $this->assertEquals('cargo', Vessel::where('mmsi', '525000002')->first()->vessel_type);
        $this->assertEquals('tanker', Vessel::where('mmsi', '525000003')->first()->vessel_type);
        $this->assertEquals('pleasure', Vessel::where('mmsi', '525000004')->first()->vessel_type);
        $this->assertEquals('unknown', Vessel::where('mmsi', '525000005')->first()->vessel_type);
    }
}

