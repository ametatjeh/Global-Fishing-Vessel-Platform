<?php

namespace App\Services\Providers;

use App\Models\DataProvider;
use App\Models\ProviderIngestionRun;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\EncounterVessel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GlobalFishingWatchAdapter implements ProviderAdapterInterface
{
    protected ?string $token;
    protected string $baseUrl;
    protected int $timeout;
    protected int $cacheTtl;

    public function __construct()
    {
        $this->token = config('maritime.providers.gfw.token');
        $this->baseUrl = config('maritime.providers.gfw.base_url', 'https://gateway.api.globalfishingwatch.org/v3');
        $this->timeout = (int) config('maritime.providers.gfw.timeout', 30);
        $this->cacheTtl = (int) config('maritime.providers.gfw.cache_ttl', 3600);
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    public function healthCheck(): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'not_configured',
                'message' => 'GFW API Token belum dikonfigurasi dalam .env (GFW_API_TOKEN). Sumber eksternal tidak aktif.',
                'latency_ms' => null,
                'timestamp' => now()->toISOString(),
            ];
        }

        $startTime = microtime(true);

        try {
            // Check GFW datasets endpoint as lightweight authentication probe (no automated retries on 4xx/5xx)
            $response = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/datasets", [
                    'limit' => 1,
                    'offset' => 0,
                ]);

            $latency = round((microtime(true) - $startTime) * 1000, 2);

            // 1. Authentication failure (Invalid or expired token)
            if ($response->status() === 401) {
                $status = 'unauthenticated';
                $statusMsg = 'Autentikasi gagal (HTTP 401 Unauthorized): Token API GFW tidak valid atau telah kadaluarsa.';
                $this->updateProviderStatus($status, $statusMsg, $latency);

                return [
                    'status' => $status,
                    'message' => $statusMsg,
                    'latency_ms' => $latency,
                    'timestamp' => now()->toISOString(),
                ];
            }

            // 2. Rate limit on datasets
            if ($response->status() === 429) {
                $status = 'rate_limited';
                $statusMsg = 'Batas laju permintaan gateway GFW terlampaui (HTTP 429 Too Many Requests). Silakan tunggu sebelum melakukan panggilan berikutnya.';
                $this->updateProviderStatus($status, $statusMsg, $latency);

                return [
                    'status' => $status,
                    'message' => $statusMsg,
                    'latency_ms' => $latency,
                    'timestamp' => now()->toISOString(),
                ];
            }

            // 3. Server error on datasets
            if ($response->serverError()) {
                $status = 'server_error';
                $statusMsg = "Gateway GFW mengalami kesalahan internal pada server (HTTP {$response->status()}).";
                $this->updateProviderStatus($status, $statusMsg, $latency);

                return [
                    'status' => $status,
                    'message' => $statusMsg,
                    'latency_ms' => $latency,
                    'timestamp' => now()->toISOString(),
                ];
            }

            // 4. Token authenticated on datasets, now verify permission on Events API (single probe, no retry)
            if ($response->successful()) {
                $datasetId = 'public-global-encounters-events:latest';
                $eventsProbe = Http::withToken($this->token)
                    ->timeout(10)
                    ->get("{$this->baseUrl}/events", [
                        'datasets' => $datasetId,
                        'start-date' => now()->subDays(2)->toDateString(),
                        'end-date' => now()->toDateString(),
                        'limit' => 1,
                    ]);

                // 4a. HTTP 403 Forbidden: Research Access required
                if ($eventsProbe->status() === 403) {
                    $status = 'research_access_required';
                    $statusMsg = 'Access denied — Research Access required. Token API valid dan terotentikasi di katalog GFW, namun akun belum memiliki izin Research Access untuk dataset Events API (' . $datasetId . '). Silakan ajukan izin akses di https://globalfishingwatch.org/our-apis/tokens.';
                    $this->updateProviderStatus($status, 'HTTP 403: Research Access permission required untuk dataset ' . $datasetId, $latency);

                    return [
                        'status' => $status,
                        'message' => $statusMsg,
                        'latency_ms' => $latency,
                        'timestamp' => now()->toISOString(),
                        'guidance_url' => 'https://globalfishingwatch.org/our-apis/tokens',
                    ];
                }

                // 4b. Events endpoint rate limited
                if ($eventsProbe->status() === 429) {
                    $status = 'rate_limited';
                    $statusMsg = 'Batas laju permintaan endpoint /events terlampaui (HTTP 429 Too Many Requests).';
                    $this->updateProviderStatus($status, $statusMsg, $latency);

                    return [
                        'status' => $status,
                        'message' => $statusMsg,
                        'latency_ms' => $latency,
                        'timestamp' => now()->toISOString(),
                    ];
                }

                // 4c. Events endpoint server error
                if ($eventsProbe->serverError()) {
                    $status = 'server_error';
                    $statusMsg = "Endpoint /events GFW mengalami kesalahan internal server (HTTP {$eventsProbe->status()}).";
                    $this->updateProviderStatus($status, $statusMsg, $latency);

                    return [
                        'status' => $status,
                        'message' => $statusMsg,
                        'latency_ms' => $latency,
                        'timestamp' => now()->toISOString(),
                    ];
                }

                // 4d. Events endpoint accessible
                if ($eventsProbe->successful()) {
                    $provider = DataProvider::where('slug', 'global-fishing-watch')->first();
                    $hasRecentEvents = VesselEncounter::where('source_provider_id', $provider?->id)
                        ->where('created_at', '>=', now()->subHours(24))
                        ->exists();

                    if ($hasRecentEvents) {
                        $status = 'receiving_data';
                        $statusMsg = 'Koneksi ke GFW Events API v3 terotentikasi dan aktif menerima event perikanan (data 24 jam terakhir tersedia).';
                    } else {
                        $status = 'authenticated';
                        $statusMsg = 'Koneksi ke GFW API v3 berhasil terotentikasi dan izin Events API aktif (HTTP 200 OK).';
                    }

                    $this->updateProviderStatus($status, null, $latency);

                    return [
                        'status' => $status,
                        'message' => $statusMsg,
                        'latency_ms' => $latency,
                        'timestamp' => now()->toISOString(),
                    ];
                }

                // Other unexpected non-200 code on events probe
                $status = 'error';
                $statusMsg = "Endpoint /events merespons dengan status HTTP {$eventsProbe->status()}.";
                $this->updateProviderStatus($status, $statusMsg, $latency);

                return [
                    'status' => $status,
                    'message' => $statusMsg,
                    'latency_ms' => $latency,
                    'timestamp' => now()->toISOString(),
                ];
            }

            $errorMsg = $this->sanitizeErrorMessage("GFW API merespons dengan status {$response->status()}: " . substr($response->body(), 0, 150));
            $this->updateProviderStatus('error', $errorMsg, $latency);

            return [
                'status' => 'error',
                'message' => $errorMsg,
                'latency_ms' => $latency,
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $msg = $e->getMessage();
            $isTimeout = str_contains(strtolower($msg), 'timed out')
                || str_contains(strtolower($msg), 'timeout')
                || str_contains($msg, 'cURL error 28');

            $status = $isTimeout ? 'network_error' : 'error';
            $errorMsg = $isTimeout
                ? 'Koneksi ke GFW API timeout atau jaringan terputus (network_error).'
                : 'Gagal menghubungi server GFW: ' . $this->sanitizeErrorMessage($msg);

            $this->updateProviderStatus($status, $errorMsg, $latency);

            return [
                'status' => $status,
                'message' => $errorMsg,
                'latency_ms' => $latency,
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Synchronize events (e.g. encounters) from GFW Events API v3
     */
    public function sync(array $options = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'not_configured',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['GFW API token is not configured.'],
            ];
        }

        $provider = DataProvider::firstOrCreate(
            ['slug' => 'global-fishing-watch'],
            [
                'name' => 'Global Fishing Watch (Events API v3)',
                'type' => 'gfw_events',
                'api_url' => $this->baseUrl,
                'auth_type' => 'bearer_token',
                'license_type' => 'GFW Research & Non-Commercial',
                'commercial_use_allowed' => false,
                'retention_allowed' => true,
                'redistribution_allowed' => false,
            ]
        );

        $run = ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_encounters',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $startDate = $options['start_date'] ?? now()->subDays(7)->toDateString();
        $endDate = $options['end_date'] ?? now()->toDateString();
        $limit = $options['limit'] ?? 50;

        $fetched = 0;
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        try {
            $params = [
                'datasets' => 'public-global-encounters-events:latest',
                'start-date' => $startDate,
                'end-date' => $endDate,
                'limit' => $limit,
            ];

            if (!empty($options['bbox'])) {
                $params['geometry'] = json_encode([
                    'type' => 'Polygon',
                    'coordinates' => [
                        [
                            [$options['bbox'][0], $options['bbox'][1]],
                            [$options['bbox'][2], $options['bbox'][1]],
                            [$options['bbox'][2], $options['bbox'][3]],
                            [$options['bbox'][0], $options['bbox'][3]],
                            [$options['bbox'][0], $options['bbox'][1]],
                        ]
                    ]
                ]);
            }

            $httpRes = Http::withToken($this->token)
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/events", $params);

            // Explicitly handle 403 Forbidden without retrying or inventing data
            if ($httpRes->status() === 403) {
                $errorMsg = "Access denied — Research Access required: Token API terotentikasi di katalog GFW, namun dataset '{$params['datasets']}' memerlukan izin Research Access dari Global Fishing Watch. Silakan ajukan izin di https://globalfishingwatch.org/our-apis/tokens";
                $sanitizedError = $this->sanitizeErrorMessage($errorMsg);

                $run->update([
                    'status' => 'failed',
                    'error_message' => $sanitizedError,
                    'items_fetched' => 0,
                    'items_inserted' => 0,
                    'items_skipped' => 0,
                    'completed_at' => now(),
                ]);
                $provider->update([
                    'health_status' => 'research_access_required',
                    'last_health_check_at' => now(),
                    'last_error_message' => 'HTTP 403 Forbidden: Memerlukan Research Access. Silakan ajukan permohonan di https://globalfishingwatch.org/our-apis/tokens',
                ]);

                return [
                    'status' => 'failed',
                    'error_type' => 'forbidden_research_required',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [$sanitizedError],
                    'guidance_url' => 'https://globalfishingwatch.org/our-apis/tokens',
                ];
            }

            if ($httpRes->status() === 401) {
                $errorMsg = 'Autentikasi gagal (HTTP 401 Unauthorized): Token API GFW tidak valid atau telah kadaluarsa.';
                $sanitizedError = $this->sanitizeErrorMessage($errorMsg);

                $run->update([
                    'status' => 'failed',
                    'error_message' => $sanitizedError,
                    'items_fetched' => 0,
                    'items_inserted' => 0,
                    'items_skipped' => 0,
                    'completed_at' => now(),
                ]);
                $provider->update([
                    'health_status' => 'unauthenticated',
                    'last_health_check_at' => now(),
                    'last_error_message' => $sanitizedError,
                ]);

                return [
                    'status' => 'failed',
                    'error_type' => 'unauthenticated',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [$sanitizedError],
                ];
            }

            if ($httpRes->status() === 429) {
                $errorMsg = 'Batas laju permintaan endpoint /events terlampaui (HTTP 429 Too Many Requests).';
                $sanitizedError = $this->sanitizeErrorMessage($errorMsg);

                $run->update([
                    'status' => 'failed',
                    'error_message' => $sanitizedError,
                    'items_fetched' => 0,
                    'items_inserted' => 0,
                    'items_skipped' => 0,
                    'completed_at' => now(),
                ]);
                $provider->update([
                    'health_status' => 'rate_limited',
                    'last_health_check_at' => now(),
                    'last_error_message' => $sanitizedError,
                ]);

                return [
                    'status' => 'failed',
                    'error_type' => 'rate_limited',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [$sanitizedError],
                ];
            }

            if (!$httpRes->successful()) {
                throw new \Exception("GFW Events API returned HTTP {$httpRes->status()}: " . substr($httpRes->body(), 0, 200));
            }

            $response = $httpRes->json();
            $entries = $response['entries'] ?? [];
            $fetched = count($entries);

            // Explicitly handle clean empty response (200 OK with no entries)
            if ($fetched === 0) {
                $run->update([
                    'status' => 'completed',
                    'items_fetched' => 0,
                    'items_inserted' => 0,
                    'items_skipped' => 0,
                    'completed_at' => now(),
                ]);

                $provider->update([
                    'last_sync_at' => now(),
                    'last_error_message' => null,
                ]);

                return [
                    'status' => 'empty',
                    'fetched' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'errors' => [],
                ];
            }

            foreach ($entries as $event) {
                $externalId = $event['id'] ?? null;
                if (!$externalId) {
                    $skipped++;
                    continue;
                }

                // Check for deduplication
                if (VesselEncounter::where('external_event_id', $externalId)->exists()) {
                    $skipped++;
                    continue;
                }

                // Process vessel identities
                $vessels = $event['vessels'] ?? [];
                if (count($vessels) < 2) {
                    $skipped++;
                    continue;
                }

                $v1 = $this->resolveVessel($vessels[0], $provider->id);
                $v2 = $this->resolveVessel($vessels[1], $provider->id);

                $startTime = isset($event['start']) ? date('Y-m-d H:i:s', strtotime($event['start'])) : now();
                $endTime = isset($event['end']) ? date('Y-m-d H:i:s', strtotime($event['end'])) : now();
                $duration = max(1, (int) round((strtotime($endTime) - strtotime($startTime)) / 60));

                $position = $event['position'] ?? [];
                $lat = $position['lat'] ?? 0;
                $lon = $position['lon'] ?? 0;

                $encounter = VesselEncounter::create([
                    'encounter_code' => 'ENC-GFW-' . substr($externalId, -8),
                    'vessel_1_id' => $v1->id,
                    'vessel_2_id' => $v2->id,
                    'category' => $this->mapCategory($v1->vessel_type, $v2->vessel_type),
                    'status' => 'candidate',
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration_minutes' => $duration,
                    'min_distance_meters' => $event['min_distance'] ?? 300.0,
                    'median_distance_meters' => $event['median_distance'] ?? 400.0,
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'detection_method' => 'gfw_events',
                    'algorithm_version' => 'GFW-Events-v3',
                    'parameters_used' => [
                        'provider' => 'Global Fishing Watch',
                        'dataset' => 'public-global-encounters-events:latest',
                    ],
                    'source_provider_id' => $provider->id,
                    'external_event_id' => $externalId,
                    'analyst_notes' => 'Imported via GFW Events API v3. Non-commercial research dataset.',
                ]);

                EncounterVessel::create([
                    'encounter_id' => $encounter->id,
                    'vessel_id' => $v1->id,
                    'role' => 'participant',
                    'flag_state' => $v1->flag_state,
                    'vessel_type' => $v1->vessel_type,
                ]);

                EncounterVessel::create([
                    'encounter_id' => $encounter->id,
                    'vessel_id' => $v2->id,
                    'role' => 'participant',
                    'flag_state' => $v2->flag_state,
                    'vessel_type' => $v2->vessel_type,
                ]);

                $inserted++;
            }

            $run->update([
                'status' => 'completed',
                'items_fetched' => $fetched,
                'items_inserted' => $inserted,
                'items_skipped' => $skipped,
                'completed_at' => now(),
            ]);

            $provider->update([
                'last_sync_at' => now(),
                'health_status' => 'connected',
            ]);
        } catch (\Throwable $e) {
            $sanitizedError = $this->sanitizeErrorMessage($e->getMessage());
            $errors[] = $sanitizedError;
            Log::error('GFW Sync Error: ' . $sanitizedError);

            $run->update([
                'status' => 'failed',
                'error_message' => $sanitizedError,
                'completed_at' => now(),
            ]);

            $provider->update([
                'last_error_message' => $sanitizedError,
            ]);
        }

        return [
            'status' => empty($errors) ? 'completed' : 'failed',
            'fetched' => $fetched,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    protected function resolveVessel(array $vData, int $providerId): Vessel
    {
        $mmsi = $vData['ssvid'] ?? $vData['mmsi'] ?? null;
        $name = $vData['name'] ?? ('VESSEL-' . ($mmsi ?: substr(md5(json_encode($vData)), 0, 6)));

        $rawType = strtolower(trim((string) ($vData['vesselType'] ?? '')));
        // RULE B: Strict prohibition on third-party / external APIs classifying cargo or tanker.
        // Classification of cargo and tanker is exclusively reserved for local AISStream ShipType.
        if (in_array($rawType, ['cargo', 'tanker'])) {
            $vesselType = 'unknown';
        } else {
            $vesselType = !empty($vData['vesselType']) ? $vData['vesselType'] : 'fishing';
        }

        return Vessel::firstOrCreate(
            ['mmsi' => $mmsi],
            [
                'name' => $name,
                'flag_state' => $vData['flag'] ?? null,
                'vessel_type' => $vesselType,
                'callsign' => $vData['callsign'] ?? null,
                'imo' => $vData['imo'] ?? null,
                'source_provider_id' => $providerId,
                'verification_status' => 'provider_reported',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );
    }

    protected function mapCategory(string $type1, string $type2): string
    {
        $types = strtolower($type1 . ' ' . $type2);
        if (str_contains($types, 'carrier') || str_contains($types, 'reefer')) {
            return 'fishing_carrier';
        }
        if (str_contains($types, 'bunker') || str_contains($types, 'tanker')) {
            return 'fishing_bunker';
        }
        if (str_contains($types, 'support') || str_contains($types, 'tug')) {
            return 'fishing_support';
        }
        return 'fishing_fishing';
    }

    protected function updateProviderStatus(string $status, ?string $error = null, ?float $latency = null): void
    {
        DataProvider::updateOrCreate(
            ['slug' => 'global-fishing-watch'],
            [
                'name' => 'Global Fishing Watch (Events API v3)',
                'type' => 'gfw_events',
                'api_url' => $this->baseUrl,
                'auth_type' => 'bearer_token',
                'is_enabled' => $this->isConfigured(),
                'health_status' => $status,
                'last_health_check_at' => now(),
                'last_error_message' => $error ? $this->sanitizeErrorMessage($error) : null,
                'license_type' => 'GFW Research & Non-Commercial',
                'commercial_use_allowed' => false,
                'retention_allowed' => true,
                'redistribution_allowed' => false,
                'rate_limit_info' => $latency ? ['latency_ms' => $latency] : null,
            ]
        );
    }

    /**
     * Sanitize error message to ensure API tokens or sensitive headers are never leaked.
     */
    public function sanitizeErrorMessage(?string $message): string
    {
        if (empty($message)) {
            return '';
        }

        if (!empty($this->token)) {
            $message = str_replace($this->token, '[REDACTED_GFW_TOKEN]', $message);
        }

        $message = preg_replace('/Bearer\s+[A-Za-z0-9\-_.]{10,}/i', 'Bearer [REDACTED]', $message);
        $message = preg_replace('/([?&](?:token|key|api_key|secret)=)[^&\s]+/i', '$1[REDACTED]', $message);

        return $message;
    }

    public function getMetadata(): array
    {
        return [
            'provider' => 'Global Fishing Watch',
            'api_version' => 'v3',
            'configured' => $this->isConfigured(),
            'commercial_license' => false,
            'terms_url' => 'https://globalfishingwatch.org/our-apis/documentation',
            'notice' => 'Global Fishing Watch datasets are provided for research, monitoring, and non-commercial purposes only under strict API guidelines.',
        ];
    }
}
