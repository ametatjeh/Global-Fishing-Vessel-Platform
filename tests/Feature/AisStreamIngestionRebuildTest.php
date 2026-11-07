<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AisStreamIngestionRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-ais-key']);
    }

    public function test_vessel_type_normalizer_distinguishes_fishing_from_cargo_and_unknown(): void
    {
        $this->assertEquals('fishing', AisStreamAdapter::normalizeVesselType(30));
        $this->assertEquals('military', AisStreamAdapter::normalizeVesselType(35));
        $this->assertEquals('sailing', AisStreamAdapter::normalizeVesselType(36));
        $this->assertEquals('pleasure', AisStreamAdapter::normalizeVesselType(37));
        $this->assertEquals('pilot', AisStreamAdapter::normalizeVesselType(50));
        $this->assertEquals('sar', AisStreamAdapter::normalizeVesselType(51));
        $this->assertEquals('tug', AisStreamAdapter::normalizeVesselType(52));
        $this->assertEquals('port_tender', AisStreamAdapter::normalizeVesselType(53));
        $this->assertEquals('anti_pollution', AisStreamAdapter::normalizeVesselType(54));
        $this->assertEquals('patrol', AisStreamAdapter::normalizeVesselType(55));
        $this->assertEquals('unknown', AisStreamAdapter::normalizeVesselType(56));
        $this->assertEquals('unknown', AisStreamAdapter::normalizeVesselType(57));
        $this->assertEquals('medical', AisStreamAdapter::normalizeVesselType(58));
        $this->assertEquals('non_combatant', AisStreamAdapter::normalizeVesselType(59));
        $this->assertEquals('passenger', AisStreamAdapter::normalizeVesselType(60));
        $this->assertEquals('passenger', AisStreamAdapter::normalizeVesselType(69));
        $this->assertEquals('cargo', AisStreamAdapter::normalizeVesselType(70));
        $this->assertEquals('cargo', AisStreamAdapter::normalizeVesselType(75));
        $this->assertEquals('cargo', AisStreamAdapter::normalizeVesselType(79));
        $this->assertEquals('tanker', AisStreamAdapter::normalizeVesselType(80));
        $this->assertEquals('tanker', AisStreamAdapter::normalizeVesselType(84));
        $this->assertEquals('tanker', AisStreamAdapter::normalizeVesselType(89));
        $this->assertEquals('unknown', AisStreamAdapter::normalizeVesselType(99));
        $this->assertEquals('unknown', AisStreamAdapter::normalizeVesselType(0));
        $this->assertEquals('unknown', AisStreamAdapter::normalizeVesselType(null));
        $this->assertEquals('unknown', AisStreamAdapter::normalizeVesselType(''));
        $this->assertEquals('unknown', AisStreamAdapter::normalizeVesselType('UNKNOWN_VESSEL'));
        $this->assertEquals('fishing', AisStreamAdapter::normalizeVesselType('fishing'));
        $this->assertEquals('cargo', AisStreamAdapter::normalizeVesselType('cargo'));
        $this->assertEquals('tanker', AisStreamAdapter::normalizeVesselType('tanker'));
    }

    public function test_position_report_does_not_force_unknown_vessel_to_fishing(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $position = $adapter->ingestPositionMessage([
            'mmsi' => '211233660',
            'latitude' => 53.53658,
            'longitude' => 8.57776,
            'speed_over_ground' => 12.4,
            'course_over_ground' => 144.1,
            'true_heading' => 229,
            'vessel_name' => 'WESERLOTSE',
            'timestamp' => '2026-11-07T03:12:00Z',
        ]);

        $this->assertNotNull($position);
        $vessel = $position->vessel;
        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertEquals('WESERLOTSE', $vessel->name);
    }

    public function test_position_report_filters_sentinel_values(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $position = $adapter->ingestPositionMessage([
            'mmsi' => '525001002',
            'latitude' => -5.1234,
            'longitude' => 106.5678,
            'speed_over_ground' => 102.3, // Sentinel for not available
            'course_over_ground' => 360.0, // Sentinel for not available
            'true_heading' => 511, // Sentinel for not available
            'timestamp' => '2026-11-07T04:00:00Z',
        ]);

        $this->assertNotNull($position);
        $this->assertNull($position->speed_knots);
        $this->assertNull($position->course_degrees);
        $this->assertNull($position->heading_degrees);
    }

    public function test_ship_static_data_updates_vessel_identity_and_type_without_forcing_fishing(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // 1. First seen as unknown from PositionReport
        $pos = $adapter->ingestPositionMessage([
            'mmsi' => '319156300',
            'latitude' => 43.587,
            'longitude' => 7.130,
            'timestamp' => '2026-11-07T04:10:00Z',
        ]);
        $this->assertEquals('unknown', $pos->vessel->vessel_type);

        // 2. Static packet arrives with IMO, Callsign, Type 60 (Passenger)
        $vessel = $adapter->ingestStaticMessage([
            'mmsi' => '319156300',
            'vessel_name' => 'PAPA',
            'callsign' => 'ZGIJ3',
            'imo' => '9794549',
            'ship_type' => 60, // Passenger
            'length_meters' => 25.0,
            'timestamp' => '2026-11-07T04:10:05Z',
        ]);

        $this->assertNotNull($vessel);
        $this->assertEquals('PAPA', $vessel->name);
        $this->assertEquals('ZGIJ3', $vessel->callsign);
        $this->assertEquals('9794549', $vessel->imo);
        $this->assertEquals('passenger', $vessel->vessel_type);
        $this->assertEquals(25.0, $vessel->length_meters);
    }

    public function test_position_deduplication_is_idempotent(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $payload = [
            'mmsi' => '525999888',
            'latitude' => -6.1234,
            'longitude' => 106.7890,
            'speed_over_ground' => 8.5,
            'course_over_ground' => 90.0,
            'timestamp' => '2026-11-07T04:15:00Z',
        ];

        $pos1 = $adapter->ingestPositionMessage($payload);
        $pos2 = $adapter->ingestPositionMessage($payload);

        $this->assertEquals($pos1->id, $pos2->id);
        $this->assertEquals(1, VesselPosition::where('vessel_id', $pos1->vessel_id)->count());
    }

    public function test_static_data_report_type_24_part_b_updates_vessel_callsign_and_type(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $vessel = $adapter->ingestStaticMessage([
            'mmsi' => '265518880',
            'vessel_name' => 'RESCUE BURRE',
            'callsign' => 'SM1234',
            'ship_type' => 30, // Fishing
            'length_meters' => 16.0,
            'timestamp' => '2026-11-07T04:20:00Z',
        ]);

        $this->assertNotNull($vessel);
        $this->assertEquals('RESCUE BURRE', $vessel->name);
        $this->assertEquals('SM1234', $vessel->callsign);
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals(16.0, $vessel->length_meters);
    }

    public function test_type_24_part_a_and_part_b_sequential_merge(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // 1. Part A arrives with Ship Name
        $frameA = [
            'MessageType' => 'StaticDataReport',
            'Message' => [
                'StaticDataReport' => [
                    'UserID' => 525888111,
                    'ReportA' => [
                        'Name' => 'PACIFIC EXPLORER@@@@',
                    ],
                ],
            ],
            'MetaData' => [
                'MMSI' => 525888111,
                'time_utc' => '2026-11-07 05:00:00.000000000 +0000 UTC',
            ],
        ];

        $resA = $adapter->parseAndIngestRawFrame($frameA);
        $this->assertEquals('inserted', $resA['status']);

        $vesselA = Vessel::where('mmsi', '525888111')->first();
        $this->assertNotNull($vesselA);
        $this->assertEquals('PACIFIC EXPLORER', $vesselA->name);
        $this->assertNull($vesselA->callsign);
        $this->assertEquals('unknown', $vesselA->vessel_type);

        // 2. Part B arrives with CallSign and ShipType (30: fishing)
        $frameB = [
            'MessageType' => 'StaticDataReport',
            'Message' => [
                'StaticDataReport' => [
                    'UserID' => 525888111,
                    'ReportB' => [
                        'CallSign' => 'YDB321@@',
                        'ShipType' => 30,
                        'Dimension' => ['A' => 15, 'B' => 10, 'C' => 3, 'D' => 3],
                    ],
                ],
            ],
            'MetaData' => [
                'MMSI' => 525888111,
                'time_utc' => '2026-11-07 05:00:02.000000000 +0000 UTC',
            ],
        ];

        $resB = $adapter->parseAndIngestRawFrame($frameB);
        $this->assertEquals('inserted', $resB['status']);

        $vesselB = Vessel::where('mmsi', '525888111')->first();
        $this->assertNotNull($vesselB);
        $this->assertEquals('PACIFIC EXPLORER', $vesselB->name);
        $this->assertEquals('YDB321', $vesselB->callsign);
        $this->assertEquals('fishing', $vesselB->vessel_type);
        $this->assertEquals(25.0, $vesselB->length_meters);
    }

    public function test_batch_endpoint_requires_valid_x_ais_token(): void
    {
        config(['maritime.providers.ais_stream.ingest_token' => 'secure-ingest-token-123']);

        // 1. Missing header -> 401
        $response = $this->postJson('/api/telemetry/ais/batch', [
            'messages' => [
                ['mmsi' => '525111222', 'latitude' => -6.1, 'longitude' => 106.8],
            ],
        ]);
        $response->assertStatus(401);

        // 2. Invalid header -> 401
        $responseBad = $this->withHeaders(['X-AIS-Token' => 'wrong-token'])
            ->postJson('/api/telemetry/ais/batch', [
                'messages' => [
                    ['mmsi' => '525111222', 'latitude' => -6.1, 'longitude' => 106.8],
                ],
            ]);
        $responseBad->assertStatus(401);
    }

    public function test_batch_endpoint_ingests_messages_with_valid_x_ais_token(): void
    {
        config(['maritime.providers.ais_stream.ingest_token' => 'secure-ingest-token-123']);

        $batchPayload = [
            'messages' => [
                // Position report
                [
                    'MessageType' => 'PositionReport',
                    'Message' => [
                        'PositionReport' => [
                            'UserID' => 525000111,
                            'Latitude' => -5.998,
                            'Longitude' => 106.882,
                            'Sog' => 10.5,
                            'Cog' => 180.0,
                            'TrueHeading' => 180,
                        ],
                    ],
                    'MetaData' => [
                        'MMSI' => 525000111,
                        'ShipName' => 'KM LAUTAN BARU@@@',
                        'time_utc' => '2026-11-07 05:10:00.000000000 +0000 UTC',
                    ],
                ],
                // Static report
                [
                    'MessageType' => 'ShipStaticData',
                    'Message' => [
                        'ShipStaticData' => [
                            'UserID' => 525000111,
                            'Name' => 'KM LAUTAN BARU@@@',
                            'CallSign' => 'YD888@@',
                            'ImoNumber' => 9123456,
                            'Type' => 60, // Passenger
                            'Dimension' => ['A' => 40, 'B' => 10],
                        ],
                    ],
                    'MetaData' => [
                        'MMSI' => 525000111,
                        'time_utc' => '2026-11-07 05:10:05.000000000 +0000 UTC',
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['X-AIS-Token' => 'secure-ingest-token-123'])
            ->postJson('/api/telemetry/ais/batch', $batchPayload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'received' => 2,
            'inserted' => 2,
            'skipped' => 0,
            'errors_count' => 0,
        ]);

        $vessel = Vessel::where('mmsi', '525000111')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('KM LAUTAN BARU', $vessel->name);
        $this->assertEquals('YD888', $vessel->callsign);
        $this->assertEquals('9123456', $vessel->imo);
        $this->assertEquals('passenger', $vessel->vessel_type);

        $position = VesselPosition::where('vessel_id', $vessel->id)->first();
        $this->assertNotNull($position);
        $this->assertEquals(-5.998, (float) $position->latitude);
        $this->assertEquals(106.882, (float) $position->longitude);
        $this->assertNotNull($position->raw_payload);
    }

    public function test_ais_shiptype_70_to_79_classifies_as_cargo_and_records_provenance(): void
    {
        $adapter = app(AisStreamAdapter::class);

        foreach ([70, 74, 79] as $code) {
            $this->assertEquals('cargo', AisStreamAdapter::normalizeVesselType($code));

            $mmsi = '5257000' . $code;
            $pos = $adapter->ingestPositionMessage([
                'mmsi' => $mmsi,
                'latitude' => 1.25,
                'longitude' => 103.85,
                'ship_type' => $code,
                'vessel_name' => 'CARRIER ' . $code,
                'timestamp' => '2026-11-07T06:00:00Z',
            ]);

            // Under Section C, cargo is rejected and blocked from ingestion
            $this->assertNull($pos);
            $this->assertTrue(\App\Models\ProhibitedVessel::isProhibited($mmsi));
        }
    }

    public function test_ais_shiptype_80_to_89_classifies_as_tanker_and_records_provenance(): void
    {
        $adapter = app(AisStreamAdapter::class);

        foreach ([80, 85, 89] as $code) {
            $this->assertEquals('tanker', AisStreamAdapter::normalizeVesselType($code));

            $mmsi = '5258000' . $code;
            $vessel = $adapter->ingestStaticMessage([
                'mmsi' => $mmsi,
                'vessel_name' => 'BUNKER ' . $code,
                'ship_type' => $code,
                'callsign' => 'YB' . $code,
                'timestamp' => '2026-11-07T06:05:00Z',
            ]);

            // Under Section C, tanker is rejected and blocked from ingestion
            $this->assertNull($vessel);
            $this->assertTrue(\App\Models\ProhibitedVessel::isProhibited($mmsi));
        }
    }

    public function test_no_external_http_or_api_called_during_cargo_and_tanker_classification(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $adapter = app(AisStreamAdapter::class);

        $adapter->ingestPositionMessage([
            'mmsi' => '525777111',
            'latitude' => -6.1,
            'longitude' => 106.8,
            'ship_type' => 70, // Cargo
            'timestamp' => '2026-11-07T06:10:00Z',
        ]);

        $adapter->ingestStaticMessage([
            'mmsi' => '525888222',
            'vessel_name' => 'OCEAN OIL',
            'ship_type' => 80, // Tanker
            'timestamp' => '2026-11-07T06:10:05Z',
        ]);

        // Assert strictly zero HTTP client calls were made
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_message_without_shiptype_results_in_unknown_without_guessing(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // Vessel with obvious name containing 'CARGO' but null ship_type
        $posCargo = $adapter->ingestPositionMessage([
            'mmsi' => '525999111',
            'latitude' => 2.5,
            'longitude' => 101.5,
            'vessel_name' => 'MEGA CONTAINER CARGO SHIP',
            'ship_type' => null, // Missing ShipType
            'timestamp' => '2026-11-07T06:15:00Z',
        ]);

        $this->assertNotNull($posCargo);
        $this->assertEquals('unknown', $posCargo->vessel->vessel_type);
        $this->assertNull($posCargo->vessel->metadata['classification_source'] ?? null);

        // Vessel with name containing 'TANKER' but empty ship_type
        $vesselTanker = $adapter->ingestStaticMessage([
            'mmsi' => '525999222',
            'vessel_name' => 'VERY LARGE CRUDE TANKER',
            'ship_type' => '', // Empty ShipType
            'timestamp' => '2026-11-07T06:15:05Z',
        ]);

        $this->assertNotNull($vesselTanker);
        $this->assertEquals('unknown', $vesselTanker->vessel_type);
        $this->assertNull($vesselTanker->metadata['classification_source'] ?? null);
    }

    public function test_raw_payload_preserved_unaltered_as_source_evidence(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $rawFrame = [
            'MessageType' => 'PositionReport',
            'Message' => [
                'PositionReport' => [
                    'UserID' => 525777333,
                    'Latitude' => 3.123456,
                    'Longitude' => 104.987654,
                    'Sog' => 14.2,
                    'Cog' => 270.5,
                    'TrueHeading' => 271,
                ],
            ],
            'MetaData' => [
                'MMSI' => 525777333,
                'ShipName' => 'SEASPAN FRONTIER',
                'time_utc' => '2026-11-07 06:20:00.123456789 +0000 UTC',
            ],
        ];

        $res = $adapter->parseAndIngestRawFrame($rawFrame);
        $this->assertEquals('inserted', $res['status']);

        $position = VesselPosition::whereHas('vessel', fn ($q) => $q->where('mmsi', '525777333'))->first();
        $this->assertNotNull($position);

        // Raw payload must be stored pristine without alterations
        $this->assertEquals($rawFrame, $position->raw_payload);
        $this->assertEquals('SEASPAN FRONTIER', $position->raw_payload['MetaData']['ShipName']);
        $this->assertEquals(525777333, $position->raw_payload['Message']['PositionReport']['UserID']);
    }

    public function test_external_api_failure_or_gfw_payload_does_not_alter_local_ais_cargo_or_tanker(): void
    {
        $provider = DataProvider::firstOrCreate(
            ['slug' => 'global-fishing-watch'],
            ['name' => 'GFW Events', 'type' => 'gfw_events']
        );

        // 1. Existing local AIS Cargo vessel pre-established in DB
        $cargoVessel = Vessel::create([
            'mmsi' => '525777888',
            'name' => 'SINGAPORE EXPRESS',
            'vessel_type' => 'cargo',
            'source_provider_id' => $provider->id,
            'metadata' => [
                'classification_source' => 'ais_stream_ship_type',
                'ais_ship_type_code' => 70,
            ],
        ]);
        $this->assertEquals('cargo', $cargoVessel->vessel_type);
        $this->assertEquals('ais_stream_ship_type', $cargoVessel->metadata['classification_source']);

        // 2. GFW sync attempt tries to resolve the same vessel or set cargo/tanker
        $gfw = app(\App\Services\Providers\GlobalFishingWatchAdapter::class);
        $reflection = new \ReflectionClass($gfw);
        $resolveMethod = $reflection->getMethod('resolveVessel');
        $resolveMethod->setAccessible(true);

        // Resolving with existing MMSI: must NOT overwrite existing cargo classification
        $resolved = $resolveMethod->invoke($gfw, [
            'mmsi' => '525777888',
            'vesselType' => 'fishing',
        ], $provider->id);

        $cargoVessel->refresh();
        $this->assertEquals('cargo', $cargoVessel->vessel_type);
        $this->assertEquals('ais_stream_ship_type', $cargoVessel->metadata['classification_source']);

        // 3. Resolving a NEW vessel where GFW attempts to classify as 'cargo': strictly prevented and forced to 'unknown'
        $gfwCargoAttempt = $resolveMethod->invoke($gfw, [
            'mmsi' => '525999555',
            'name' => 'EXTERNAL CARGO SHIP',
            'vesselType' => 'cargo', // External API claiming cargo
        ], $provider->id);

        $this->assertEquals('unknown', $gfwCargoAttempt->vessel_type);
        $this->assertNull($gfwCargoAttempt->metadata['classification_source'] ?? null);

        // 4. Resolving a NEW vessel where GFW attempts to classify as 'tanker': strictly prevented and forced to 'unknown'
        $gfwTankerAttempt = $resolveMethod->invoke($gfw, [
            'mmsi' => '525999666',
            'name' => 'EXTERNAL OIL TANKER',
            'vesselType' => 'tanker', // External API claiming tanker
        ], $provider->id);

        $this->assertEquals('unknown', $gfwTankerAttempt->vessel_type);
        $this->assertNull($gfwTankerAttempt->metadata['classification_source'] ?? null);
    }
}

