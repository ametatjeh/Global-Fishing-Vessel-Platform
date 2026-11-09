<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AisVesselStaticDataClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected AisStreamAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['maritime.providers.ais_stream.api_key' => 'test-secret-key-12345']);
        $this->adapter = app(AisStreamAdapter::class);
        $this->adapter->resetMetrics();
        Cache::flush();
    }

    /**
     * 1. PositionReport only:
     * Creates vessel awaiting static data, vessel_type=unknown, ais_ship_type_code=null, static_data_received_at=null.
     * UI label must display 'AWAITING STATIC DATA'.
     */
    public function test_position_report_only_creates_vessel_awaiting_static_data(): void
    {
        $payload = [
            'MessageType' => 'PositionReport',
            'MetaData' => [
                'MMSI' => 123456789,
                'ShipName' => 'PACIFIC VOYAGER',
                'time_utc' => '2026-11-09T22:00:00Z',
            ],
            'Message' => [
                'PositionReport' => [
                    'UserID' => 123456789,
                    'Latitude' => -6.1234,
                    'Longitude' => 106.8765,
                    'Sog' => 12.4,
                    'Cog' => 180.0,
                    'TrueHeading' => 180,
                    'NavigationalStatus' => 0,
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($payload);

        $this->assertEquals('inserted', $res['status']);
        $this->assertInstanceOf(VesselPosition::class, $res['model']);

        $vessel = Vessel::where('mmsi', '123456789')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertNull($vessel->ais_ship_type_code);
        $this->assertNull($vessel->static_data_received_at);
        $this->assertEquals('AWAITING STATIC DATA', $vessel->display_vessel_type);

        $position = VesselPosition::where('vessel_id', $vessel->id)->first();
        $this->assertNotNull($position);
        $this->assertEquals(-6.1234, $position->latitude);
        $this->assertEquals(106.8765, $position->longitude);
    }

    /**
     * 2. ShipStaticData (Type 5):
     * Parses Type 70 (Cargo), Name, IMO, CallSign, Dimension (length & beam).
     * Vessel becomes CARGO with static_data_received_at recorded.
     */
    public function test_ship_static_data_populates_cargo_type_and_dimensions(): void
    {
        $payload = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => [
                'MMSI' => 222333444,
                'ShipName' => 'EVER FORWARD@@@@',
                'time_utc' => '2026-11-09T22:05:00Z',
            ],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => 222333444,
                    'Name' => 'EVER FORWARD@@@@',
                    'CallSign' => 'VRGL2@@',
                    'ImoNumber' => 9856789,
                    'Type' => 70, // Cargo
                    'Dimension' => [
                        'A' => 150,
                        'B' => 50,
                        'C' => 15,
                        'D' => 15,
                    ],
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($payload);

        $this->assertEquals('inserted', $res['status']);
        $this->assertInstanceOf(Vessel::class, $res['model']);

        $vessel = Vessel::where('mmsi', '222333444')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('cargo', $vessel->vessel_type);
        $this->assertEquals(70, $vessel->ais_ship_type_code);
        $this->assertEquals('EVER FORWARD', $vessel->name);
        $this->assertEquals('VRGL2', $vessel->callsign);
        $this->assertEquals('9856789', $vessel->imo);
        $this->assertEquals(200.0, $vessel->length_meters);
        $this->assertEquals(30.0, $vessel->beam_meters);
        $this->assertNotNull($vessel->static_data_received_at);
        $this->assertEquals('CARGO', $vessel->display_vessel_type);
    }

    /**
     * 3. StaticDataReport (Type 24):
     * Parses ReportA (Name) and ReportB (ShipType 80 -> Tanker, CallSign, Dimension).
     */
    public function test_static_data_report_populates_tanker_type_from_report_b(): void
    {
        $payload = [
            'MessageType' => 'StaticDataReport',
            'MetaData' => [
                'MMSI' => 333444555,
                'time_utc' => '2026-11-09T22:10:00Z',
            ],
            'Message' => [
                'StaticDataReport' => [
                    'UserID' => 333444555,
                    'ReportA' => [
                        'Name' => 'OCEAN QUEEN@@@@',
                    ],
                    'ReportB' => [
                        'ShipType' => 80, // Tanker
                        'CallSign' => '3EWY8',
                        'Dimension' => [
                            'A' => 80,
                            'B' => 20,
                            'C' => 10,
                            'D' => 6,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($payload);

        $this->assertEquals('inserted', $res['status']);
        $vessel = Vessel::where('mmsi', '333444555')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('tanker', $vessel->vessel_type);
        $this->assertEquals(80, $vessel->ais_ship_type_code);
        $this->assertEquals('OCEAN QUEEN', $vessel->name);
        $this->assertEquals('3EWY8', $vessel->callsign);
        $this->assertEquals(100.0, $vessel->length_meters);
        $this->assertEquals(16.0, $vessel->beam_meters);
        $this->assertEquals('TANKER', $vessel->display_vessel_type);
    }

    /**
     * 4. Static data arrives BEFORE PositionReport:
     * Static data sets Fishing type (30).
     * Subsequent PositionReport matches vessel, does NOT overwrite type to unknown,
     * and inserts position successfully without creating duplicate vessels.
     */
    public function test_static_data_arriving_before_position_report_is_preserved(): void
    {
        $mmsi = 444555666;

        // Step 1: ShipStaticData arrives first
        $staticPayload = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T22:15:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsi,
                    'Name' => 'KM SURYA JAYA',
                    'Type' => 30, // Fishing
                    'CallSign' => 'YBW8',
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($staticPayload);

        // Step 2: PositionReport arrives later
        $posPayload = [
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T22:20:00Z'],
            'Message' => [
                'PositionReport' => [
                    'UserID' => $mmsi,
                    'Latitude' => -5.4321,
                    'Longitude' => 105.1234,
                    'Sog' => 8.5,
                    'Cog' => 90.0,
                    'NavigationalStatus' => 7, // Engaged in fishing
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($posPayload);

        // Verify: Single vessel, type preserved as fishing
        $this->assertEquals(1, Vessel::where('mmsi', (string) $mmsi)->count());
        $vessel = Vessel::where('mmsi', (string) $mmsi)->first();
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals(30, $vessel->ais_ship_type_code);
        $this->assertEquals('FISHING', $vessel->display_vessel_type);

        // Position inserted
        $this->assertEquals(1, VesselPosition::where('vessel_id', $vessel->id)->count());
    }

    /**
     * 5. Static data arrives AFTER PositionReport:
     * PositionReport creates initial vessel (unknown).
     * Subsequent ShipStaticData updates identity and type to Cargo.
     * All position history is preserved.
     */
    public function test_static_data_arriving_after_position_report_updates_identity_and_type(): void
    {
        $mmsi = 555666777;

        // Step 1: PositionReport arrives first
        $posPayload = [
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T22:25:00Z'],
            'Message' => [
                'PositionReport' => [
                    'UserID' => $mmsi,
                    'Latitude' => 1.2500,
                    'Longitude' => 103.8500,
                    'Sog' => 14.2,
                    'Cog' => 245.0,
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($posPayload);

        $initialVessel = Vessel::where('mmsi', (string) $mmsi)->first();
        $this->assertEquals('unknown', $initialVessel->vessel_type);
        $this->assertEquals('AWAITING STATIC DATA', $initialVessel->display_vessel_type);

        // Step 2: ShipStaticData arrives later
        $staticPayload = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T22:30:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsi,
                    'Name' => 'MAERSK TENDER',
                    'CallSign' => 'OUJH2',
                    'ImoNumber' => 9723456,
                    'Type' => 70, // Cargo
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($staticPayload);

        // Verify: Single vessel, upgraded to cargo, position intact
        $this->assertEquals(1, Vessel::where('mmsi', (string) $mmsi)->count());
        $updatedVessel = Vessel::where('mmsi', (string) $mmsi)->first();
        $this->assertEquals('cargo', $updatedVessel->vessel_type);
        $this->assertEquals(70, $updatedVessel->ais_ship_type_code);
        $this->assertEquals('CARGO', $updatedVessel->display_vessel_type);
        $this->assertEquals('MAERSK TENDER', $updatedVessel->name);
        $this->assertNotNull($updatedVessel->static_data_received_at);

        $this->assertEquals(1, VesselPosition::where('vessel_id', $updatedVessel->id)->count());
    }

    /**
     * 6. ShipType 0 handling:
     * - New vessel with Type 0 has vessel_type 'not_available', display 'NOT AVAILABLE'.
     * - An already valid vessel receiving Type 0 does NOT have its valid type overwritten.
     */
    public function test_shiptype_zero_sets_not_available_and_does_not_overwrite_valid_type(): void
    {
        // Case A: New vessel with code 0
        $mmsiA = 666777888;
        $staticA = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsiA, 'time_utc' => '2026-11-09T22:35:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsiA,
                    'Name' => 'UNKNOWN CRAFT',
                    'Type' => 0, // Not available
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($staticA);
        $vesselA = Vessel::where('mmsi', (string) $mmsiA)->first();
        $this->assertEquals('not_available', $vesselA->vessel_type);
        $this->assertEquals(0, $vesselA->ais_ship_type_code);
        $this->assertEquals('NOT AVAILABLE', $vesselA->display_vessel_type);

        // Case B: Existing valid fishing vessel receives Code 0
        $mmsiB = 666777999;
        $staticB1 = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsiB, 'time_utc' => '2026-11-09T22:36:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsiB,
                    'Name' => 'SEASPAN LEADER',
                    'Type' => 52, // Tug
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($staticB1);
        $vesselB = Vessel::where('mmsi', (string) $mmsiB)->first();
        $this->assertEquals('tug', $vesselB->vessel_type);
        $this->assertEquals('TUG', $vesselB->display_vessel_type);

        // Now code 0 arrives for vessel B
        $staticB2 = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsiB, 'time_utc' => '2026-11-09T22:40:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsiB,
                    'Type' => 0, // 0 arrives
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($staticB2);
        $vesselB->refresh();

        // Valid type 'tug' and original valid code MUST NOT be overwritten by 0!
        $this->assertEquals('tug', $vesselB->vessel_type);
        $this->assertEquals(52, $vesselB->ais_ship_type_code);
        $this->assertEquals('TUG', $vesselB->display_vessel_type);
    }

    /**
     * 7. Unmapped / unrecognized ShipType code:
     * Code outside 0-99 (e.g. 999) maps to 'unmapped' and displays 'UNMAPPED AIS CODE'.
     */
    public function test_unmapped_shiptype_code_sets_unmapped_status(): void
    {
        $mmsi = 777888999;
        $payload = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T22:45:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsi,
                    'Name' => 'EXPERIMENTAL CRAFT',
                    'Type' => 999, // Unmapped code
                ],
            ],
        ];

        $this->adapter->parseAndIngestRawFrame($payload);
        $vessel = Vessel::where('mmsi', (string) $mmsi)->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('unmapped', $vessel->vessel_type);
        $this->assertEquals(999, $vessel->ais_ship_type_code);
        $this->assertEquals('UNMAPPED AIS CODE', $vessel->display_vessel_type);
    }

    /**
     * 8. Empty static data does not overwrite valid data:
     * When new static message has empty name, callsign, imo, or dimensions,
     * the existing valid values are safely preserved.
     */
    public function test_empty_static_data_does_not_overwrite_valid_data(): void
    {
        $mmsi = 888999111;

        // Step 1: Fully populated static message
        $payload1 = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T22:50:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsi,
                    'Name' => 'GLORY STAR',
                    'CallSign' => 'CALL123',
                    'ImoNumber' => 9123456,
                    'Type' => 70, // Cargo
                    'Dimension' => ['A' => 60, 'B' => 40, 'C' => 10, 'D' => 10],
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($payload1);

        $vessel = Vessel::where('mmsi', (string) $mmsi)->first();
        $this->assertEquals('GLORY STAR', $vessel->name);
        $this->assertEquals('CALL123', $vessel->callsign);
        $this->assertEquals('9123456', $vessel->imo);
        $this->assertEquals(100.0, $vessel->length_meters);
        $this->assertEquals(20.0, $vessel->beam_meters);

        // Step 2: Empty static data message (empty strings / nulls)
        $payload2 = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T22:55:00Z'],
            'Message' => [
                'ShipStaticData' => [
                    'UserID' => $mmsi,
                    'Name' => '',
                    'CallSign' => '',
                    'ImoNumber' => null,
                    'Type' => 70,
                    'Dimension' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0],
                ],
            ],
        ];
        $this->adapter->parseAndIngestRawFrame($payload2);

        $vessel->refresh();
        // None of the valid fields should be blanked out!
        $this->assertEquals('GLORY STAR', $vessel->name);
        $this->assertEquals('CALL123', $vessel->callsign);
        $this->assertEquals('9123456', $vessel->imo);
        $this->assertEquals(100.0, $vessel->length_meters);
        $this->assertEquals(20.0, $vessel->beam_meters);
        $this->assertEquals('cargo', $vessel->vessel_type);
    }

    /**
     * 9. ExtendedClassBPositionReport (Type 19):
     * Ingests both static metadata (type 36 -> sailing) and position telemetry in single frame.
     */
    public function test_extended_class_b_position_report_ingests_both_static_and_position(): void
    {
        $mmsi = 999111222;
        $payload = [
            'MessageType' => 'ExtendedClassBPositionReport',
            'MetaData' => ['MMSI' => $mmsi, 'time_utc' => '2026-11-09T23:00:00Z'],
            'Message' => [
                'ExtendedClassBPositionReport' => [
                    'UserID' => $mmsi,
                    'Name' => 'WIND SPIRIT',
                    'Type' => 36, // Sailing
                    'Latitude' => -8.7654,
                    'Longitude' => 115.1234,
                    'Sog' => 7.2,
                    'Cog' => 120.0,
                    'TrueHeading' => 122,
                    'Dimension' => ['A' => 25, 'B' => 15, 'C' => 4, 'D' => 4],
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($payload);
        $this->assertEquals('inserted', $res['status']);

        $vessel = Vessel::where('mmsi', (string) $mmsi)->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('sailing', $vessel->vessel_type);
        $this->assertEquals('SAILING', $vessel->display_vessel_type);
        $this->assertEquals('WIND SPIRIT', $vessel->name);
        $this->assertEquals(40.0, $vessel->length_meters);
        $this->assertEquals(8.0, $vessel->beam_meters);

        $pos = VesselPosition::where('vessel_id', $vessel->id)->first();
        $this->assertNotNull($pos);
        $this->assertEquals(-8.7654, $pos->latitude);
        $this->assertEquals(115.1234, $pos->longitude);
    }

    /**
     * 10. Frontend /vessels UI and filter integration:
     * Verifies that the mapped categories render on /vessels and can be filtered.
     */
    public function test_vessels_page_and_filter_integration(): void
    {
        $provider = DataProvider::firstOrCreate(
            ['slug' => 'test-provider'],
            ['name' => 'Test', 'type' => 'ais_stream', 'is_enabled' => true]
        );

        // Vessel 1: Fishing
        Vessel::create([
            'mmsi' => '111111111',
            'name' => 'KM LAUTAN REJEKI',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'source_provider_id' => $provider->id,
            'static_data_received_at' => now(),
        ]);

        // Vessel 2: Cargo
        Vessel::create([
            'mmsi' => '222222222',
            'name' => 'MERATUS BANJAR',
            'vessel_type' => 'cargo',
            'ais_ship_type_code' => 70,
            'source_provider_id' => $provider->id,
            'static_data_received_at' => now(),
        ]);

        // Vessel 3: Awaiting Static Data
        Vessel::create([
            'mmsi' => '333333333',
            'name' => 'VESSEL-333333333',
            'vessel_type' => 'unknown',
            'ais_ship_type_code' => null,
            'static_data_received_at' => null,
            'source_provider_id' => $provider->id,
        ]);

        // Vessel 4: Not Available (code 0)
        Vessel::create([
            'mmsi' => '444444444',
            'name' => 'VESSEL-444444444',
            'vessel_type' => 'not_available',
            'ais_ship_type_code' => 0,
            'static_data_received_at' => now(),
            'source_provider_id' => $provider->id,
        ]);

        $user = User::factory()->create();

        // Query all vessels
        $respAll = $this->actingAs($user)->get(route('vessels.index'));
        $respAll->assertStatus(200);
        $respAll->assertSee('FISHING');
        $respAll->assertSee('CARGO');
        $respAll->assertSee('AWAITING STATIC DATA');
        $respAll->assertSee('NOT AVAILABLE');

        // Filter by cargo
        $respCargo = $this->actingAs($user)->get(route('vessels.index', ['vessel_type' => 'cargo']));
        $respCargo->assertStatus(200);
        $respCargo->assertSee('MERATUS BANJAR');
        $respCargo->assertDontSee('KM LAUTAN REJEKI');

        // Filter by awaiting_static
        $respAwaiting = $this->actingAs($user)->get(route('vessels.index', ['vessel_type' => 'awaiting_static']));
        $respAwaiting->assertStatus(200);
        $respAwaiting->assertSee('VESSEL-333333333');
        $respAwaiting->assertDontSee('MERATUS BANJAR');
    }
}
