<?php

namespace App\Services\Providers;

use App\Models\DataProvider;
use App\Models\ProviderIngestionRun;
use App\Models\Vessel;
use App\Models\VesselIdentifier;
use App\Models\VesselPosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AisStreamAdapter implements ProviderAdapterInterface
{
    protected ?string $apiKey;
    protected string $providerSlug;

    public function __construct()
    {
        $this->apiKey = config('maritime.providers.ais_stream.api_key');
        $this->providerSlug = config('maritime.providers.ais_stream.provider', 'ais-stream');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function healthCheck(?bool $forceTlsConnected = null): array
    {
        if (!$this->isConfigured()) {
            $this->updateProviderStatus('not_configured', 'AIS Stream API Key belum dikonfigurasi dalam .env (AIS_STREAM_API_KEY).');
            return [
                'status' => 'not_configured',
                'message' => 'AIS Stream API Key belum dikonfigurasi dalam .env (AIS_STREAM_API_KEY). Feed AIS live eksternal belum aktif.',
                'latency_ms' => null,
                'timestamp' => now()->toISOString(),
            ];
        }

        $startTime = microtime(true);
        $errstr = '';

        if ($forceTlsConnected !== null) {
            $connected = $forceTlsConnected;
            if (!$connected) {
                $errstr = 'Connection timeout / host unreachable';
            }
        } else {
            [$connected, $errstr] = $this->probeTls('stream.aisstream.io', 443, 5);
        }

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        if (!$connected) {
            $errorMsg = 'Gagal membuka koneksi TLS ke stream.aisstream.io: ' . ($errstr ?: 'Connection timeout / host unreachable');
            $this->updateProviderStatus('unreachable', $errorMsg, $latency);

            return [
                'status' => 'unreachable',
                'message' => $errorMsg,
                'latency_ms' => $latency,
                'timestamp' => now()->toISOString(),
            ];
        }

        // 1. Check for persistence error (database connectivity or recent failed ingestion run with SQL/PostGIS error)
        $persistenceError = null;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $dbEx) {
            $persistenceError = 'Koneksi database PostgreSQL tidak dapat diakses: ' . $dbEx->getMessage();
        }

        $provider = DataProvider::where('slug', $this->providerSlug)->first();

        if (!$persistenceError && $provider) {
            $lastFailedRun = ProviderIngestionRun::where('data_provider_id', $provider->id)
                ->where('run_type', 'sync_ais')
                ->latest('id')
                ->first();

            if ($lastFailedRun && $lastFailedRun->status === 'failed' && !empty($lastFailedRun->error_message)) {
                $errMsgLower = strtolower($lastFailedRun->error_message);
                if (str_contains($errMsgLower, 'sql') || str_contains($errMsgLower, 'database') || str_contains($errMsgLower, 'geom') || str_contains($errMsgLower, 'postgis') || str_contains($errMsgLower, 'insert into')) {
                    $persistenceError = 'Kegagalan penyimpanan database telemetri: ' . $lastFailedRun->error_message;
                }
            }
        }

        if ($persistenceError) {
            $this->updateProviderStatus('persistence_error', $persistenceError, $latency);
            return [
                'status' => 'persistence_error',
                'message' => $persistenceError,
                'latency_ms' => $latency,
                'timestamp' => now()->toISOString(),
            ];
        }

        // 2. Check telemetry freshness and worker state
        $latestPos = null;
        if ($provider) {
            $latestPos = VesselPosition::where('source_provider_id', $provider->id)
                ->orderBy('observed_at', 'desc')
                ->first();
        }

        $isWorkerAlive = \Illuminate\Support\Facades\Cache::has('ais_stream_active_worker_alive');

        if ($latestPos && $latestPos->observed_at && $latestPos->observed_at->gt(now()->subHours(2))) {
            $status = 'streaming';
            $workerNotice = $isWorkerAlive ? ' [Daemon: aktif]' : ' [Daemon: standby/idle]';
            $message = "Endpoint stream.aisstream.io terhubung dan aktif menerima pesan telemetri (data riil diterima dalam 2 jam terakhir){$workerNotice}.";
        } elseif ($latestPos) {
            $status = 'stale';
            $message = "Telemetri AIS historis tersedia (terakhir {$latestPos->observed_at?->toIso8601String()}), namun stream aktif belum menerima data segar (> 2 jam).";
        } elseif ($isWorkerAlive) {
            $status = 'awaiting_telemetry';
            $message = 'Endpoint stream.aisstream.io TLS terhubung dan worker aktif. Sedang menunggu frame stream telemetri pertama masuk.';
        } else {
            $status = 'awaiting_telemetry';
            $message = 'Endpoint stream.aisstream.io TLS terhubung (reachable). Menunggu worker daemon (ais:listen) aktif dan menerima frame telemetri pertama.';
        }

        $this->updateProviderStatus($status, null, $latency);

        return [
            'status' => $status,
            'message' => $message,
            'latency_ms' => $latency,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Probe TLS connectivity to the external endpoint.
     */
    protected function probeTls(string $host = 'stream.aisstream.io', int $port = 443, int $timeout = 5): array
    {
        $errno = 0;
        $errstr = '';
        $connected = false;

        try {
            $fp = @fsockopen("ssl://{$host}", $port, $errno, $errstr, $timeout);
            if ($fp) {
                $connected = true;
                fclose($fp);
            }
        } catch (\Throwable $e) {
            $errstr = $e->getMessage();
        }

        return [$connected, $errstr];
    }

    protected function updateProviderStatus(string $status, ?string $error = null, ?float $latency = null): void
    {
        DataProvider::updateOrCreate(
            ['slug' => $this->providerSlug],
            [
                'name' => 'AIS Live Stream Telemetry',
                'type' => 'ais_stream',
                'api_url' => 'wss://stream.aisstream.io/v0/stream',
                'auth_type' => 'bearer_token',
                'is_enabled' => $this->isConfigured(),
                'health_status' => $status,
                'last_health_check_at' => now(),
                'last_error_message' => $error,
                'license_type' => 'Proprietary Live Feed',
                'commercial_use_allowed' => false,
                'rate_limit_info' => $latency ? ['latency_ms' => $latency] : null,
            ]
        );
    }

    /**
     * Consume real-time WebSocket stream from aisstream.io
     *
     * @param int $maxMessages Maximum frames to collect before gracefully closing
     * @param int $timeoutSeconds Socket read timeout in seconds
     * @return array
     */
    public function consumeStream(int $maxMessages = 10, int $timeoutSeconds = 15): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'not_configured',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['AIS provider key is not configured in .env.'],
            ];
        }

        // Prevent collision if Node.js background worker is actively streaming
        $nodeWorkerAlive = \Illuminate\Support\Facades\Cache::get('ais_stream_node_worker_alive');
        if ($nodeWorkerAlive) {
            return [
                'status' => 'busy',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Koneksi stream AIS sedang ditangani oleh background Node.js worker (ais/worker.mjs). Penggunaan ganda dibatalkan.'],
            ];
        }

        // Prevent uncontrolled duplicate stream workers from flooding provider
        $lockTtl = ($maxMessages <= 0) ? 300 : max(60, $timeoutSeconds * 2);
        $lock = \Illuminate\Support\Facades\Cache::lock('ais_stream_active_worker', $lockTtl);
        if (!$lock->get()) {
            return [
                'status' => 'busy',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Koneksi stream AIS sedang digunakan oleh proses lain. Duplikasi dicegah oleh concurrency lock.'],
            ];
        }

        $provider = DataProvider::firstOrCreate(
            ['slug' => $this->providerSlug],
            [
                'name' => 'AIS Live Stream Telemetry',
                'type' => 'ais_stream',
                'api_url' => 'wss://stream.aisstream.io/v0/stream',
                'auth_type' => 'bearer_token',
                'is_enabled' => true,
                'health_status' => 'connected',
            ]
        );

        $run = ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_ais',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $host = 'stream.aisstream.io';
        $port = 443;
        $path = '/v0/stream';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            $lock->release();
            $errorMsg = "Gagal membuka soket TLS ke $host: $errstr";
            $run->update(['status' => 'failed', 'error_message' => $errorMsg, 'completed_at' => now()]);
            return [
                'status' => 'error',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [$errorMsg],
            ];
        }

        // Send RFC 6455 WebSocket handshake request
        $secKey = base64_encode(random_bytes(16));
        $handshake = [
            "GET $path HTTP/1.1",
            "Host: $host",
            "Upgrade: websocket",
            "Connection: Upgrade",
            "Sec-WebSocket-Key: $secKey",
            "Sec-WebSocket-Version: 13",
            "User-Agent: GFVIP-Platform/1.0",
        ];
        fwrite($fp, implode("\r\n", $handshake) . "\r\n\r\n");

        $respHeaders = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            $respHeaders .= $line;
            if (rtrim($line) === '') break;
        }

        if (!str_contains($respHeaders, '101 Switching Protocols')) {
            fclose($fp);
            $lock->release();
            $errorMsg = 'WebSocket handshake ditolak oleh stream.aisstream.io (bukan 101 Switching Protocols)';
            $run->update(['status' => 'failed', 'error_message' => $errorMsg, 'completed_at' => now()]);
            return [
                'status' => 'error',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [$errorMsg],
            ];
        }

        // Build configurable subscription payload
        $subConfig = [
            'APIKey' => $this->apiKey,
            'BoundingBoxes' => config('maritime.providers.ais_stream.bounding_boxes', [[[-90.0, -180.0], [90.0, 180.0]]]),
            'FilterMessageTypes' => config('maritime.providers.ais_stream.filter_message_types', ['PositionReport']),
        ];

        $filterMmsi = config('maritime.providers.ais_stream.filter_mmsi');
        if (!empty($filterMmsi) && is_array($filterMmsi)) {
            $subConfig['FiltersShipMMSI'] = array_values(array_filter(array_map('trim', $filterMmsi)));
        }

        $subPayload = json_encode($subConfig);

        $len = strlen($subPayload);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $subPayload[$i] ^ $mask[$i % 4];
        }

        if ($len < 126) {
            $header = chr(0x81) . chr(0x80 | $len) . $mask;
        } elseif ($len < 65536) {
            $header = chr(0x81) . chr(0x80 | 126) . pack('n', $len) . $mask;
        } else {
            $header = chr(0x81) . chr(0x80 | 127) . pack('J', $len) . $mask;
        }

        fwrite($fp, $header . $masked);

        stream_set_timeout($fp, $timeoutSeconds);

        $fetched = 0;
        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $lastHeartbeat = time();

        try {
            while ($maxMessages <= 0 || $fetched < $maxMessages) {
                // Heartbeat / lock extension every 30 seconds for continuous runs
                if (time() - $lastHeartbeat >= 30) {
                    $lastHeartbeat = time();
                    // Keep cache lock fresh
                    \Illuminate\Support\Facades\Cache::put('ais_stream_active_worker_alive', now()->toIso8601String(), 120);
                }

                $h1 = fread($fp, 1);
                if ($h1 === '' || $h1 === false) break;
                $b1 = ord($h1);
                $fin = ($b1 & 0x80) !== 0;
                $opcode = $b1 & 0x0F;

                $h2 = fread($fp, 1);
                if ($h2 === '' || $h2 === false) break;
                $b2 = ord($h2);
                $isMasked = ($b2 & 0x80) !== 0;
                $payloadLen = $b2 & 0x7F;

                if ($payloadLen === 126) {
                    $ext = fread($fp, 2);
                    if (strlen($ext) < 2) break;
                    $payloadLen = unpack('n', $ext)[1];
                } elseif ($payloadLen === 127) {
                    $ext = fread($fp, 8);
                    if (strlen($ext) < 8) break;
                    $payloadLen = unpack('J', $ext)[1];
                }

                $maskKey = $isMasked ? fread($fp, 4) : '';
                $payload = '';
                while (strlen($payload) < $payloadLen) {
                    $chunk = fread($fp, min(4096, $payloadLen - strlen($payload)));
                    if ($chunk === '' || $chunk === false) break;
                    $payload .= $chunk;
                }

                if ($isMasked) {
                    for ($i = 0; $i < strlen($payload); $i++) {
                        $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
                    }
                }

                // Handle RFC 6455 Control Frames
                if ($opcode === 0x8) {
                    // Close frame received from server: respond with Close frame and terminate cleanly
                    $closeMask = random_bytes(4);
                    $closeFrame = chr(0x88) . chr(0x80) . $closeMask;
                    @fwrite($fp, $closeFrame);
                    break;
                }

                if ($opcode === 0x9) {
                    // Ping frame received from server: respond with Pong (Opcode 0xA) keeping connection alive
                    $pongMask = random_bytes(4);
                    $pongPayload = $payload;
                    $pLen = strlen($pongPayload);
                    $pongMasked = '';
                    for ($pi = 0; $pi < $pLen; $pi++) {
                        $pongMasked .= $pongPayload[$pi] ^ $pongMask[$pi % 4];
                    }
                    $pongHeader = chr(0x8A) . chr(0x80 | ($pLen & 0x7F)) . $pongMask;
                    @fwrite($fp, $pongHeader . $pongMasked);
                    continue;
                }

                // Opcode 0x1 is Text frame (JSON)
                $json = json_decode($payload, true);
                if (!$json) {
                    $skipped++;
                    continue;
                }

                $fetched++;
                $frameResult = $this->parseAndIngestRawFrame($json);
                if ($frameResult['status'] === 'inserted') {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            Log::error('AIS Stream Reader Exception: ' . $e->getMessage());
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
            $lock->release();
            \Illuminate\Support\Facades\Cache::forget('ais_stream_active_worker_alive');
        }

        $run->update([
            'status' => empty($errors) ? 'completed' : 'partially_failed',
            'items_fetched' => $fetched,
            'items_inserted' => $inserted,
            'items_skipped' => $skipped,
            'completed_at' => now(),
            'metadata_json' => json_encode([
                'messages_received' => $fetched,
                'new_positions_or_updates' => $inserted,
                'skipped_or_duplicates' => $skipped,
                'errors_count' => count($errors),
            ]),
        ]);

        $telemetryMeta = [
            'last_frame_at' => now()->toIso8601String(),
            'session_messages_received' => $fetched,
            'session_inserted' => $inserted,
            'session_skipped' => $skipped,
            'total_positions_in_db' => \Illuminate\Support\Facades\Cache::remember('vessel_positions_count_metric', 300, fn() => VesselPosition::count()),
            'total_vessels_in_db' => \Illuminate\Support\Facades\Cache::remember('vessels_count_metric', 300, fn() => Vessel::count()),
        ];

        if ($inserted > 0) {
            $provider->update([
                'health_status' => 'streaming',
                'last_sync_at' => now(),
                'last_error_message' => null,
                'rate_limit_info' => array_merge($provider->rate_limit_info ?? [], $telemetryMeta),
            ]);
        } else {
            $provider->update([
                'rate_limit_info' => array_merge($provider->rate_limit_info ?? [], $telemetryMeta),
            ]);
        }

        return [
            'status' => empty($errors) ? 'completed' : 'partially_failed',
            'fetched' => $fetched,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function sync(array $options = []): array
    {
        return $this->consumeStream($options['limit'] ?? 10, $options['timeout'] ?? 15);
    }

    /**
     * Parse and ingest a single raw AIS frame or normalized item.
     *
     * @param array $json
     * @return array ['status' => 'inserted'|'skipped', 'type' => string, 'model' => mixed]
     */
    public function parseAndIngestRawFrame(array $json): array
    {
        $msgType = $json['MessageType'] ?? null;

        // Handle SubscriptionConfirmation frame
        if ($msgType === 'SubscriptionConfirmation') {
            Log::info('AISStream SubscriptionConfirmation received: ' . json_encode($json['Message'] ?? []));
            return ['status' => 'skipped', 'type' => 'SubscriptionConfirmation', 'model' => null];
        }

        $meta = $json['MetaData'] ?? [];
        $messageObj = $json['Message'] ?? [];

        // 1. Position Reports (PositionReport, StandardClassBPositionReport, ExtendedClassBPositionReport)
        if (in_array($msgType, ['PositionReport', 'StandardClassBPositionReport', 'ExtendedClassBPositionReport'])) {
            $posData = $messageObj[$msgType] ?? [];
            $mmsi = (string) ($meta['MMSI'] ?? $posData['UserID'] ?? '');
            $lat = $meta['latitude'] ?? $posData['Latitude'] ?? null;
            $lng = $meta['longitude'] ?? $posData['Longitude'] ?? null;
            $sog = $posData['Sog'] ?? null;
            $cog = $posData['Cog'] ?? null;
            $heading = $posData['TrueHeading'] ?? null;
            $shipName = trim(trim((string) ($meta['ShipName'] ?? $posData['Name'] ?? '')), '@');
            $timeUtc = $meta['time_utc'] ?? now()->toIso8601String();
            $shipType = $posData['ShipType'] ?? null;

            $position = $this->ingestPositionMessage([
                'mmsi' => $mmsi,
                'latitude' => $lat,
                'longitude' => $lng,
                'speed_over_ground' => $sog,
                'course_over_ground' => $cog,
                'true_heading' => $heading,
                'vessel_name' => $shipName ?: ('VESSEL-' . $mmsi),
                'ship_type' => $shipType,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
            ]);

            return [
                'status' => $position ? 'inserted' : 'skipped',
                'type' => $msgType,
                'model' => $position,
            ];
        }

        // 2. ShipStaticData (Type 5)
        if ($msgType === 'ShipStaticData') {
            $staticData = $messageObj['ShipStaticData'] ?? [];
            $mmsi = (string) ($meta['MMSI'] ?? $staticData['UserID'] ?? '');
            $shipName = trim(trim((string) ($meta['ShipName'] ?? $staticData['Name'] ?? '')), '@');
            $callSign = trim(trim((string) ($staticData['CallSign'] ?? '')), '@');
            $imo = $staticData['ImoNumber'] ?? null;
            $shipType = $staticData['Type'] ?? null;
            $timeUtc = $meta['time_utc'] ?? now()->toIso8601String();

            $dim = $staticData['Dimension'] ?? [];
            $lengthM = (isset($dim['A'], $dim['B'])) ? (float) ($dim['A'] + $dim['B']) : null;

            $vessel = $this->ingestStaticMessage([
                'mmsi' => $mmsi,
                'vessel_name' => $shipName,
                'callsign' => $callSign,
                'imo' => $imo,
                'ship_type' => $shipType,
                'length_meters' => $lengthM,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
            ]);

            return [
                'status' => $vessel ? 'inserted' : 'skipped',
                'type' => 'ShipStaticData',
                'model' => $vessel,
            ];
        }

        // 3. StaticDataReport (Type 24)
        if ($msgType === 'StaticDataReport') {
            $staticReport = $messageObj['StaticDataReport'] ?? [];
            $mmsi = (string) ($meta['MMSI'] ?? $staticReport['UserID'] ?? '');
            $timeUtc = $meta['time_utc'] ?? now()->toIso8601String();
            $shipName = trim(trim((string) ($meta['ShipName'] ?? '')), '@');
            $callSign = null;
            $shipType = null;
            $lengthM = null;

            if (!empty($staticReport['ReportA']['Name'])) {
                $shipName = trim(trim((string) $staticReport['ReportA']['Name']), '@');
            }
            if (!empty($staticReport['ReportB'])) {
                $rb = $staticReport['ReportB'];
                $callSign = trim(trim((string) ($rb['CallSign'] ?? '')), '@');
                $shipType = $rb['ShipType'] ?? null;
                if (isset($rb['Dimension']['A'], $rb['Dimension']['B'])) {
                    $lengthM = (float) ($rb['Dimension']['A'] + $rb['Dimension']['B']);
                }
            }

            $vessel = $this->ingestStaticMessage([
                'mmsi' => $mmsi,
                'vessel_name' => $shipName,
                'callsign' => $callSign,
                'ship_type' => $shipType,
                'length_meters' => $lengthM,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
            ]);

            return [
                'status' => $vessel ? 'inserted' : 'skipped',
                'type' => 'StaticDataReport',
                'model' => $vessel,
            ];
        }

        // 4. Flat normalized payload fallback (e.g. from custom webhook or API batch)
        if (isset($json['mmsi'])) {
            if (isset($json['latitude'], $json['longitude'])) {
                $pos = $this->ingestPositionMessage($json);
                return [
                    'status' => $pos ? 'inserted' : 'skipped',
                    'type' => 'normalized_position',
                    'model' => $pos,
                ];
            } else {
                $vessel = $this->ingestStaticMessage($json);
                return [
                    'status' => $vessel ? 'inserted' : 'skipped',
                    'type' => 'normalized_static',
                    'model' => $vessel,
                ];
            }
        }

        return ['status' => 'skipped', 'type' => 'unknown', 'model' => null];
    }

    /**
     * Ingest batch of AIS messages from external worker (e.g. Node.js ais/worker.mjs).
     *
     * @param array $messages
     * @return array Ingestion report
     */
    public function ingestBatch(array $messages): array
    {
        $received = count($messages);
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        $provider = DataProvider::firstOrCreate(
            ['slug' => $this->providerSlug],
            [
                'name' => 'AIS Live Stream Provider',
                'type' => 'ais_stream',
                'is_enabled' => true,
                'health_status' => 'streaming',
            ]
        );

        foreach ($messages as $msg) {
            try {
                if (!is_array($msg)) {
                    $skipped++;
                    continue;
                }

                $res = $this->parseAndIngestRawFrame($msg);
                if ($res['status'] === 'inserted') {
                    $inserted++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                Log::warning('AIS batch item ingestion error: ' . $e->getMessage());
            }
        }

        // Mark Node.js background worker as alive for 120 seconds
        \Illuminate\Support\Facades\Cache::put('ais_stream_node_worker_alive', now()->toIso8601String(), 120);

        $telemetryMeta = [
            'last_frame_at' => now()->toIso8601String(),
            'batch_messages_received' => $received,
            'batch_inserted' => $inserted,
            'batch_skipped' => $skipped,
            'total_positions_in_db' => \Illuminate\Support\Facades\Cache::remember('vessel_positions_count_metric', 300, fn() => VesselPosition::count()),
            'total_vessels_in_db' => \Illuminate\Support\Facades\Cache::remember('vessels_count_metric', 300, fn() => Vessel::count()),
        ];

        $provider->update([
            'health_status' => 'streaming',
            'last_sync_at' => now(),
            'last_error_message' => empty($errors) ? null : end($errors),
            'rate_limit_info' => array_merge($provider->rate_limit_info ?? [], $telemetryMeta),
        ]);

        return [
            'status' => 'success',
            'received' => $received,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Map AIS numeric ship type code to normalized GFVIP vessel type.
     * Strictly based on ITU-R M.1371 / IMO ship type tables.
     * Non-fishing vessels or unknown types must NOT default to 'fishing'.
     */
    public static function normalizeVesselType(mixed $type): string
    {
        if (is_string($type)) {
            $t = strtolower(trim($type));
            $t = str_replace(['-', ' '], '_', $t);
            if ($t === 'sar') return 'sar';
            if ($t === 'port_tender') return 'port_tender';
            if ($t === 'anti_pollution') return 'anti_pollution';
            if (in_array($t, [
                'fishing', 'military', 'sailing', 'pleasure', 'pilot',
                'sar', 'tug', 'port_tender', 'anti_pollution', 'patrol',
                'medical', 'passenger', 'cargo', 'tanker', 'carrier',
                'bunker', 'support', 'unknown'
            ])) {
                return $t;
            }
            if (is_numeric($t)) {
                $type = (int) $t;
            } else {
                return 'unknown';
            }
        }

        if (!is_numeric($type)) {
            return 'unknown';
        }

        $code = (int) $type;

        // ITU-R M.1371 / IMO AIS Ship Type Codes:
        // 30: Fishing
        if ($code === 30) {
            return 'fishing';
        }

        // 35: Military operations
        if ($code === 35) {
            return 'military';
        }

        // 36: Sailing vessel
        if ($code === 36) {
            return 'sailing';
        }

        // 37: Pleasure craft
        if ($code === 37) {
            return 'pleasure';
        }

        // 50: Pilot vessel
        if ($code === 50) {
            return 'pilot';
        }

        // 51: SAR (Search and Rescue)
        if ($code === 51) {
            return 'sar';
        }

        // 52: Tug
        if ($code === 52) {
            return 'tug';
        }

        // 53: Port tender
        if ($code === 53) {
            return 'port_tender';
        }

        // 54: Anti-pollution equipment
        if ($code === 54) {
            return 'anti_pollution';
        }

        // 55: Law enforcement / Patrol craft
        if ($code === 55) {
            return 'patrol';
        }

        // 56, 57: Local vessel / unknown
        if ($code === 56 || $code === 57) {
            return 'unknown';
        }

        // 58: Medical transport
        if ($code === 58) {
            return 'medical';
        }

        // 59: Non-combatant ship / unknown
        if ($code === 59) {
            return 'unknown';
        }

        // 60–69: Passenger ships
        if ($code >= 60 && $code <= 69) {
            return 'passenger';
        }

        // 70–79: Cargo ships
        if ($code >= 70 && $code <= 79) {
            return 'cargo';
        }

        // 80–89: Tankers
        if ($code >= 80 && $code <= 89) {
            return 'tanker';
        }

        // Default: Strictly 'unknown' rather than guessing
        return 'unknown';
    }

    /**
     * Parse raw ShipStaticData (Type 5) or StaticDataReport (Type 24) payload into normalized array
     */
    public function ingestStaticMessage(array $message): ?Vessel
    {
        $rawMmsi = (string) ($message['mmsi'] ?? '');
        $cleanMmsi = preg_replace('/\D/', '', $rawMmsi);

        if (strlen($cleanMmsi) < 7 || strlen($cleanMmsi) > 9) {
            return null;
        }

        // SECTION C: Strictly reject static updates for known cargo/tanker prohibited MMSIs
        if (\App\Models\ProhibitedVessel::isProhibited($cleanMmsi)) {
            return null;
        }

        $shipName = !empty($message['vessel_name']) ? trim(trim((string) $message['vessel_name']), '@') : null;
        $callSign = !empty($message['callsign']) ? strtoupper(trim(trim((string) $message['callsign']), '@')) : null;
        $rawImo = !empty($message['imo']) ? preg_replace('/\D/', '', (string) $message['imo']) : null;
        $cleanImo = ($rawImo && strlen($rawImo) === 7) ? $rawImo : null;
        
        $rawShipType = $message['ship_type'] ?? null;
        $vesselType = null;
        $classificationSource = null;
        $aisTypeCode = null;

        if ($rawShipType !== null && $rawShipType !== '') {
            $normalized = self::normalizeVesselType($rawShipType);
            // SECTION C: Tolak penyimpanan kapal dengan klasifikasi cargo, tanker, atau pleasure pada pipeline AIS
            if (in_array($normalized, ['cargo', 'tanker', 'pleasure'])) {
                \App\Models\ProhibitedVessel::firstOrCreate(
                    ['mmsi' => $cleanMmsi],
                    [
                        'reason' => 'cargo_tanker_pleasure_policy',
                        'original_name' => $shipName,
                        'original_type' => $normalized,
                    ]
                );
                return null;
            }
            $vesselType = $normalized;
        }

        $length = isset($message['length_meters']) && is_numeric($message['length_meters']) && (float) $message['length_meters'] > 0
            ? (float) $message['length_meters']
            : null;

        $observedAt = isset($message['timestamp'])
            ? \Carbon\Carbon::parse($message['timestamp'])
            : now();

        $provider = DataProvider::firstOrCreate(
            ['slug' => $this->providerSlug],
            [
                'name' => 'AIS Live Stream Provider',
                'type' => 'ais_stream',
                'is_enabled' => true,
                'health_status' => 'streaming',
            ]
        );

        return DB::transaction(function () use ($cleanMmsi, $shipName, $callSign, $cleanImo, $vesselType, $classificationSource, $aisTypeCode, $length, $observedAt, $provider, $message) {
            $initialMeta = [];
            if ($classificationSource) {
                $initialMeta['classification_source'] = $classificationSource;
                $initialMeta['ais_ship_type_code'] = $aisTypeCode;
                $initialMeta['classified_at'] = now()->toIso8601String();
            }

            $vessel = Vessel::firstOrCreate(
                ['mmsi' => $cleanMmsi],
                [
                    'name' => $shipName ?: ('VESSEL-' . $cleanMmsi),
                    'flag_state' => $message['flag'] ?? null,
                    'vessel_type' => $vesselType ?: 'unknown',
                    'callsign' => $callSign,
                    'imo' => $cleanImo,
                    'length_meters' => $length,
                    'source_provider_id' => $provider->id,
                    'verification_status' => 'provider_reported',
                    'first_seen_at' => $observedAt,
                    'last_seen_at' => $observedAt,
                    'metadata' => !empty($initialMeta) ? $initialMeta : null,
                ]
            );

            $updates = [];
            if ($shipName && (empty($vessel->name) || str_starts_with($vessel->name, 'VESSEL-'))) {
                $updates['name'] = $shipName;
            }
            if ($callSign && empty($vessel->callsign)) {
                $updates['callsign'] = $callSign;
            }
            if ($cleanImo && empty($vessel->imo)) {
                $updates['imo'] = $cleanImo;
            }
            if ($vesselType && $vesselType !== 'unknown') {
                if (empty($vessel->vessel_type) || $vessel->vessel_type === 'unknown') {
                    $updates['vessel_type'] = $vesselType;
                    if ($classificationSource) {
                        $meta = $vessel->metadata ?? [];
                        $meta['classification_source'] = $classificationSource;
                        $meta['ais_ship_type_code'] = $aisTypeCode;
                        $meta['classified_at'] = now()->toIso8601String();
                        $updates['metadata'] = $meta;
                    }
                } elseif ($classificationSource && in_array($vesselType, ['cargo', 'tanker'])) {
                    $updates['vessel_type'] = $vesselType;
                    $meta = $vessel->metadata ?? [];
                    $meta['classification_source'] = $classificationSource;
                    $meta['ais_ship_type_code'] = $aisTypeCode;
                    $meta['classified_at'] = now()->toIso8601String();
                    $updates['metadata'] = $meta;
                }
            }
            if ($length && empty($vessel->length_meters)) {
                $updates['length_meters'] = $length;
            }

            if (!$vessel->last_seen_at || $observedAt->gt($vessel->last_seen_at)) {
                $updates['last_seen_at'] = $observedAt;
            }

            if (!empty($updates)) {
                $vessel->update($updates);
            }

            // Register identifiers
            VesselIdentifier::firstOrCreate(
                ['vessel_id' => $vessel->id, 'identifier_type' => 'mmsi', 'identifier_value' => $cleanMmsi],
                ['is_current' => true, 'source_provider_id' => $provider->id]
            );
            if ($cleanImo) {
                VesselIdentifier::firstOrCreate(
                    ['vessel_id' => $vessel->id, 'identifier_type' => 'imo', 'identifier_value' => $cleanImo],
                    ['is_current' => true, 'source_provider_id' => $provider->id]
                );
            }
            if ($callSign) {
                VesselIdentifier::firstOrCreate(
                    ['vessel_id' => $vessel->id, 'identifier_type' => 'callsign', 'identifier_value' => $callSign],
                    ['is_current' => true, 'source_provider_id' => $provider->id]
                );
            }

            return $vessel;
        });
    }

    /**
     * Ingest genuine AIS message from external REST, stream, or batch payload
     *
     * @param array $message Single AIS position message
     * @return VesselPosition|null
     */
    public function ingestPositionMessage(array $message): ?VesselPosition
    {
        $rawMmsi = (string) ($message['mmsi'] ?? '');
        $cleanMmsi = preg_replace('/\D/', '', $rawMmsi);

        // Standard maritime MMSI is 9 digits, allow 7-9 for special coastal/auxiliary craft
        if (strlen($cleanMmsi) < 7 || strlen($cleanMmsi) > 9) {
            return null;
        }

        // SECTION C: Strictly reject positions from MMSIs known as cargo or tanker
        if (\App\Models\ProhibitedVessel::isProhibited($cleanMmsi)) {
            return null;
        }

        $lat = $message['latitude'] ?? null;
        $lng = $message['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        // Validate coordinate bounds
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        // Validate SOG / COG / Heading sentinel values
        $sog = isset($message['speed_over_ground']) && is_numeric($message['speed_over_ground'])
            ? (float) $message['speed_over_ground']
            : null;
        if ($sog !== null && ($sog < 0 || $sog >= 102.2)) {
            // 102.3 knots is AIS sentinel for "not available"
            $sog = null;
        }

        $cog = isset($message['course_over_ground']) && is_numeric($message['course_over_ground'])
            ? (float) $message['course_over_ground']
            : null;
        if ($cog !== null && ($cog < 0 || $cog >= 360.0)) {
            // 360.0 or 3600 is AIS sentinel for "not available"
            $cog = null;
        }

        $heading = isset($message['true_heading']) && is_numeric($message['true_heading'])
            ? (float) $message['true_heading']
            : null;
        if ($heading !== null && ($heading < 0 || $heading >= 360.0 || (int)$heading === 511)) {
            // 511 is AIS sentinel for "not available"
            $heading = null;
        }

        // Normalize observation timestamp
        $observedAt = isset($message['timestamp'])
            ? \Carbon\Carbon::parse($message['timestamp'])
            : now();

        // Reject or clamp unrealistic future timestamps (> 15 minutes ahead)
        if ($observedAt->gt(now()->addMinutes(15))) {
            $observedAt = now();
        }

        // Normalize IMO and Callsign
        $cleanImo = !empty($message['imo']) ? preg_replace('/\D/', '', (string) $message['imo']) : null;
        if ($cleanImo && strlen($cleanImo) !== 7) {
            $cleanImo = null;
        }

        $cleanCallsign = !empty($message['callsign'])
            ? strtoupper(trim(trim((string) $message['callsign']), '@'))
            : null;

        $shipName = !empty($message['vessel_name'])
            ? trim(trim((string) $message['vessel_name']), '@')
            : ('VESSEL-' . $cleanMmsi);

        $rawShipType = $message['ship_type'] ?? null;
        $vesselType = 'unknown';

        if ($rawShipType !== null && $rawShipType !== '') {
            $normalized = self::normalizeVesselType($rawShipType);
            // SECTION C: Strictly reject storing cargo, tanker, or pleasure vessels on AIS pipeline
            if (in_array($normalized, ['cargo', 'tanker', 'pleasure'])) {
                \App\Models\ProhibitedVessel::firstOrCreate(
                    ['mmsi' => $cleanMmsi],
                    [
                        'reason' => 'cargo_tanker_pleasure_policy',
                        'original_name' => $shipName,
                        'original_type' => $normalized,
                    ]
                );
                return null;
            }
            $vesselType = $normalized;
        }

        $provider = DataProvider::firstOrCreate(
            ['slug' => $this->providerSlug],
            [
                'name' => 'AIS Live Stream Provider',
                'type' => 'ais_stream',
                'is_enabled' => true,
                'health_status' => 'streaming',
            ]
        );

        return DB::transaction(function () use ($message, $cleanMmsi, $cleanImo, $cleanCallsign, $shipName, $vesselType, $lat, $lng, $sog, $cog, $heading, $observedAt, $provider) {
            $vessel = Vessel::firstOrCreate(
                ['mmsi' => $cleanMmsi],
                [
                    'name' => $shipName,
                    'flag_state' => $message['flag'] ?? null,
                    'vessel_type' => $vesselType,
                    'callsign' => $cleanCallsign,
                    'imo' => $cleanImo,
                    'source_provider_id' => $provider->id,
                    'verification_status' => 'provider_reported',
                    'first_seen_at' => $observedAt,
                    'last_seen_at' => $observedAt,
                ]
            );

            // Update attributes if newly available
            $vesselUpdates = [];
            if ($cleanImo && !$vessel->imo) {
                $vesselUpdates['imo'] = $cleanImo;
            }
            if ($cleanCallsign && !$vessel->callsign) {
                $vesselUpdates['callsign'] = $cleanCallsign;
            }
            if ($vesselType !== 'unknown' && ($vessel->vessel_type === 'unknown' || empty($vessel->vessel_type))) {
                $vesselUpdates['vessel_type'] = $vesselType;
            }
            if ($shipName && (empty($vessel->name) || str_starts_with($vessel->name, 'VESSEL-'))) {
                $vesselUpdates['name'] = $shipName;
            }

            // Strictly guard last_seen_at: historical packets must NOT regress latest position
            if (!$vessel->last_seen_at || $observedAt->gt($vessel->last_seen_at)) {
                $vesselUpdates['last_seen_at'] = $observedAt;
            }

            if (!empty($vesselUpdates)) {
                $vessel->update($vesselUpdates);
            }

            // Cross-register identifiers
            VesselIdentifier::firstOrCreate(
                ['vessel_id' => $vessel->id, 'identifier_type' => 'mmsi', 'identifier_value' => $cleanMmsi],
                ['is_current' => true, 'source_provider_id' => $provider->id]
            );
            if ($cleanImo) {
                VesselIdentifier::firstOrCreate(
                    ['vessel_id' => $vessel->id, 'identifier_type' => 'imo', 'identifier_value' => $cleanImo],
                    ['is_current' => true, 'source_provider_id' => $provider->id]
                );
            }
            if ($cleanCallsign) {
                VesselIdentifier::firstOrCreate(
                    ['vessel_id' => $vessel->id, 'identifier_type' => 'callsign', 'identifier_value' => $cleanCallsign],
                    ['is_current' => true, 'source_provider_id' => $provider->id]
                );
            }

            // Check for exact duplicate position packet (vessel_id + observed_at + lat + lng)
            $existingPosition = VesselPosition::where('vessel_id', $vessel->id)
                ->where('observed_at', $observedAt)
                ->where('latitude', $lat)
                ->where('longitude', $lng)
                ->first();

            if ($existingPosition) {
                return $existingPosition;
            }

            try {
                // Save new position
                $position = VesselPosition::create([
                    'vessel_id' => $vessel->id,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'speed_knots' => $sog,
                    'course_degrees' => $cog,
                    'heading_degrees' => $heading,
                    'nav_status' => $message['nav_status'] ?? 'under_way',
                    'observed_at' => $observedAt,
                    'received_at' => now(),
                    'source_provider_id' => $provider->id,
                    'data_quality_score' => $message['quality_score'] ?? 1.0,
                    'is_gap_indicator' => false,
                    'raw_payload' => $message['raw_payload'] ?? $message,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Concurrency duplicate race condition caught by unique index
                if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'Duplicate') || $e->getCode() == 23505) {
                    return VesselPosition::where('vessel_id', $vessel->id)
                        ->where('observed_at', $observedAt)
                        ->where('latitude', $lat)
                        ->where('longitude', $lng)
                        ->first();
                }
                throw $e;
            }

            // Update PostGIS geom if pgsql driver is active
            if (DB::connection()->getDriverName() === 'pgsql') {
                try {
                    DB::statement(
                        "UPDATE vessel_positions SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                        [$lng, $lat, $position->id]
                    );
                } catch (\Throwable $e) {
                    // Ignore if extension is missing
                }
            }

            // Update provider receiving state
            $provider->update([
                'health_status' => 'streaming',
                'last_sync_at' => now(),
                'last_error_message' => null,
            ]);

            return $position;
        });
    }

    public function getMetadata(): array
    {
        return [
            'provider' => 'AIS Real-time Stream Integration',
            'configured' => $this->isConfigured(),
            'supports_websocket' => true,
            'supports_rest_polling' => true,
            'notice' => 'Requires legitimate provider credentials for live global vessel feeds.',
        ];
    }
}
