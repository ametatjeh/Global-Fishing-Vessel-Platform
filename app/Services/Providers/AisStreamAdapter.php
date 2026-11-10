<?php

namespace App\Services\Providers;

use App\Jobs\EnrichVesselGfwJob;
use App\Models\DataProvider;
use App\Models\ProviderIngestionRun;
use App\Models\Vessel;
use App\Models\VesselIdentifier;
use App\Models\VesselPosition;
use Illuminate\Support\Facades\Cache;
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
        $diagnostics = [];

        if ($forceTlsConnected !== null) {
            $connected = $forceTlsConnected;
            if (!$connected) {
                $errstr = 'Connection timeout / host unreachable';
            }
        } else {
            [$connected, $errstr, $diagnostics] = $this->probeTls('stream.aisstream.io', 443, 5);
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
                'diagnostics' => $diagnostics ?: null,
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
            'diagnostics' => array_merge($diagnostics ?: [], [
                'worker_alive' => $isWorkerAlive,
                'telemetry_status' => \Illuminate\Support\Facades\Cache::get('ais_stream_telemetry_status'),
                'last_connected_at' => \Illuminate\Support\Facades\Cache::get('ais_stream_last_connected_at'),
                'subscription_confirmed_at' => \Illuminate\Support\Facades\Cache::get('ais_stream_subscription_confirmed_at'),
                'last_message_at' => \Illuminate\Support\Facades\Cache::get('ais_stream_last_message_at'),
                'last_position_at' => \Illuminate\Support\Facades\Cache::get('ais_stream_last_position_at'),
                'database_latest_observed_at' => $latestPos?->observed_at?->toIso8601String(),
                'counters' => $this->getMetrics(),
            ]),
        ];
    }

    /**
     * Multi-stage probe to diagnose connectivity to the external endpoint:
     * Stage 1: DNS Resolution
     * Stage 2: TCP Transport Connect (Layer 4)
     * Stage 3: TLS Handshake & Certificate Verification (Layer 5/6)
     *
     * @return array [bool $connected, string $errstr, array $stageDiagnostics]
     */
    public function probeTls(string $host = 'stream.aisstream.io', int $port = 443, int $timeout = 5): array
    {
        $diagnostics = [
            'host' => $host,
            'port' => $port,
            'resolved_ip' => null,
            'dns_ok' => false,
            'tcp_ok' => false,
            'tls_ok' => false,
            'stage_failed' => null,
            'error_detail' => null,
        ];

        // Stage 1: DNS resolution
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
            $diagnostics['resolved_ip'] = $ip;
            $diagnostics['dns_ok'] = true;
        } else {
            $ip = @gethostbyname($host);
            if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $diagnostics['stage_failed'] = 'DNS_RESOLUTION';
                $diagnostics['error_detail'] = "DNS resolution failed for host '{$host}'.";
                return [false, $diagnostics['error_detail'], $diagnostics];
            }
            $diagnostics['resolved_ip'] = $ip;
            $diagnostics['dns_ok'] = true;
        }

        // Stage 2: TCP Transport socket connect (Layer 4)
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $t0 = microtime(true);
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        $tcpElapsed = round((microtime(true) - $t0) * 1000, 1);

        if (!$fp) {
            $diagnostics['stage_failed'] = 'TCP_CONNECT_TIMEOUT';
            $cleanErr = $errstr ?: "Connection timed out after {$tcpElapsed}ms";
            $diagnostics['error_detail'] = "TCP connection to {$host} ({$ip}:{$port}) timed out or failed [Error {$errno}: {$cleanErr}]. Layer 4 unreachable.";
            return [false, $diagnostics['error_detail'], $diagnostics];
        }

        $diagnostics['tcp_ok'] = true;

        // Stage 3: TLS Handshake & Certificate Verification (Layer 5/6)
        $tlsResult = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        fclose($fp);

        if ($tlsResult !== true) {
            $diagnostics['stage_failed'] = 'TLS_HANDSHAKE';
            $diagnostics['error_detail'] = "TLS handshake / certificate validation failed against {$host} ({$ip}:{$port}).";
            return [false, $diagnostics['error_detail'], $diagnostics];
        }

        $diagnostics['tls_ok'] = true;
        return [true, '', $diagnostics];
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
     * Sanitize error message to scrub any API tokens or credentials.
     */
    public function sanitizeErrorMessage(string $message): string
    {
        if (!empty($this->apiKey)) {
            $message = str_replace($this->apiKey, '[REDACTED_AIS_KEY]', $message);
        }
        return preg_replace('/(api[-_]?key|token|secret|password)=([^&\s]+)/i', '$1=[REDACTED]', $message);
    }

    /**
     * Increment structured diagnostic metric counter in Cache.
     */
    public function incrementMetric(string $key, int $amount = 1): int
    {
        $cacheKey = "ais_stream_metric_{$key}";
        $val = (int) Cache::get($cacheKey, 0) + $amount;
        Cache::put($cacheKey, $val, 86400 * 7);
        return $val;
    }

    /**
     * Retrieve all current telemetry counters.
     */
    public function getMetrics(): array
    {
        $keys = [
            'total_connection_attempts',
            'successful_connections',
            'subscription_confirmations',
            'messages_received',
            'position_reports_received',
            'position_reports_processed',
            'positions_persisted',
            'invalid_messages',
            'decompression_errors',
            'database_errors',
            'reconnects',
        ];

        $metrics = [];
        foreach ($keys as $k) {
            $metrics[$k] = (int) Cache::get("ais_stream_metric_{$k}", 0);
        }
        return $metrics;
    }

    /**
     * Reset telemetry metrics (useful for isolated tests).
     */
    public function resetMetrics(): void
    {
        $keys = [
            'total_connection_attempts',
            'successful_connections',
            'subscription_confirmations',
            'messages_received',
            'position_reports_received',
            'position_reports_processed',
            'positions_persisted',
            'invalid_messages',
            'decompression_errors',
            'database_errors',
            'reconnects',
        ];
        foreach ($keys as $k) {
            Cache::forget("ais_stream_metric_{$k}");
        }
    }

    /**
     * Read exact N bytes from stream handling network chunking and socket timeouts.
     */
    public function readExactBytes($fp, int $bytes): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $remaining = $bytes - strlen($buffer);
            $chunk = @fread($fp, $remaining);
            if ($chunk === '' || $chunk === false) {
                $meta = @stream_get_meta_data($fp);
                if ($meta && ($meta['timed_out'] || $meta['eof'])) {
                    return null;
                }
                if (@feof($fp)) {
                    return null;
                }
                usleep(5000);
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /**
     * Read a single RFC 6455 WebSocket frame from stream socket.
     */
    public function readWebSocketFrame($fp, int $timeoutSeconds = 15): ?array
    {
        $h1 = $this->readExactBytes($fp, 1);
        if ($h1 === null) return null;
        $b1 = ord($h1);
        $fin = ($b1 & 0x80) !== 0;
        $rsv1 = ($b1 & 0x40) !== 0;
        $opcode = $b1 & 0x0F;

        $h2 = $this->readExactBytes($fp, 1);
        if ($h2 === null) return null;
        $b2 = ord($h2);
        $isMasked = ($b2 & 0x80) !== 0;
        $payloadLen = $b2 & 0x7F;

        if ($payloadLen === 126) {
            $ext = $this->readExactBytes($fp, 2);
            if ($ext === null) return null;
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = $this->readExactBytes($fp, 8);
            if ($ext === null) return null;
            $payloadLen = unpack('J', $ext)[1];
        }

        $maskKey = $isMasked ? $this->readExactBytes($fp, 4) : '';
        if ($isMasked && $maskKey === null) return null;

        $payload = '';
        if ($payloadLen > 0) {
            $payload = $this->readExactBytes($fp, $payloadLen);
            if ($payload === null) return null;
        }

        if ($isMasked && $payloadLen > 0) {
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
            }
        }

        return [
            'fin' => $fin,
            'rsv1' => $rsv1,
            'opcode' => $opcode,
            'payload' => $payload,
        ];
    }

    /**
     * Send an RFC 6455 WebSocket frame with client masking.
     */
    public function sendWebSocketFrame($fp, string $payload, int $opcode = 0x1, bool $masked = true): bool
    {
        $len = strlen($payload);
        $mask = $masked ? random_bytes(4) : '';
        $maskBit = $masked ? 0x80 : 0x00;

        if ($len < 126) {
            $header = chr(0x80 | ($opcode & 0x0F)) . chr($maskBit | $len);
        } elseif ($len < 65536) {
            $header = chr(0x80 | ($opcode & 0x0F)) . chr($maskBit | 126) . pack('n', $len);
        } else {
            $header = chr(0x80 | ($opcode & 0x0F)) . chr($maskBit | 127) . pack('J', $len);
        }

        if ($masked) {
            $maskedPayload = '';
            for ($i = 0; $i < $len; $i++) {
                $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
            }
            $written = @fwrite($fp, $header . $mask . $maskedPayload);
        } else {
            $written = @fwrite($fp, $header . $payload);
        }

        return $written !== false;
    }

    /**
     * Decompress RFC 7692 permessage-deflate compressed WebSocket payload.
     */
    public function decompressPayload(string $compressedPayload): ?string
    {
        try {
            $inflator = @inflate_init(ZLIB_ENCODING_RAW);
            if ($inflator) {
                $result = @inflate_add($inflator, $compressedPayload . "\x00\x00\xff\xff");
                if ($result !== false && strlen($result) > 0) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {}

        try {
            $res = @gzuncompress($compressedPayload);
            if ($res !== false) return $res;
        } catch (\Throwable $e) {}

        return null;
    }

    /**
     * Consume real-time WebSocket stream from aisstream.io
     *
     * @param int $maxMessages Maximum frames to collect before gracefully closing
     * @param int $timeoutSeconds Socket read timeout in seconds
     * @param int $connectTimeout Socket connect timeout in seconds
     * @param resource|null $streamSocket Optional pre-connected stream socket for testing
     * @return array
     */
    public function consumeStream(int $maxMessages = 10, int $timeoutSeconds = 15, int $connectTimeout = 10, $streamSocket = null): array
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
        $nodeWorkerAlive = Cache::get('ais_stream_node_worker_alive');
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
        $lock = Cache::lock('ais_stream_active_worker', $lockTtl);
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
                'health_status' => 'connecting',
            ]
        );

        $provider->update([
            'health_status' => 'connecting',
            'last_health_check_at' => now(),
        ]);
        Cache::put('ais_stream_telemetry_status', 'connecting', 120);
        $this->incrementMetric('total_connection_attempts');

        $run = ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_ais',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $host = 'stream.aisstream.io';
        $port = 443;
        $path = '/v0/stream';
        $fp = null;

        if ($streamSocket !== null) {
            $fp = $streamSocket;
        } else {
            // Stage 1: DNS Resolution
            Log::info("[AISStream][Stage 1/5] Resolving DNS for {$host}...");
            $ip = @gethostbyname($host);
            if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $lock->release();
                Cache::put('ais_stream_telemetry_status', 'error', 120);
                $errMsg = "DNS resolution gagal untuk {$host}. Host tidak dapat ditemukan.";
                Log::error("[AISStream][Stage 1/5 FAILED] {$errMsg}");
                $provider->update(['health_status' => 'unreachable', 'last_error_message' => $errMsg]);
                $run->update(['status' => 'failed', 'error_message' => $errMsg, 'completed_at' => now()]);
                return [
                    'status' => 'error',
                    'stage' => 'dns_resolution',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [$errMsg],
                ];
            }
            Log::info("[AISStream][Stage 1/5 OK] DNS resolved {$host} -> {$ip}");

            // Stage 2: TCP Transport Connect (Layer 4)
            Log::info("[AISStream][Stage 2/5] Membuka soket TCP Layer 4 ke {$host} ({$ip}:{$port}) timeout {$connectTimeout}s...");
            $context = stream_context_create([
                'ssl' => [
                    'peer_name' => $host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $errno = 0;
            $errstr = '';
            $t0 = microtime(true);
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT, $context);
            $tcpElapsed = round((microtime(true) - $t0) * 1000, 1);

            if (!$fp) {
                $lock->release();
                Cache::put('ais_stream_telemetry_status', 'error', 120);
                $cleanErr = $errstr ?: "Connection timed out after {$tcpElapsed}ms";
                $errMsg = "Gagal membuka soket TCP ke {$host} ({$ip}:{$port}) [Error {$errno}: {$cleanErr}]. Layer 4 unreachable.";
                Log::error("[AISStream][Stage 2/5 FAILED] {$errMsg}");
                $provider->update([
                    'health_status' => 'unreachable',
                    'last_error_message' => $errMsg,
                    'rate_limit_info' => array_merge($provider->rate_limit_info ?? [], [
                        'last_connect_failure' => [
                            'stage' => 'tcp_transport',
                            'host' => $host,
                            'ip' => $ip,
                            'errno' => $errno,
                            'error' => $cleanErr,
                            'failed_at' => now()->toIso8601String(),
                        ],
                    ]),
                ]);
                $run->update(['status' => 'failed', 'error_message' => $errMsg, 'completed_at' => now()]);
                return [
                    'status' => 'error',
                    'stage' => 'tcp_transport',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [$errMsg],
                ];
            }
            Log::info("[AISStream][Stage 2/5 OK] Soket TCP Layer 4 berhasil terhubung dalam {$tcpElapsed}ms.");

            // Stage 3: TLS Handshake & Certificate Verification (Layer 5/6)
            Log::info("[AISStream][Stage 3/5] Melakukan TLS handshake dan verifikasi sertifikat SSL...");
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                fclose($fp);
                $lock->release();
                Cache::put('ais_stream_telemetry_status', 'error', 120);
                $errMsg = "Gagal negosiasi TLS atau validasi sertifikat SSL ke {$host} ({$ip}:{$port}).";
                Log::error("[AISStream][Stage 3/5 FAILED] {$errMsg}");
                $provider->update(['health_status' => 'unreachable', 'last_error_message' => $errMsg]);
                $run->update(['status' => 'failed', 'error_message' => $errMsg, 'completed_at' => now()]);
                return [
                    'status' => 'error',
                    'stage' => 'tls_handshake',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [$errMsg],
                ];
            }
            Log::info("[AISStream][Stage 3/5 OK] TLS handshake berhasil terverifikasi secara aman.");
        }

        // Stage 4: WebSocket HTTP 101 Switching Protocols Upgrade
        Log::info("[AISStream][Stage 4/5] Mengirim RFC 6455 WebSocket Upgrade request...");
        $secKey = base64_encode(random_bytes(16));
        $expectedAccept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $handshake = [
            "GET {$path} HTTP/1.1",
            "Host: {$host}",
            "Upgrade: websocket",
            "Connection: Upgrade",
            "Sec-WebSocket-Key: {$secKey}",
            "Sec-WebSocket-Version: 13",
            "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits",
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
            if (is_resource($fp)) fclose($fp);
            $lock->release();
            Cache::put('ais_stream_telemetry_status', 'error', 120);
            $firstHeaderLine = strtok($respHeaders, "\r\n") ?: 'No response headers';
            $errMsg = "WebSocket handshake ditolak oleh {$host} (Bukan 101 Switching Protocols: {$firstHeaderLine}).";
            Log::error("[AISStream][Stage 4/5 FAILED] {$errMsg}");
            $provider->update(['health_status' => 'unreachable', 'last_error_message' => $errMsg]);
            $run->update(['status' => 'failed', 'error_message' => $errMsg, 'completed_at' => now()]);
            return [
                'status' => 'error',
                'stage' => 'websocket_upgrade',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [$errMsg],
            ];
        }

        // Verify Sec-WebSocket-Accept if present in response
        if (preg_match('/Sec-WebSocket-Accept:\s*(\S+)/i', $respHeaders, $acceptMatches)) {
            $actualAccept = trim($acceptMatches[1]);
            if ($actualAccept !== $expectedAccept) {
                if (is_resource($fp)) fclose($fp);
                $lock->release();
                Cache::put('ais_stream_telemetry_status', 'error', 120);
                $errMsg = "WebSocket Sec-WebSocket-Accept tidak cocok (RFC 6455 verification failed).";
                Log::error("[AISStream][Stage 4/5 FAILED] {$errMsg}");
                $provider->update(['health_status' => 'unreachable', 'last_error_message' => $errMsg]);
                $run->update(['status' => 'failed', 'error_message' => $errMsg, 'completed_at' => now()]);
                return [
                    'status' => 'error',
                    'stage' => 'websocket_upgrade',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [$errMsg],
                ];
            }
        }

        $this->incrementMetric('successful_connections');
        Log::info("[AISStream][Stage 4/5 OK] WebSocket berhasil di-upgrade (HTTP 101 Switching Protocols).");

        // Stage 5: Subscription Payload & Telemetry Streaming
        Log::info("[AISStream][Stage 5/5] Mengirim subscription frame dan memulai streaming telemetri...");
        $provider->update([
            'health_status' => 'awaiting_telemetry',
            'last_error_message' => null,
        ]);
        Cache::put('ais_stream_telemetry_status', 'awaiting_telemetry', 120);
        Cache::put('ais_stream_last_connected_at', now()->toIso8601String());
        Cache::put('ais_stream_active_worker_alive', now()->toIso8601String(), 120);

        // Build subscription payload
        $subConfig = [
            'APIKey' => $this->apiKey,
            'BoundingBoxes' => config('maritime.providers.ais_stream.bounding_boxes', [[[-90.0, -180.0], [90.0, 180.0]]]),
            'FilterMessageTypes' => config('maritime.providers.ais_stream.filter_message_types', [
                'PositionReport',
                'ShipStaticData',
                'StandardClassBPositionReport',
                'ExtendedClassBPositionReport',
                'StaticDataReport',
            ]),
        ];

        $filterMmsi = config('maritime.providers.ais_stream.filter_mmsi');
        if (!empty($filterMmsi) && is_array($filterMmsi)) {
            $subConfig['FiltersShipMMSI'] = array_values(array_filter(array_map('trim', $filterMmsi)));
        }

        $subPayload = json_encode($subConfig);
        $this->sendWebSocketFrame($fp, $subPayload, 0x1, true);

        stream_set_timeout($fp, $timeoutSeconds);

        $fetched = 0;
        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $lastHeartbeat = time();

        $fragmentBuffer = '';
        $messageOpcode = null;
        $messageCompressed = false;

        try {
            while ($maxMessages <= 0 || $fetched < $maxMessages) {
                // Heartbeat / lock extension every 30 seconds for continuous runs
                if (time() - $lastHeartbeat >= 30) {
                    $lastHeartbeat = time();
                    Cache::put('ais_stream_active_worker_alive', now()->toIso8601String(), 120);
                    // Send client keepalive ping
                    $this->sendWebSocketFrame($fp, 'keepalive', 0x9, true);
                }

                $frame = $this->readWebSocketFrame($fp, $timeoutSeconds);
                if ($frame === null) {
                    break;
                }

                $opcode = $frame['opcode'];
                $fin = $frame['fin'];
                $rsv1 = $frame['rsv1'];
                $payload = $frame['payload'];

                // Control frame: Close (0x8)
                if ($opcode === 0x8) {
                    $this->sendWebSocketFrame($fp, pack('n', 1000), 0x8, true);
                    Log::info('[AISStream] Server Close frame diterima. Menutup soket secara normal.');
                    break;
                }

                // Control frame: Ping (0x9)
                if ($opcode === 0x9) {
                    $this->sendWebSocketFrame($fp, $payload, 0xA, true); // Pong reply
                    continue;
                }

                // Control frame: Pong (0xA)
                if ($opcode === 0xA) {
                    continue;
                }

                // Data frames: Text (0x1), Binary (0x2), Continuation (0x0)
                if ($opcode === 0x1 || $opcode === 0x2) {
                    $messageOpcode = $opcode;
                    $messageCompressed = $rsv1;
                    $fragmentBuffer = $payload;
                } elseif ($opcode === 0x0) {
                    $fragmentBuffer .= $payload;
                } else {
                    continue;
                }

                if (!$fin) {
                    continue;
                }

                // Assembled message
                $fullPayload = $fragmentBuffer;
                $fragmentBuffer = '';

                if ($messageCompressed) {
                    $decompressed = $this->decompressPayload($fullPayload);
                    if ($decompressed === null) {
                        $skipped++;
                        $this->incrementMetric('decompression_errors');
                        Log::warning('[AISStream] Gagal mendekompresi payload pesan WebSocket (RFC 7692).');
                        continue;
                    }
                    $fullPayload = $decompressed;
                }

                $json = json_decode($fullPayload, true);
                if (!$json) {
                    $skipped++;
                    $this->incrementMetric('invalid_messages');
                    continue;
                }

                $fetched++;
                $this->incrementMetric('messages_received');
                Cache::put('ais_stream_last_message_at', now()->toIso8601String());

                $frameResult = $this->parseAndIngestRawFrame($json);
                if ($frameResult['status'] === 'inserted') {
                    $inserted++;
                    $this->incrementMetric('positions_persisted');
                    Cache::put('ais_stream_last_position_at', now()->toIso8601String());
                    Cache::put('ais_stream_telemetry_status', 'streaming', 120);
                    $provider->update(['health_status' => 'streaming']);
                } elseif ($frameResult['status'] === 'error') {
                    $errors[] = $frameResult['error'] ?? 'Frame error';
                } else {
                    $skipped++;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $this->sanitizeErrorMessage($e->getMessage());
            Log::error('AIS Stream Reader Exception: ' . $this->sanitizeErrorMessage($e->getMessage()));
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
            $lock->release();
            Cache::forget('ais_stream_active_worker_alive');
            Cache::put('ais_stream_telemetry_status', 'disconnected', 120);
        }

        $allMetrics = $this->getMetrics();
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
                'counters' => $allMetrics,
            ]),
        ]);

        $telemetryMeta = [
            'last_frame_at' => now()->toIso8601String(),
            'session_messages_received' => $fetched,
            'session_inserted' => $inserted,
            'session_skipped' => $skipped,
            'counters' => $allMetrics,
            'total_positions_in_db' => Cache::remember('vessel_positions_count_metric', 300, fn() => VesselPosition::count()),
            'total_vessels_in_db' => Cache::remember('vessels_count_metric', 300, fn() => Vessel::count()),
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
        return $this->consumeStream(
            $options['limit'] ?? 10,
            $options['timeout'] ?? 15,
            $options['connect_timeout'] ?? 10
        );
    }

    /**
     * Parse and ingest a single raw AIS frame or normalized item.
     *
     * @param array $json
     * @return array ['status' => 'inserted'|'skipped'|'error', 'type' => string, 'model' => mixed, 'error' => ?string]
     */
    public function parseAndIngestRawFrame(array $json): array
    {
        $msgType = $json['MessageType'] ?? null;
        $meta = $json['MetaData'] ?? [];
        $messageObj = $json['Message'] ?? [];

        // Handle SubscriptionConfirmation frame
        if ($msgType === 'SubscriptionConfirmation') {
            $compEnabled = (bool) ($messageObj['CompressionEnabled'] ?? false);
            Log::info("[AISStream] SubscriptionConfirmation diterima (CompressionEnabled: " . ($compEnabled ? 'true' : 'false') . ").");
            $this->incrementMetric('subscription_confirmations');
            Cache::put('ais_stream_subscription_confirmed_at', now()->toIso8601String(), 86400);
            Cache::put('ais_stream_telemetry_status', 'awaiting_telemetry', 120);

            $provider = DataProvider::where('slug', $this->providerSlug)->first();
            if ($provider) {
                $pMeta = $provider->rate_limit_info ?? [];
                $pMeta['compression_enabled'] = $compEnabled;
                $pMeta['last_subscription_confirmed_at'] = now()->toIso8601String();
                $provider->update([
                    'health_status' => 'awaiting_telemetry',
                    'rate_limit_info' => $pMeta,
                ]);
            }

            return ['status' => 'skipped', 'type' => 'SubscriptionConfirmation', 'model' => null];
        }

        // Handle server error frames
        if (in_array($msgType, ['Error', 'AuthenticationError', 'SubscriptionError'])) {
            $errorDetail = $messageObj['Error'] ?? $messageObj['Message'] ?? $json['Message'] ?? 'Subscription error';
            $safeError = is_string($errorDetail) ? $this->sanitizeErrorMessage($errorDetail) : 'AISStream error received';
            Log::error("[AISStream] {$msgType} frame received from server: {$safeError}");
            $this->incrementMetric('invalid_messages');
            return ['status' => 'error', 'type' => $msgType, 'model' => null, 'error' => $safeError];
        }

        // 1. Standard Position Reports (PositionReport, StandardClassBPositionReport)
        if (in_array($msgType, ['PositionReport', 'StandardClassBPositionReport'])) {
            $posData = $messageObj[$msgType] ?? [];
            $mmsi = (string) ($meta['MMSI'] ?? $posData['UserID'] ?? '');
            $lat = $meta['Latitude'] ?? $meta['latitude'] ?? $posData['Latitude'] ?? $posData['latitude'] ?? null;
            $lng = $meta['Longitude'] ?? $meta['longitude'] ?? $posData['Longitude'] ?? $posData['longitude'] ?? null;
            $sog = $posData['Sog'] ?? null;
            $cog = $posData['Cog'] ?? null;
            $heading = $posData['TrueHeading'] ?? null;
            $shipName = trim(trim((string) ($meta['ShipName'] ?? $posData['Name'] ?? '')), '@');
            $timeUtc = $meta['time_utc'] ?? now()->toIso8601String();
            $shipType = $posData['ShipType'] ?? null;

            $rawNavStatus = $posData['NavigationalStatus'] ?? $meta['NavigationalStatus'] ?? null;
            $navStatus = 'under_way';
            if ($rawNavStatus !== null) {
                $navStatus = match ((int) $rawNavStatus) {
                    0 => 'under_way_engine',
                    1 => 'at_anchor',
                    2 => 'not_under_command',
                    3 => 'restricted_manoeuvrability',
                    4 => 'constrained_by_draught',
                    5 => 'moored',
                    6 => 'aground',
                    7 => 'engaged_in_fishing',
                    8 => 'under_way_sailing',
                    14 => 'ais_sart',
                    default => 'under_way',
                };
            }

            $this->incrementMetric('position_reports_received');

            $position = $this->ingestPositionMessage([
                'mmsi' => $mmsi,
                'latitude' => $lat,
                'longitude' => $lng,
                'speed_over_ground' => $sog,
                'course_over_ground' => $cog,
                'true_heading' => $heading,
                'nav_status' => $navStatus,
                'vessel_name' => $shipName ?: ('VESSEL-' . $mmsi),
                'ship_type' => $shipType,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
                'allow_classification_review' => true,
            ]);

            if ($position) {
                $this->incrementMetric('position_reports_processed');
            }

            return [
                'status' => $position ? 'inserted' : 'skipped',
                'type' => $msgType,
                'model' => $position,
            ];
        }

        // 1b. Extended Class B Position Report (Type 19 - dual static metadata + position report)
        if ($msgType === 'ExtendedClassBPositionReport') {
            $report = $messageObj['ExtendedClassBPositionReport'] ?? [];
            $mmsi = (string) ($meta['MMSI'] ?? $report['UserID'] ?? '');
            $lat = $meta['Latitude'] ?? $meta['latitude'] ?? $report['Latitude'] ?? $report['latitude'] ?? null;
            $lng = $meta['Longitude'] ?? $meta['longitude'] ?? $report['Longitude'] ?? $report['longitude'] ?? null;
            $sog = $report['Sog'] ?? null;
            $cog = $report['Cog'] ?? null;
            $heading = $report['TrueHeading'] ?? null;
            $shipName = trim(trim((string) ($meta['ShipName'] ?? $report['Name'] ?? '')), '@');
            $timeUtc = $meta['time_utc'] ?? now()->toIso8601String();
            $shipType = $report['Type'] ?? $report['ShipType'] ?? $meta['ShipType'] ?? null;

            $dim = $report['Dimension'] ?? [];
            $lengthM = (isset($dim['A'], $dim['B']) && ($dim['A'] + $dim['B']) > 0) ? (float) ($dim['A'] + $dim['B']) : null;
            $beamM = (isset($dim['C'], $dim['D']) && ($dim['C'] + $dim['D']) > 0) ? (float) ($dim['C'] + $dim['D']) : null;

            $this->incrementMetric('position_reports_received');

            // STEP 1: Always ingest static metadata first (preserved even if position is rejected or invalid)
            $staticResult = $this->ingestStaticMessage([
                'mmsi' => $mmsi,
                'vessel_name' => $shipName,
                'ship_type' => $shipType,
                'length_meters' => $lengthM,
                'beam_meters' => $beamM,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
                'allow_classification_review' => true,
            ]);

            // STEP 2: Ingest position message separately (subject to coordinate bounds & prohibited policy checks)
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
                'allow_classification_review' => true,
            ]);

            if ($position) {
                $this->incrementMetric('position_reports_processed');
            }

            return [
                'status' => ($position || $staticResult) ? 'inserted' : 'skipped',
                'type' => 'ExtendedClassBPositionReport',
                'model' => $position ?: $staticResult,
            ];
        }

        // 1c. Long Range AIS Broadcast Message (Type 27 - low-resolution position only, strictly NO ShipType)
        if (in_array($msgType, ['LongRangeBroadcastMessage', 'LongRangeAISBroadcast', 'Type27'])) {
            $lr = $messageObj[$msgType] ?? [];
            $mmsi = (string) ($meta['MMSI'] ?? $lr['UserID'] ?? '');
            $lat = $meta['latitude'] ?? $lr['Latitude'] ?? null;
            $lng = $meta['longitude'] ?? $lr['Longitude'] ?? null;
            $sog = $lr['Sog'] ?? null;
            $cog = $lr['Cog'] ?? null;
            $timeUtc = $meta['time_utc'] ?? now()->toIso8601String();

            // Type 27 strictly does not carry ShipType or Name; NEVER assume or fallback to fishing
            $position = $this->ingestPositionMessage([
                'mmsi' => $mmsi,
                'latitude' => $lat,
                'longitude' => $lng,
                'speed_over_ground' => $sog,
                'course_over_ground' => $cog,
                'true_heading' => null,
                'vessel_name' => null,
                'ship_type' => null,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
                'allow_classification_review' => true,
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
            $lengthM = (isset($dim['A'], $dim['B']) && ($dim['A'] + $dim['B']) > 0) ? (float) ($dim['A'] + $dim['B']) : null;
            $beamM = (isset($dim['C'], $dim['D']) && ($dim['C'] + $dim['D']) > 0) ? (float) ($dim['C'] + $dim['D']) : null;

            $vessel = $this->ingestStaticMessage([
                'mmsi' => $mmsi,
                'vessel_name' => $shipName,
                'callsign' => $callSign,
                'imo' => $imo,
                'ship_type' => $shipType,
                'length_meters' => $lengthM,
                'beam_meters' => $beamM,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
                'allow_classification_review' => true,
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
            $beamM = null;

            if (!empty($staticReport['ReportA']['Name'])) {
                $shipName = trim(trim((string) $staticReport['ReportA']['Name']), '@');
            }
            if (!empty($staticReport['ReportB'])) {
                $rb = $staticReport['ReportB'];
                $callSign = trim(trim((string) ($rb['CallSign'] ?? '')), '@');
                $shipType = $rb['ShipType'] ?? $rb['Type'] ?? null;
                $dim = $rb['Dimension'] ?? [];
                if (isset($dim['A'], $dim['B']) && ($dim['A'] + $dim['B']) > 0) {
                    $lengthM = (float) ($dim['A'] + $dim['B']);
                }
                if (isset($dim['C'], $dim['D']) && ($dim['C'] + $dim['D']) > 0) {
                    $beamM = (float) ($dim['C'] + $dim['D']);
                }
            }

            $vessel = $this->ingestStaticMessage([
                'mmsi' => $mmsi,
                'vessel_name' => $shipName,
                'callsign' => $callSign,
                'ship_type' => $shipType,
                'length_meters' => $lengthM,
                'beam_meters' => $beamM,
                'timestamp' => $timeUtc,
                'raw_payload' => $json,
                'allow_classification_review' => true,
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
            if ($t === 'sar' || $t === 'search_and_rescue') return 'sar';
            if ($t === 'port_tender') return 'port_tender';
            if ($t === 'anti_pollution') return 'anti_pollution';
            if ($t === 'law_enforcement' || $t === 'patrol') return 'patrol';
            if ($t === 'high_speed_craft' || $t === 'hsc') return 'high_speed_craft';
            if ($t === 'non_combatant') return 'non_combatant';
            if ($t === 'medical' || $t === 'medical_transport') return 'medical';
            if ($t === 'pleasure_craft') return 'pleasure';
            if ($t === 'not_available') return 'not_available';
            if ($t === 'unmapped') return 'unmapped';

            if (in_array($t, [
                'fishing',
                'towing',
                'dredging',
                'diving',
                'military',
                'sailing',
                'pleasure',
                'high_speed_craft',
                'pilot',
                'sar',
                'tug',
                'port_tender',
                'anti_pollution',
                'law_enforcement',
                'patrol',
                'medical',
                'passenger',
                'cargo',
                'tanker',
                'non_combatant',
                'other',
                'not_available',
                'unmapped',
                'carrier',
                'support',
                'research',
                'unknown'
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

        if ($code === 0 || $code === 99) {
            return 'unknown';
        }

        // ITU-R M.1371 / IMO AIS Ship Type Codes:
        // 20-29: High Speed Craft (WIG / HSC)
        if ($code >= 20 && $code <= 29) {
            return 'high_speed_craft';
        }

        // 30: Fishing
        if ($code === 30) {
            return 'fishing';
        }

        // 31–32: Towing
        if ($code === 31 || $code === 32) {
            return 'towing';
        }

        // 33: Dredging
        if ($code === 33) {
            return 'dredging';
        }

        // 34: Diving
        if ($code === 34) {
            return 'diving';
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

        // 40–49: High Speed Craft (HSC)
        if ($code >= 40 && $code <= 49) {
            return 'high_speed_craft';
        }

        // 50: Pilot vessel
        if ($code === 50) {
            return 'pilot';
        }

        // 51: Search and Rescue (SAR)
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

        // 55: Law enforcement / Patrol
        if ($code === 55) {
            return 'patrol';
        }

        // 56, 57: Spare / Reserved / Unknown
        if ($code === 56 || $code === 57) {
            return 'unknown';
        }

        // 58: Medical transport
        if ($code === 58) {
            return 'medical';
        }

        // 59: Non-combatant ship / States not parties to armed conflict
        if ($code === 59) {
            return 'non_combatant';
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

        // 90–98: Other types of ship
        if ($code >= 90 && $code <= 98) {
            return 'other';
        }

        // Unrecognized numeric code
        return 'unmapped';
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

        $existingVessel = Vessel::where('mmsi', $cleanMmsi)->first();

        $shipName = !empty($message['vessel_name']) ? trim(trim((string) $message['vessel_name']), '@') : null;
        $callSign = !empty($message['callsign']) ? strtoupper(trim(trim((string) $message['callsign']), '@')) : null;
        $rawImo = !empty($message['imo']) ? preg_replace('/\D/', '', (string) $message['imo']) : null;
        $cleanImo = ($rawImo && strlen($rawImo) === 7) ? $rawImo : null;

        $rawShipType = $message['ship_type'] ?? null;
        $vesselType = null;
        $classificationSource = null;
        $aisTypeCode = null;

        if ($rawShipType !== null && $rawShipType !== '') {
            $aisTypeCode = is_numeric($rawShipType) ? (int) $rawShipType : null;
            $vesselType = self::normalizeVesselType($rawShipType);
            $classificationSource = 'AIS_STATIC_MESSAGE_SHIPTYPE';
        }

        $length = isset($message['length_meters']) && is_numeric($message['length_meters']) && (float) $message['length_meters'] > 0
            ? (float) $message['length_meters']
            : null;

        $beam = isset($message['beam_meters']) && is_numeric($message['beam_meters']) && (float) $message['beam_meters'] > 0
            ? (float) $message['beam_meters']
            : null;

        $observedAt = isset($message['observed_at'])
            ? \Carbon\Carbon::parse($message['observed_at'])
            : (isset($message['timestamp']) ? \Carbon\Carbon::parse($message['timestamp']) : now());

        $provider = DataProvider::firstOrCreate(
            ['slug' => $this->providerSlug],
            [
                'name' => 'AIS Live Stream Provider',
                'type' => 'ais_stream',
                'is_enabled' => true,
                'health_status' => 'streaming',
            ]
        );

        return DB::transaction(function () use ($cleanMmsi, $shipName, $callSign, $cleanImo, $vesselType, $classificationSource, $aisTypeCode, $length, $beam, $observedAt, $provider, $message) {
            $initialMeta = [];
            if ($classificationSource) {
                $initialMeta['classification_source'] = $classificationSource;
                $initialMeta['ais_ship_type_code'] = $aisTypeCode;
                $initialMeta['classified_at'] = $observedAt->toIso8601String();
            }
            $initialMeta['static_data_received_at'] = $observedAt->toIso8601String();

            $initialVesselType = $vesselType ?: 'unknown';
            if ($aisTypeCode === 0) {
                $initialVesselType = 'not_available';
            }

            $vessel = Vessel::firstOrCreate(
                ['mmsi' => $cleanMmsi],
                [
                    'name' => $shipName ?: ('VESSEL-' . $cleanMmsi),
                    'flag_state' => $message['flag'] ?? null,
                    'vessel_type' => $initialVesselType,
                    'callsign' => $callSign,
                    'imo' => $cleanImo,
                    'length_meters' => $length,
                    'beam_meters' => $beam,
                    'source_provider_id' => $provider->id,
                    'verification_status' => 'provider_reported',
                    'classification_source' => $classificationSource,
                    'ais_ship_type_code' => $aisTypeCode,
                    'classified_at' => $classificationSource ? $observedAt : null,
                    'static_data_received_at' => $observedAt,
                    'first_seen_at' => $observedAt,
                    'last_seen_at' => $observedAt,
                    'metadata' => !empty($initialMeta) ? $initialMeta : null,
                ]
            );

            $updates = [
                'static_data_received_at' => $observedAt,
            ];

            // Only update identity if valid and not empty (empty data must NOT overwrite valid data)
            if ($shipName && (empty($vessel->name) || str_starts_with($vessel->name, 'VESSEL-'))) {
                $updates['name'] = $shipName;
            }
            if ($callSign && empty($vessel->callsign)) {
                $updates['callsign'] = $callSign;
            }
            if ($cleanImo && empty($vessel->imo)) {
                $updates['imo'] = $cleanImo;
            }
            if ($length && empty($vessel->length_meters)) {
                $updates['length_meters'] = $length;
            }
            if ($beam && empty($vessel->beam_meters)) {
                $updates['beam_meters'] = $beam;
            }

            // ShipType 0 must NOT overwrite valid existing vessel_type or existing valid ais code
            if ($aisTypeCode === 0) {
                if (empty($vessel->vessel_type) || $vessel->vessel_type === 'unknown') {
                    $updates['vessel_type'] = 'not_available';
                    $updates['classification_source'] = $classificationSource ?: 'AIS_STATIC_MESSAGE_SHIPTYPE';
                    $updates['ais_ship_type_code'] = 0;
                    $updates['classified_at'] = $observedAt;
                }
            } elseif ($vesselType && $vesselType !== 'unknown') {
                $isOlder = false;
                $existingClassifiedAt = $vessel->classified_at
                    ?? (!empty($vessel->metadata['classified_at']) ? \Carbon\Carbon::parse($vessel->metadata['classified_at']) : null);

                if ($existingClassifiedAt && $observedAt->lt($existingClassifiedAt)) {
                    $isOlder = true;
                }

                if (!$isOlder) {
                    $isAnalystVerified = $vessel->classification_source === 'ANALYST_VERIFICATION'
                        || in_array($vessel->verification_status, ['verified', 'reclassified'], true);

                    if ($isAnalystVerified) {
                        // Conflict review if analyst verified
                        if (strtolower((string) $vesselType) !== strtolower((string) $vessel->vessel_type)) {
                            $updates['ais_ship_type_code'] = $aisTypeCode;
                            $updates['verification_status'] = 'under_review';

                            $meta = $vessel->metadata ?? [];
                            $meta['pending_conflict_review'] = [
                                'detected_at' => $observedAt->toIso8601String(),
                                'conflicting_ais_type' => $vesselType,
                                'conflicting_ais_code' => $aisTypeCode,
                                'source' => $classificationSource ?: 'AIS_STATIC_MESSAGE_SHIPTYPE',
                                'retained_analyst_type' => $vessel->vessel_type,
                            ];
                            $meta['static_data_received_at'] = $observedAt->toIso8601String();
                            $updates['metadata'] = $meta;
                        } else {
                            $updates['ais_ship_type_code'] = $aisTypeCode;
                        }
                    } else {
                        // Standard static data updates identity and vessel_type automatically
                        $updates['vessel_type'] = $vesselType;
                        $updates['classification_source'] = $classificationSource ?: 'AIS_STATIC_MESSAGE_SHIPTYPE';
                        $updates['ais_ship_type_code'] = $aisTypeCode;
                        $updates['classified_at'] = $observedAt;

                        $meta = $vessel->metadata ?? [];
                        $meta['classification_source'] = $classificationSource ?: 'AIS_STATIC_MESSAGE_SHIPTYPE';
                        $meta['ais_ship_type_code'] = $aisTypeCode;
                        $meta['classified_at'] = $observedAt->toIso8601String();
                        $meta['static_data_received_at'] = $observedAt->toIso8601String();
                        $updates['metadata'] = $meta;
                    }
                }
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

        if ($vessel && $vessel->vessel_type === 'unknown' && $vessel->enrichment_status === 'not_enriched') {
            $this->dispatchGfwEnrichmentAsync($vessel->id);
        }

        return $vessel;
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

        // Validate coordinate bounds and reject unacquired GPS sentinel (0, 0)
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || ($lat == 0.0 && $lng == 0.0)) {
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
        $observedAt = isset($message['observed_at'])
            ? \Carbon\Carbon::parse($message['observed_at'])
            : (isset($message['timestamp']) ? \Carbon\Carbon::parse($message['timestamp']) : now());

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
        $aisTypeCode = null;

        if ($rawShipType !== null && $rawShipType !== '') {
            $aisTypeCode = is_numeric($rawShipType) ? (int) $rawShipType : null;
            $vesselType = self::normalizeVesselType($rawShipType);
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

        return DB::transaction(function () use ($message, $cleanMmsi, $cleanImo, $cleanCallsign, $shipName, $vesselType, $aisTypeCode, $lat, $lng, $sog, $cog, $heading, $observedAt, $provider) {
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
                    'ais_ship_type_code' => $aisTypeCode,
                    'static_data_received_at' => null,
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
                if ($aisTypeCode !== null && $vessel->ais_ship_type_code === null) {
                    $vesselUpdates['ais_ship_type_code'] = $aisTypeCode;
                }
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

        if ($position && $position->vessel && $position->vessel->vessel_type === 'unknown' && $position->vessel->enrichment_status === 'not_enriched') {
            $this->dispatchGfwEnrichmentAsync($position->vessel->id);
        }

        return $position;
    }

    /**
     * Dispatch GFW vessel enrichment asynchronously in the background.
     * Prevents queue spam and rate-limit exhaustion using a 1-hour debounce lock.
     */
    protected function dispatchGfwEnrichmentAsync(string $vesselId): void
    {
        if (!config('maritime.providers.gfw.unknown_policy.async_enrichment_enabled', true)) {
            return;
        }

        $lockKey = 'gfw_enrich_dispatch_' . $vesselId;
        $lock = Cache::lock($lockKey, 3600);

        if ($lock->get()) {
            EnrichVesselGfwJob::dispatch($vesselId)->delay(now()->addSeconds(10));
        }
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
