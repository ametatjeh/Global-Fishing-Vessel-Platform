<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GfwApiService
{
    protected ?string $token;
    protected string $baseUrl;
    protected int $timeout;

    public const DATASET_VESSEL_IDENTITY = 'public-global-vessel-identity:latest';
    public const DATASET_PORT_VISITS = 'public-global-port-visits-events:latest';
    public const DATASET_LOITERING = 'public-global-loitering-events:latest';
    public const DATASET_ENCOUNTERS = 'public-global-encounters-events:latest';
    public const DATASET_FISHING = 'public-global-fishing-events:latest';
    public const DATASET_AIS_GAPS = 'public-global-gaps-events:latest';

    public function __construct()
    {
        $this->token = config('maritime.providers.gfw.token') ?: (env('GFW_API_ACCESS_TOKEN') ?: env('GFW_API_TOKEN'));
        $this->baseUrl = rtrim(config('maritime.providers.gfw.base_url', 'https://gateway.api.globalfishingwatch.org/v3'), '/');
        $this->timeout = (int) config('maritime.providers.gfw.timeout', 20);
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Check authentication against lightweight dataset catalog.
     */
    public function probeAuthentication(): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'not_configured',
                'authenticated' => false,
                'message' => 'GFW API token is not configured in .env (GFW_API_TOKEN).',
                'latency_ms' => null,
            ];
        }

        $start = microtime(true);
        try {
            $response = $this->client()->get("{$this->baseUrl}/datasets", [
                'limit' => 1,
                'offset' => 0,
            ]);
            $latency = round((microtime(true) - $start) * 1000, 2);

            if ($response->status() === 200) {
                return [
                    'status' => 'authenticated',
                    'authenticated' => true,
                    'message' => 'Koneksi ke gateway GFW API v3 berhasil terotentikasi (HTTP 200 OK).',
                    'latency_ms' => $latency,
                ];
            }

            if ($response->status() === 401) {
                return [
                    'status' => 'unauthenticated',
                    'authenticated' => false,
                    'message' => 'Autentikasi gagal (HTTP 401 Unauthorized): Token API GFW tidak valid atau telah kadaluarsa.',
                    'latency_ms' => $latency,
                ];
            }

            if ($response->status() === 429) {
                return [
                    'status' => 'rate_limited',
                    'authenticated' => false,
                    'message' => 'Batas laju permintaan gateway GFW terlampaui (HTTP 429 Too Many Requests).',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status' => 'error',
                'authenticated' => false,
                'message' => "Gateway GFW merespons dengan status HTTP {$response->status()}.",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            return [
                'status' => 'network_error',
                'authenticated' => false,
                'message' => 'Gagal menghubungi server GFW: ' . $this->sanitizeMessage($e->getMessage()),
                'latency_ms' => $latency,
            ];
        }
    }

    /**
     * Check individual dataset capabilities with proper v3 parameter formatting.
     */
    public function checkDatasetCapabilities(): array
    {
        $datasets = [
            'vessel_identity' => self::DATASET_VESSEL_IDENTITY,
            'port_visits' => self::DATASET_PORT_VISITS,
            'loitering' => self::DATASET_LOITERING,
            'fishing' => self::DATASET_FISHING,
            'encounters' => self::DATASET_ENCOUNTERS,
            'ais_gaps' => self::DATASET_AIS_GAPS,
        ];

        $capabilities = [];

        foreach ($datasets as $key => $dataset) {
            if ($key === 'vessel_identity') {
                // Test vessel search capability
                try {
                    $res = $this->client()->get("{$this->baseUrl}/vessels/search", [
                        'datasets[0]' => $dataset,
                        'query' => '1000000', // Dummy probe numeric
                    ]);
                    $capabilities[$key] = [
                        'dataset' => $dataset,
                        'status' => $res->status() === 200 ? 'available' : ($res->status() === 403 ? 'permission_required' : 'unavailable'),
                        'http_code' => $res->status(),
                    ];
                } catch (\Throwable $e) {
                    $capabilities[$key] = [
                        'dataset' => $dataset,
                        'status' => 'network_error',
                        'http_code' => null,
                    ];
                }
            } else {
                // Test events capability with correct pagination params
                try {
                    $res = $this->client()->get("{$this->baseUrl}/events", [
                        'datasets[0]' => $dataset,
                        'limit' => 1,
                        'offset' => 0,
                    ]);
                    $status = match ($res->status()) {
                        200 => 'available',
                        403 => 'permission_required',
                        429 => 'rate_limited',
                        default => 'unavailable',
                    };
                    $capabilities[$key] = [
                        'dataset' => $dataset,
                        'status' => $status,
                        'http_code' => $res->status(),
                    ];
                } catch (\Throwable $e) {
                    $capabilities[$key] = [
                        'dataset' => $dataset,
                        'status' => 'network_error',
                        'http_code' => null,
                    ];
                }
            }
        }

        return $capabilities;
    }

    /**
     * Search vessels via GET /v3/vessels/search
     */
    public function searchVessels(string $query, string $dataset = self::DATASET_VESSEL_IDENTITY): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 500,
                'total' => 0,
                'entries' => [],
                'error' => 'GFW API token is not configured.',
            ];
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/vessels/search", [
                'datasets[0]' => $dataset,
                'query' => $query,
            ]);

            return $this->normalizeSearchResponse($response);
        } catch (\Throwable $e) {
            Log::warning("GFW vessel search failed for query '{$query}': " . $this->sanitizeMessage($e->getMessage()));
            return [
                'status' => 500,
                'total' => 0,
                'entries' => [],
                'error' => $this->sanitizeMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Get vessel detail via GET /v3/vessels/{vesselId}
     */
    public function getVessel(string $vesselId, string $dataset = self::DATASET_VESSEL_IDENTITY): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/vessels/{$vesselId}", [
                'dataset' => $dataset,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning("GFW getVessel failed for ID '{$vesselId}': " . $this->sanitizeMessage($e->getMessage()));
            return null;
        }
    }

    /**
     * Get events via GET /v3/events
     */
    public function getEvents(array $params): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 500,
                'total' => 0,
                'entries' => [],
                'error' => 'GFW API token is not configured.',
            ];
        }

        // Ensure pagination parameters exist
        if (!isset($params['limit'])) {
            $params['limit'] = 10;
        }
        if (!isset($params['offset'])) {
            $params['offset'] = 0;
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/events", $params);

            if ($response->successful()) {
                $json = $response->json();
                return [
                    'status' => 200,
                    'total' => $json['total'] ?? count($json['entries'] ?? []),
                    'entries' => $json['entries'] ?? [],
                    'error' => null,
                ];
            }

            return [
                'status' => $response->status(),
                'total' => 0,
                'entries' => [],
                'error' => $this->sanitizeMessage($response->body()),
            ];
        } catch (\Throwable $e) {
            Log::warning("GFW getEvents failed: " . $this->sanitizeMessage($e->getMessage()));
            return [
                'status' => 500,
                'total' => 0,
                'entries' => [],
                'error' => $this->sanitizeMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Get event stats via POST /v3/events/stats
     */
    public function getEventsStats(array $payload): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 500, 'data' => null, 'error' => 'GFW API token is not configured.'];
        }

        try {
            $response = $this->client()->post("{$this->baseUrl}/events/stats", $payload);
            if ($response->successful()) {
                return ['status' => 200, 'data' => $response->json(), 'error' => null];
            }
            return ['status' => $response->status(), 'data' => null, 'error' => $this->sanitizeMessage($response->body())];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => null, 'error' => $this->sanitizeMessage($e->getMessage())];
        }
    }

    /**
     * Get single event detail via GET /v3/events/{id}
     */
    public function getEventDetail(string $eventId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/events/{$eventId}");
            if ($response->successful()) {
                return $response->json();
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Internal secure HTTP client instance.
     */
    protected function client()
    {
        return Http::withToken($this->token)
            ->timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'GFVIP-Maritime-Intelligence/1.0',
            ]);
    }

    /**
     * Normalize search responses across GFW versions.
     */
    protected function normalizeSearchResponse(Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();
            return [
                'status' => 200,
                'total' => $json['total'] ?? count($json['entries'] ?? []),
                'entries' => $json['entries'] ?? [],
                'error' => null,
            ];
        }

        return [
            'status' => $response->status(),
            'total' => 0,
            'entries' => [],
            'error' => $this->sanitizeMessage($response->body()),
        ];
    }

    /**
     * Ensure secret tokens or credentials are never exposed in log messages or errors.
     */
    public function sanitizeMessage(string $msg): string
    {
        if ($this->token) {
            $msg = str_replace($this->token, '[REDACTED_GFW_TOKEN]', $msg);
        }
        return preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [REDACTED]', $msg);
    }
}

