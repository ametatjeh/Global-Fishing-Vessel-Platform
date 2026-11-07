<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\ProhibitedVessel;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AisPhase4aIngestionAndSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-ais-key']);
        config(['maritime.providers.ais_stream.ingest_token' => 'test-valid-token-xyz']);
    }

    /**
     * 1. Test PostgreSQL Migration: columns exist on vessels table.
     */
    public function test_vessels_table_has_provenance_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('vessels', 'classification_source'));
        $this->assertTrue(Schema::hasColumn('vessels', 'ais_ship_type_code'));
        $this->assertTrue(Schema::hasColumn('vessels', 'classified_at'));
    }

    /**
     * 2. Test AIS ShipType 58 (medical) and 59 (non_combatant) mapping.
     */
    public function test_shiptype_58_and_59_mapping(): void
    {
        // Numeric inputs
        $this->assertEquals('medical', AisStreamAdapter::normalizeVesselType(58));
        $this->assertEquals('non_combatant', AisStreamAdapter::normalizeVesselType(59));

        // String inputs
        $this->assertEquals('medical', AisStreamAdapter::normalizeVesselType('medical'));
        $this->assertEquals('non_combatant', AisStreamAdapter::normalizeVesselType('non_combatant'));

        // Neither defaults to fishing
        $this->assertNotEquals('fishing', AisStreamAdapter::normalizeVesselType(58));
        $this->assertNotEquals('fishing', AisStreamAdapter::normalizeVesselType(59));
    }

    /**
     * 3. Test provenance storage in both columns and metadata JSON.
     */
    public function test_provenance_is_stored_in_dedicated_columns_and_metadata(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $vessel = $adapter->ingestStaticMessage([
            'mmsi' => '525111333',
            'vessel_name' => 'KM BERKAH LAUT',
            'ship_type' => 30, // Fishing
            'callsign' => 'YDA999',
            'timestamp' => '2026-11-08T02:00:00Z',
        ]);

        $this->assertNotNull($vessel);
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals('AIS_STATIC_MESSAGE_SHIPTYPE', $vessel->classification_source);
        $this->assertEquals(30, $vessel->ais_ship_type_code);
        $this->assertNotNull($vessel->classified_at);

        // Mirror in metadata for backward compatibility
        $this->assertEquals('AIS_STATIC_MESSAGE_SHIPTYPE', $vessel->metadata['classification_source'] ?? null);
        $this->assertEquals(30, $vessel->metadata['ais_ship_type_code'] ?? null);
        $this->assertNotNull($vessel->metadata['classified_at'] ?? null);
    }

    /**
     * 4. Test out-of-order timestamp protection for static classification updates.
     */
    public function test_out_of_order_static_messages_do_not_regress_classification(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // 1. Ingest newer message first (classified at 10:00:00)
        $newer = $adapter->ingestStaticMessage([
            'mmsi' => '525444555',
            'vessel_name' => 'KM CAHAYA TIMUR',
            'ship_type' => 30, // Fishing
            'timestamp' => '2026-11-08T10:00:00Z',
        ]);

        $this->assertEquals('fishing', $newer->vessel_type);
        $this->assertEquals('2026-11-08 10:00:00', $newer->classified_at->format('Y-m-d H:i:s'));

        // 2. Ingest older message (from 08:00:00 with ship_type 60 = passenger)
        $older = $adapter->ingestStaticMessage([
            'mmsi' => '525444555',
            'vessel_name' => 'KM CAHAYA TIMUR (OLD)',
            'ship_type' => 60, // Passenger
            'callsign' => 'YDX111', // New callsign can still enrich
            'timestamp' => '2026-11-08T08:00:00Z',
        ]);

        $older->refresh();

        // Classification must NOT regress to passenger
        $this->assertEquals('fishing', $older->vessel_type);
        $this->assertEquals(30, $older->ais_ship_type_code);
        $this->assertEquals('2026-11-08 10:00:00', $older->classified_at->format('Y-m-d H:i:s'));
        // Non-classification fields can enrich if previously missing
        $this->assertEquals('YDX111', $older->callsign);
    }

    /**
     * 5. Test Type 19 (ExtendedClassBPositionReport): dual static metadata + position ingestion.
     * Metadata must be preserved even if position is rejected.
     */
    public function test_type_19_preserves_metadata_when_position_is_rejected(): void
    {
        $adapter = app(AisStreamAdapter::class);

        // Payload with invalid latitude (999.0) so position is rejected
        $rawType19 = [
            'MessageType' => 'ExtendedClassBPositionReport',
            'Message' => [
                'ExtendedClassBPositionReport' => [
                    'UserID' => 525888999,
                    'Latitude' => 999.0, // Invalid coordinate
                    'Longitude' => 106.8,
                    'Sog' => 7.2,
                    'Cog' => 45.0,
                    'Name' => 'KM BINTANG SAMUDRA@@@',
                    'ShipType' => 30, // Fishing
                    'Dimension' => ['A' => 20, 'B' => 10],
                ],
            ],
            'MetaData' => [
                'MMSI' => 525888999,
                'time_utc' => '2026-11-08 03:00:00.000000000 +0000 UTC',
            ],
        ];

        $res = $adapter->parseAndIngestRawFrame($rawType19);

        // Status is inserted because static message succeeded
        $this->assertEquals('inserted', $res['status']);

        // Check that Vessel record exists with metadata preserved
        $vessel = Vessel::where('mmsi', '525888999')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('KM BINTANG SAMUDRA', $vessel->name);
        $this->assertEquals('fishing', $vessel->vessel_type);
        $this->assertEquals(30, $vessel->ais_ship_type_code);
        $this->assertEquals(30.0, (float) $vessel->length_meters);

        // But NO position record exists because coordinates were invalid
        $positionCount = VesselPosition::where('vessel_id', $vessel->id)->count();
        $this->assertEquals(0, $positionCount);
    }

    /**
     * 5b. Test Type 19 with valid position ingests both metadata and position.
     */
    public function test_type_19_with_valid_position_ingests_both(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $rawType19 = [
            'MessageType' => 'ExtendedClassBPositionReport',
            'Message' => [
                'ExtendedClassBPositionReport' => [
                    'UserID' => 525777888,
                    'Latitude' => -5.85,
                    'Longitude' => 106.45,
                    'Sog' => 8.1,
                    'Cog' => 92.0,
                    'Name' => 'KM NELAYAN MAKMUR',
                    'ShipType' => 30,
                    'Dimension' => ['A' => 15, 'B' => 5],
                ],
            ],
            'MetaData' => [
                'MMSI' => 525777888,
                'time_utc' => '2026-11-08 03:30:00.000000000 +0000 UTC',
            ],
        ];

        $res = $adapter->parseAndIngestRawFrame($rawType19);
        $this->assertEquals('inserted', $res['status']);

        $vessel = Vessel::where('mmsi', '525777888')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('KM NELAYAN MAKMUR', $vessel->name);
        $this->assertEquals('fishing', $vessel->vessel_type);

        $position = VesselPosition::where('vessel_id', $vessel->id)->first();
        $this->assertNotNull($position);
        $this->assertEquals(-5.85, (float) $position->latitude);
        $this->assertEquals(106.45, (float) $position->longitude);
    }

    /**
     * 6. Test Type 27 (LongRangeBroadcastMessage): position only, strictly does NOT classify as fishing.
     */
    public function test_type_27_ingests_position_without_classifying_as_fishing(): void
    {
        $adapter = app(AisStreamAdapter::class);

        $rawType27 = [
            'MessageType' => 'LongRangeBroadcastMessage',
            'Message' => [
                'LongRangeBroadcastMessage' => [
                    'UserID' => 525123999,
                    'Latitude' => -10.5,
                    'Longitude' => 110.2,
                    'Sog' => 12.0,
                    'Cog' => 180.0,
                ],
            ],
            'MetaData' => [
                'MMSI' => 525123999,
                'time_utc' => '2026-11-08 04:00:00.000000000 +0000 UTC',
            ],
        ];

        $res = $adapter->parseAndIngestRawFrame($rawType27);
        $this->assertEquals('inserted', $res['status']);

        $vessel = Vessel::where('mmsi', '525123999')->first();
        $this->assertNotNull($vessel);

        // Vessel MUST be unknown, NOT fishing
        $this->assertEquals('unknown', $vessel->vessel_type);
        $this->assertNull($vessel->classification_source);
        $this->assertNull($vessel->ais_ship_type_code);

        $position = VesselPosition::where('vessel_id', $vessel->id)->first();
        $this->assertNotNull($position);
        $this->assertEquals(-10.5, (float) $position->latitude);
        $this->assertEquals(110.2, (float) $position->longitude);
    }

    /**
     * 7. Test Prohibited MMSI API Authentication.
     */
    public function test_prohibited_mmsi_api_requires_valid_token(): void
    {
        // 1. Missing token -> 401
        $resNoToken = $this->getJson('/api/telemetry/ais/prohibited-mmsis');
        $resNoToken->assertStatus(401);

        // 2. Invalid token -> 401
        $resBadToken = $this->withHeaders(['X-AIS-Token' => 'invalid-token-123'])
            ->getJson('/api/telemetry/ais/prohibited-mmsis');
        $resBadToken->assertStatus(401);

        // 3. Valid token -> 200
        $resValid = $this->withHeaders(['X-AIS-Token' => 'test-valid-token-xyz'])
            ->getJson('/api/telemetry/ais/prohibited-mmsis');
        $resValid->assertStatus(200);
        $resValid->assertJsonStructure([
            'status',
            'snapshot_version',
            'total_count',
            'returned_count',
            'next_cursor',
            'is_last_page',
            'data',
        ]);
    }

    /**
     * 8. Test Prohibited MMSI API Cursor Pagination and Revocation Handling.
     */
    public function test_prohibited_mmsi_api_pagination_and_revocation(): void
    {
        // Seed 5 prohibited vessels (3 active blocks, 2 unblocked/exceptions)
        ProhibitedVessel::create([
            'mmsi' => '999000001',
            'reason' => 'cargo_policy',
            'original_type' => 'cargo',
            'original_name' => 'CARGO ONE',
        ]);
        ProhibitedVessel::create([
            'mmsi' => '999000002',
            'reason' => 'tanker_policy',
            'original_type' => 'tanker',
            'original_name' => 'TANKER TWO',
        ]);
        ProhibitedVessel::create([
            'mmsi' => '999000003',
            'reason' => 'pleasure_policy',
            'original_type' => 'pleasure',
            'original_name' => 'YACHT THREE',
        ]);
        // Unblocked / Exception granted
        ProhibitedVessel::create([
            'mmsi' => '999000004',
            'reason' => 'cargo_policy',
            'original_type' => 'cargo',
            'original_name' => 'CARGO FOUR RECLASSIFIED',
            'exception_granted_at' => now(),
            'exception_notes' => 'Reclassified to fishing',
        ]);
        ProhibitedVessel::create([
            'mmsi' => '999000005',
            'reason' => 'tanker_policy',
            'original_type' => 'tanker',
            'original_name' => 'TANKER FIVE RECLASSIFIED',
            'exception_granted_at' => now(),
            'exception_notes' => 'Granted research exemption',
        ]);

        // Request page 1 with limit=2
        $resPage1 = $this->withHeaders(['X-AIS-Token' => 'test-valid-token-xyz'])
            ->getJson('/api/telemetry/ais/prohibited-mmsis?limit=2');

        $resPage1->assertStatus(200);
        $json1 = $resPage1->json();

        $this->assertEquals(3, $json1['total_count']); // 3 active blocked vessels
        $this->assertEquals(2, $json1['returned_count']);
        $this->assertFalse($json1['is_last_page']);
        $this->assertNotNull($json1['next_cursor']);

        // Check actions on page 1
        $this->assertEquals('block', $json1['data'][0]['action']);
        $this->assertEquals('999000001', $json1['data'][0]['mmsi']);

        // Request page 2 with cursor from page 1
        $cursor1 = $json1['next_cursor'];
        $resPage2 = $this->withHeaders(['X-AIS-Token' => 'test-valid-token-xyz'])
            ->getJson('/api/telemetry/ais/prohibited-mmsis?limit=2&cursor=' . $cursor1);

        $resPage2->assertStatus(200);
        $json2 = $resPage2->json();

        $this->assertEquals(2, $json2['returned_count']);
        $this->assertFalse($json2['is_last_page']);
        $this->assertNotNull($json2['next_cursor']);

        // The second item on page 2 (item 4 in DB) is unblocked
        $this->assertEquals('block', $json2['data'][0]['action']); // item 3
        $this->assertEquals('unblock', $json2['data'][1]['action']); // item 4
        $this->assertEquals('999000004', $json2['data'][1]['mmsi']);
        $this->assertNotNull($json2['data'][1]['exception_granted_at']);

        // Request final page (page 3)
        $cursor2 = $json2['next_cursor'];
        $resPage3 = $this->withHeaders(['X-AIS-Token' => 'test-valid-token-xyz'])
            ->getJson('/api/telemetry/ais/prohibited-mmsis?limit=2&cursor=' . $cursor2);

        $resPage3->assertStatus(200);
        $json3 = $resPage3->json();

        $this->assertEquals(1, $json3['returned_count']);
        $this->assertTrue($json3['is_last_page']);
        $this->assertNull($json3['next_cursor']);
        $this->assertEquals('unblock', $json3['data'][0]['action']); // item 5
        $this->assertEquals('999000005', $json3['data'][0]['mmsi']);
    }
}

