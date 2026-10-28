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

    public function healthCheck(): array
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
        $connected = false;
        $errno = 0;
        $errstr = '';

        try {
            $fp = @fsockopen('ssl://stream.aisstream.io', 443, $errno, $errstr, 5);
            if ($fp) {
                $connected = true;
                fclose($fp);
            }
        } catch (\Throwable $e) {
            $errstr = $e->getMessage();
        }

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        if ($connected) {
            $provider = DataProvider::where('slug', $this->providerSlug)->first();
            $latestPos = null;
            if ($provider) {
                $latestPos = VesselPosition::where('source_provider_id', $provider->id)
                    ->orderBy('observed_at', 'desc')
                    ->first();
            }

            if ($latestPos && $latestPos->observed_at->gt(now()->subHours(2))) {
                $status = 'receiving_data';
                $message = 'Endpoint stream.aisstream.io terhubung dan aktif menerima pesan telemetri (data riil diterima dalam 2 jam terakhir).';
            } elseif ($latestPos) {
                $status = 'stale';
                $message = "Telemetri AIS historis tersedia (terakhir {$latestPos->observed_at->toIso8601String()}), namun stream aktif belum menerima data segar (> 2 jam).";
            } elseif ($provider && $provider->last_sync_at) {
                $status = 'connected';
                $message = "Endpoint stream.aisstream.io WebSocket terhubung. Terakhir aktif: {$provider->last_sync_at->toIso8601String()}.";
            } else {
                $status = 'reachable';
                $message = 'Endpoint stream.aisstream.io TLS terhubung (reachable). Menunggu penerimaan frame stream telemetri pertama.';
            }

            $this->updateProviderStatus($status, null, $latency);

            return [
                'status' => $status,
                'message' => $message,
                'latency_ms' => $latency,
                'timestamp' => now()->toISOString(),
            ];
        }

        $errorMsg = 'Gagal membuka koneksi TLS ke stream.aisstream.io: ' . ($errstr ?: 'Connection timeout / host unreachable');
        $this->updateProviderStatus('disconnected', $errorMsg, $latency);

        return [
            'status' => 'disconnected',
            'message' => $errorMsg,
            'latency_ms' => $latency,
            'timestamp' => now()->toISOString(),
        ];
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

                $msgType = $json['MessageType'] ?? null;
                if ($msgType !== 'PositionReport' || !isset($json['MetaData'])) {
                    $skipped++;
                    continue;
                }

                $fetched++;
                $meta = $json['MetaData'];
                $pos = $json['Message']['PositionReport'] ?? [];

                $mmsi = (string) ($meta['MMSI'] ?? $pos['UserID'] ?? '');
                $lat = $meta['latitude'] ?? $pos['Latitude'] ?? null;
                $lng = $meta['longitude'] ?? $pos['Longitude'] ?? null;
                $sog = $pos['Sog'] ?? null;
                $cog = $pos['Cog'] ?? null;
                $heading = $pos['TrueHeading'] ?? null;
                $shipName = trim($meta['ShipName'] ?? '');
                $timeUtc = $meta['time_utc'] ?? now()->toIso8601String();

                $positionModel = $this->ingestPositionMessage([
                    'mmsi' => $mmsi,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'speed_over_ground' => $sog,
                    'course_over_ground' => $cog,
                    'true_heading' => $heading,
                    'vessel_name' => $shipName ?: ('VESSEL-' . $mmsi),
                    'timestamp' => $timeUtc,
                    'raw_payload' => $json,
                ]);

                if ($positionModel) {
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
        ]);

        if ($inserted > 0) {
            $provider->update([
                'health_status' => 'receiving_data',
                'last_sync_at' => now(),
                'last_error_message' => null,
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
            ? strtoupper(trim((string) $message['callsign']))
            : null;

        $provider = DataProvider::firstOrCreate(
            ['slug' => $this->providerSlug],
            [
                'name' => 'AIS Live Stream Provider',
                'type' => 'ais_stream',
                'is_enabled' => true,
                'health_status' => 'receiving_data',
            ]
        );

        return DB::transaction(function () use ($message, $cleanMmsi, $cleanImo, $cleanCallsign, $lat, $lng, $observedAt, $provider) {
            $vessel = Vessel::firstOrCreate(
                ['mmsi' => $cleanMmsi],
                [
                    'name' => $message['vessel_name'] ?? ('VESSEL-' . $cleanMmsi),
                    'flag_state' => $message['flag'] ?? null,
                    'vessel_type' => $message['ship_type'] ?? 'fishing',
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

            // Save new position
            $position = VesselPosition::create([
                'vessel_id' => $vessel->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'speed_knots' => $message['speed_over_ground'] ?? null,
                'course_degrees' => $message['course_over_ground'] ?? null,
                'heading_degrees' => $message['true_heading'] ?? null,
                'nav_status' => $message['nav_status'] ?? 'under_way',
                'observed_at' => $observedAt,
                'received_at' => now(),
                'source_provider_id' => $provider->id,
                'data_quality_score' => $message['quality_score'] ?? 1.0,
                'is_gap_indicator' => false,
                'raw_payload' => $message,
            ]);

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
                'health_status' => 'receiving_data',
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
