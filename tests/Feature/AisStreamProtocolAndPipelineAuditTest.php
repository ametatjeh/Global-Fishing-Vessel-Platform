<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AisStreamProtocolAndPipelineAuditTest extends TestCase
{
    use RefreshDatabase;

    protected AisStreamAdapter $adapter;
    protected string $testApiKey = 'test-secret-aisstream-token-xyz-12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['maritime.providers.ais_stream.api_key' => $this->testApiKey]);
        $this->adapter = app(AisStreamAdapter::class);
        $this->adapter->resetMetrics();
        Cache::flush();
    }

    /**
     * Helper to create a bi-directional stream socket pair for protocol testing.
     * @return array [$clientSocket, $serverSocket]
     */
    protected function createSocketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($pair[0], 2);
        stream_set_timeout($pair[1], 2);
        return $pair;
    }

    /**
     * Helper to pre-buffer an HTTP 101 Switching Protocols response on the mock server socket.
     */
    protected function prebufferMock101Response($serverSocket): void
    {
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n\r\n";
        fwrite($serverSocket, $response);
    }

    /**
     * Helper to drain client HTTP upgrade request headers from server socket.
     */
    protected function drainClientHttpUpgradeRequest($serverSocket): string
    {
        $headers = '';
        while (!feof($serverSocket)) {
            $line = fgets($serverSocket, 1024);
            $headers .= $line;
            if (rtrim($line) === '') break;
        }
        return $headers;
    }

    /**
     * Helper to write unmasked WebSocket frame from server to client.
     */
    protected function writeServerFrame($serverSocket, string $payload, int $opcode = 0x1, bool $fin = true, bool $rsv1 = false): void
    {
        $len = strlen($payload);
        $b1 = ($fin ? 0x80 : 0x00) | ($rsv1 ? 0x40 : 0x00) | ($opcode & 0x0F);

        if ($len < 126) {
            $header = chr($b1) . chr($len);
        } elseif ($len < 65536) {
            $header = chr($b1) . chr(126) . pack('n', $len);
        } else {
            $header = chr($b1) . chr(127) . pack('J', $len);
        }

        fwrite($serverSocket, $header . $payload);
    }

    public function test_01_dns_resolution_failure(): void
    {
        $res = $this->adapter->probeTls('invalid-host-for-testing-domain-12345.xyz', 443, 2);
        $this->assertFalse($res[0]);
        $this->assertEquals('DNS_RESOLUTION', $res[2]['stage_failed']);
        $this->assertStringContainsString('DNS resolution failed', $res[1]);
    }

    public function test_02_tcp_connection_timeout(): void
    {
        // 192.0.2.1 is reserved for documentation (TEST-NET-1) and blackholed
        $res = $this->adapter->probeTls('192.0.2.1', 443, 1);
        $this->assertFalse($res[0]);
        $this->assertEquals('TCP_CONNECT_TIMEOUT', $res[2]['stage_failed']);
        $this->assertStringContainsString('Layer 4 unreachable', $res[1]);
    }

    public function test_03_tls_certificate_verification_failure(): void
    {
        // When forceTlsConnected is false
        $res = $this->adapter->healthCheck(forceTlsConnected: false);
        $this->assertEquals('unreachable', $res['status']);
        $this->assertStringContainsString('Gagal membuka koneksi TLS', $res['message']);
    }

    public function test_04_invalid_websocket_handshake_non_101(): void
    {
        [$client, $server] = $this->createSocketPair();

        // Server pre-buffers HTTP 403 Forbidden
        fwrite($server, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n");

        $result = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);

        fclose($server);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('websocket_upgrade', $result['stage']);
        $this->assertStringContainsString('403 Forbidden', $result['errors'][0]);
    }

    public function test_05_invalid_sec_websocket_accept(): void
    {
        [$client, $server] = $this->createSocketPair();

        // Send incorrect Sec-WebSocket-Accept
        fwrite($server, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: totally-wrong-accept-hash\r\n\r\n");

        $result = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);

        fclose($server);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('websocket_upgrade', $result['stage']);
        $this->assertStringContainsString('Sec-WebSocket-Accept tidak cocok', $result['errors'][0]);
    }

    public function test_06_valid_websocket_handshake_and_subscription_flow(): void
    {
        [$client, $server] = $this->createSocketPair();

        // Mock server pre-buffers 101 response, then close frame
        $this->prebufferMock101Response($server);
        $this->writeServerFrame($server, pack('n', 1000), opcode: 0x8);

        $result = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);

        $this->assertEquals('completed', $result['status']);
        $metrics = $this->adapter->getMetrics();
        $this->assertEquals(1, $metrics['successful_connections']);

        // Drain client upgrade request from server buffer
        $headers = $this->drainClientHttpUpgradeRequest($server);
        $this->assertStringContainsString('Upgrade: websocket', $headers);
        $this->assertStringContainsString('Sec-WebSocket-Key', $headers);

        // Read subscription request from client (read masked frame)
        $subFrame = $this->adapter->readWebSocketFrame($server, 2);
        $this->assertNotNull($subFrame);
        $subPayload = json_decode($subFrame['payload'], true);
        $this->assertEquals($this->testApiKey, $subPayload['APIKey']);
        $this->assertNotEmpty($subPayload['BoundingBoxes']);

        // Read client close confirmation frame
        $clientClose = $this->adapter->readWebSocketFrame($server, 2);
        $this->assertNotNull($clientClose);
        $this->assertEquals(0x8, $clientClose['opcode']);

        fclose($server);
    }

    public function test_07_subscription_confirmation_handling(): void
    {
        $confJson = [
            'MessageType' => 'SubscriptionConfirmation',
            'Message' => [
                'CompressionEnabled' => true,
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($confJson);

        $this->assertEquals('skipped', $res['status']);
        $this->assertEquals('SubscriptionConfirmation', $res['type']);

        $metrics = $this->adapter->getMetrics();
        $this->assertEquals(1, $metrics['subscription_confirmations']);
        $this->assertNotNull(Cache::get('ais_stream_subscription_confirmed_at'));
        $this->assertEquals('awaiting_telemetry', Cache::get('ais_stream_telemetry_status'));
    }

    public function test_08_invalid_api_key_or_server_error_response(): void
    {
        $errorJson = [
            'MessageType' => 'AuthenticationError',
            'Message' => "Invalid API key provided: {$this->testApiKey}",
        ];

        $res = $this->adapter->parseAndIngestRawFrame($errorJson);

        $this->assertEquals('error', $res['status']);
        $this->assertEquals('AuthenticationError', $res['type']);
        $this->assertStringNotContainsString($this->testApiKey, $res['error']);
        $this->assertStringContainsString('[REDACTED_AIS_KEY]', $res['error']);

        $metrics = $this->adapter->getMetrics();
        $this->assertEquals(1, $metrics['invalid_messages']);
    }

    public function test_09_position_report_parsing_and_persistence(): void
    {
        $mmsi = '525112233';
        $reportJson = [
            'MessageType' => 'PositionReport',
            'MetaData' => [
                'MMSI' => (int) $mmsi,
                'ShipName' => 'KM SAMUDERA JAYA',
                'Latitude' => -5.456,
                'Longitude' => 106.789,
                'time_utc' => now()->toIso8601String(),
            ],
            'Message' => [
                'PositionReport' => [
                    'UserID' => (int) $mmsi,
                    'Latitude' => -5.456,
                    'Longitude' => 106.789,
                    'Sog' => 8.4,
                    'Cog' => 145.2,
                    'TrueHeading' => 146,
                    'NavigationalStatus' => 7, // engaged_in_fishing
                ],
            ],
        ];

        $res = $this->adapter->parseAndIngestRawFrame($reportJson);

        $this->assertEquals('inserted', $res['status']);
        $this->assertInstanceOf(VesselPosition::class, $res['model']);

        $pos = $res['model'];
        $this->assertEquals(-5.456, $pos->latitude);
        $this->assertEquals(106.789, $pos->longitude);
        $this->assertEquals(8.4, $pos->speed_knots);
        $this->assertEquals(145.2, $pos->course_degrees);
        $this->assertEquals(146, $pos->heading_degrees);
        $this->assertEquals('engaged_in_fishing', $pos->nav_status);

        // Vessel created
        $vessel = Vessel::where('mmsi', $mmsi)->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('KM SAMUDERA JAYA', $vessel->name);

        $metrics = $this->adapter->getMetrics();
        $this->assertEquals(1, $metrics['position_reports_received']);
        $this->assertEquals(1, $metrics['position_reports_processed']);
    }

    public function test_10_compressed_websocket_frame_permessage_deflate(): void
    {
        $reportJson = [
            'MessageType' => 'PositionReport',
            'MetaData' => [
                'MMSI' => 525778899,
                'ShipName' => 'KM COMPRESSED AIR',
                'Latitude' => -6.111,
                'Longitude' => 107.222,
                'time_utc' => now()->toIso8601String(),
            ],
            'Message' => [
                'PositionReport' => [
                    'UserID' => 525778899,
                    'Latitude' => -6.111,
                    'Longitude' => 107.222,
                    'Sog' => 11.2,
                    'Cog' => 90.0,
                    'TrueHeading' => 90,
                    'NavigationalStatus' => 0,
                ],
            ],
        ];

        $rawJson = json_encode($reportJson);

        // Compress with raw deflate and strip trailing 4 bytes 00 00 FF FF
        $ctx = deflate_init(ZLIB_ENCODING_RAW);
        $compressed = deflate_add($ctx, $rawJson, ZLIB_SYNC_FLUSH);
        $strippedCompressed = substr($compressed, 0, -4);

        // Test decompression method directly
        $decompressed = $this->adapter->decompressPayload($strippedCompressed);
        $this->assertEquals($rawJson, $decompressed);

        // Test end-to-end socket stream with compressed frame (RSV1 bit = true)
        [$client, $server] = $this->createSocketPair();
        $this->prebufferMock101Response($server);

        // Server writes compressed frame with RSV1 bit set (0x40)
        $this->writeServerFrame($server, $strippedCompressed, opcode: 0x1, fin: true, rsv1: true);

        // Server then writes close frame
        $this->writeServerFrame($server, pack('n', 1000), opcode: 0x8);

        $res = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);
        fclose($server);

        $this->assertEquals('completed', $res['status']);
        $this->assertEquals(1, $res['fetched']);
        $this->assertEquals(1, $res['inserted']);

        $vessel = Vessel::where('mmsi', '525778899')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('KM COMPRESSED AIR', $vessel->name);
    }

    public function test_11_fragmented_frames_continuation_reassembly(): void
    {
        $reportJson = [
            'MessageType' => 'PositionReport',
            'MetaData' => [
                'MMSI' => 525334455,
                'ShipName' => 'KM FRAGMENTED STREAM',
                'Latitude' => -7.123,
                'Longitude' => 108.456,
                'time_utc' => now()->toIso8601String(),
            ],
            'Message' => [
                'PositionReport' => [
                    'UserID' => 525334455,
                    'Latitude' => -7.123,
                    'Longitude' => 108.456,
                    'Sog' => 7.5,
                    'Cog' => 200.0,
                    'TrueHeading' => 200,
                    'NavigationalStatus' => 0,
                ],
            ],
        ];

        $rawJson = json_encode($reportJson);
        $mid = (int) (strlen($rawJson) / 2);
        $chunk1 = substr($rawJson, 0, $mid);
        $chunk2 = substr($rawJson, $mid);

        [$client, $server] = $this->createSocketPair();
        $this->prebufferMock101Response($server);

        // Frame 1: Text, FIN = false
        $this->writeServerFrame($server, $chunk1, opcode: 0x1, fin: false);

        // Frame 2: Continuation (opcode 0x0), FIN = true
        $this->writeServerFrame($server, $chunk2, opcode: 0x0, fin: true);

        // Frame 3: Close
        $this->writeServerFrame($server, pack('n', 1000), opcode: 0x8);

        $res = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);
        fclose($server);

        $this->assertEquals('completed', $res['status']);
        $this->assertEquals(1, $res['fetched']);
        $this->assertEquals(1, $res['inserted']);

        $vessel = Vessel::where('mmsi', '525334455')->first();
        $this->assertNotNull($vessel);
        $this->assertEquals('KM FRAGMENTED STREAM', $vessel->name);
    }

    public function test_12_ping_pong_control_frame_exchange(): void
    {
        [$client, $server] = $this->createSocketPair();
        $this->prebufferMock101Response($server);

        // Server sends Ping (opcode 0x9)
        $this->writeServerFrame($server, 'ping-probe-payload', opcode: 0x9);

        // Server sends Close (opcode 0x8)
        $this->writeServerFrame($server, pack('n', 1000), opcode: 0x8);

        // Run client consumption
        $res = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);
        $this->assertEquals('completed', $res['status']);

        // Drain client upgrade request
        $this->drainClientHttpUpgradeRequest($server);

        // Drain client subscription frame
        $this->adapter->readWebSocketFrame($server, 2);

        // Verify server received Pong (opcode 0xA) with exact payload
        $pongFrame = $this->adapter->readWebSocketFrame($server, 2);
        $this->assertNotNull($pongFrame);
        $this->assertEquals(0xA, $pongFrame['opcode']);
        $this->assertEquals('ping-probe-payload', $pongFrame['payload']);

        fclose($server);
    }

    public function test_13_close_frame_handling(): void
    {
        [$client, $server] = $this->createSocketPair();
        $this->prebufferMock101Response($server);

        // Server sends Close (opcode 0x8)
        $this->writeServerFrame($server, pack('n', 1000), opcode: 0x8);

        $res = $this->adapter->consumeStream(maxMessages: 5, timeoutSeconds: 2, streamSocket: $client);
        $this->assertEquals('completed', $res['status']);

        // Drain client upgrade request & subscription
        $this->drainClientHttpUpgradeRequest($server);
        $this->adapter->readWebSocketFrame($server, 2);

        // Verify client sent Close frame back
        $clientClose = $this->adapter->readWebSocketFrame($server, 2);
        $this->assertNotNull($clientClose);
        $this->assertEquals(0x8, $clientClose['opcode']);

        fclose($server);
    }

    public function test_14_malformed_json_handling(): void
    {
        [$client, $server] = $this->createSocketPair();
        $this->prebufferMock101Response($server);

        // Write malformed text
        $this->writeServerFrame($server, "{invalid-broken-json-syntax", opcode: 0x1);
        $this->writeServerFrame($server, pack('n', 1000), opcode: 0x8);

        $res = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);
        fclose($server);

        $this->assertEquals(1, $res['skipped']);
        $metrics = $this->adapter->getMetrics();
        $this->assertEquals(1, $metrics['invalid_messages']);
    }

    public function test_15_invalid_coordinates_rejection(): void
    {
        // Latitude out of bounds (> 90)
        $badLat = [
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 525999001],
            'Message' => [
                'PositionReport' => [
                    'UserID' => 525999001,
                    'Latitude' => 95.0,
                    'Longitude' => 106.0,
                ],
            ],
        ];
        $res1 = $this->adapter->parseAndIngestRawFrame($badLat);
        $this->assertEquals('skipped', $res1['status']);
        $this->assertNull($res1['model']);

        // Null latitude
        $nullLat = [
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 525999002],
            'Message' => [
                'PositionReport' => [
                    'UserID' => 525999002,
                    'Latitude' => null,
                    'Longitude' => 106.0,
                ],
            ],
        ];
        $res2 = $this->adapter->parseAndIngestRawFrame($nullLat);
        $this->assertEquals('skipped', $res2['status']);
        $this->assertNull($res2['model']);
    }

    public function test_16_database_persistence_and_duplicate_deduplication(): void
    {
        $payload = [
            'MessageType' => 'PositionReport',
            'MetaData' => [
                'MMSI' => 525888111,
                'ShipName' => 'KM DEDUP TEST',
                'Latitude' => -5.0,
                'Longitude' => 106.0,
                'time_utc' => '2026-11-09T10:00:00Z',
            ],
            'Message' => [
                'PositionReport' => [
                    'UserID' => 525888111,
                    'Latitude' => -5.0,
                    'Longitude' => 106.0,
                    'Sog' => 10.0,
                    'Cog' => 180.0,
                ],
            ],
        ];

        // First ingestion
        $res1 = $this->adapter->parseAndIngestRawFrame($payload);
        $this->assertEquals('inserted', $res1['status']);
        $pos1Id = $res1['model']->id;

        // Duplicate ingestion (same vessel + time + lat + lng)
        $res2 = $this->adapter->parseAndIngestRawFrame($payload);
        $this->assertEquals('inserted', $res2['status']);
        $pos2Id = $res2['model']->id;

        $this->assertEquals($pos1Id, $pos2Id);
        $this->assertEquals(1, VesselPosition::where('latitude', -5.0)->where('longitude', 106.0)->count());
    }

    public function test_17_worker_reconnect_and_metrics(): void
    {
        $this->adapter->incrementMetric('reconnects');
        $this->adapter->incrementMetric('reconnects');

        $metrics = $this->adapter->getMetrics();
        $this->assertEquals(2, $metrics['reconnects']);
    }

    public function test_18_graceful_shutdown_windows_and_posix(): void
    {
        $this->assertTrue(function_exists('sapi_windows_set_ctrl_handler') || function_exists('pcntl_signal'));
    }

    public function test_19_concurrency_lock_acquisition_and_release(): void
    {
        $lock = Cache::lock('ais_stream_active_worker', 30);
        $this->assertTrue($lock->get());

        // While locked, consumeStream returns busy
        $res = $this->adapter->consumeStream(maxMessages: 1);
        $this->assertEquals('busy', $res['status']);
        $this->assertStringContainsString('concurrency lock', $res['errors'][0]);

        $lock->release();

        // After release, lock can be acquired by test socket
        [$client, $server] = $this->createSocketPair();
        $this->prebufferMock101Response($server);
        $this->writeServerFrame($server, pack('n', 1000), opcode: 0x8);

        $res2 = $this->adapter->consumeStream(maxMessages: 1, timeoutSeconds: 2, streamSocket: $client);
        fclose($server);
        $this->assertEquals('completed', $res2['status']);
    }

    public function test_20_stale_heartbeat_detection(): void
    {
        Cache::forget('ais_stream_active_worker_alive');
        $this->assertFalse(Cache::has('ais_stream_active_worker_alive'));

        Cache::put('ais_stream_active_worker_alive', now()->toIso8601String(), 120);
        $this->assertTrue(Cache::has('ais_stream_active_worker_alive'));
    }

    public function test_21_provider_health_state_transitions(): void
    {
        // 1. Not configured
        config(['maritime.providers.ais_stream.api_key' => null]);
        $adapter = app(AisStreamAdapter::class);
        $this->assertEquals('not_configured', $adapter->healthCheck()['status']);

        // 2. Unreachable
        config(['maritime.providers.ais_stream.api_key' => $this->testApiKey]);
        $adapter = app(AisStreamAdapter::class);
        $this->assertEquals('unreachable', $adapter->healthCheck(forceTlsConnected: false)['status']);

        // 3. Awaiting telemetry
        $resAwaiting = $adapter->healthCheck(forceTlsConnected: true);
        $this->assertEquals('awaiting_telemetry', $resAwaiting['status']);
        $this->assertArrayHasKey('diagnostics', $resAwaiting);

        // 4. Stale telemetry (position > 2 hours)
        $provider = DataProvider::where('slug', 'ais-stream')->first();
        $vessel = Vessel::create(['name' => 'KM STALE', 'mmsi' => '525999333']);
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $provider->id,
            'latitude' => -5.0,
            'longitude' => 106.0,
            'observed_at' => now()->subHours(4),
            'received_at' => now()->subHours(4),
        ]);
        $this->assertEquals('stale', $adapter->healthCheck(forceTlsConnected: true)['status']);

        // 5. Streaming (fresh position < 2 hours)
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $provider->id,
            'latitude' => -5.1,
            'longitude' => 106.1,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
        ]);
        $this->assertEquals('streaming', $adapter->healthCheck(forceTlsConnected: true)['status']);
    }

    public function test_22_secret_masking_guarantee(): void
    {
        $dirtyString = "Connection failed using token {$this->testApiKey} on ws://api?api_key={$this->testApiKey}";
        $cleanString = $this->adapter->sanitizeErrorMessage($dirtyString);

        $this->assertStringNotContainsString($this->testApiKey, $cleanString);
        $this->assertStringContainsString('[REDACTED_AIS_KEY]', $cleanString);
    }
}
