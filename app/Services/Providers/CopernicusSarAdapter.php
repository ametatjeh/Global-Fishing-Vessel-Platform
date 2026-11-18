<?php

namespace App\Services\Providers;

use App\Models\DataProvider;
use App\Models\ProviderIngestionRun;
use App\Models\SatelliteScene;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CopernicusSarAdapter implements ProviderAdapterInterface
{
    protected string $authMode;
    protected ?string $username;
    protected ?string $password;
    protected ?string $totp;
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected string $baseUrl;
    protected string $authUrl;
    protected string $odataUrl;
    protected string $downloadUrl;
    protected int $timeout;
    protected ?array $lastAuthDiagnostics = null;

    public function __construct()
    {
        $this->authMode = strtolower((string) config('maritime.providers.copernicus_sar.auth_mode', 'password'));
        $this->username = config('maritime.providers.copernicus_sar.username');
        $this->password = config('maritime.providers.copernicus_sar.password');
        $this->totp = config('maritime.providers.copernicus_sar.totp');
        $this->clientId = config('maritime.providers.copernicus_sar.client_id');
        $this->clientSecret = config('maritime.providers.copernicus_sar.client_secret');
        $this->baseUrl = config('maritime.providers.copernicus_sar.base_url', 'https://dataspace.copernicus.eu/analyse/apis');
        $this->authUrl = config('maritime.providers.copernicus_sar.auth_url', 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token');
        $this->odataUrl = rtrim((string) config('maritime.providers.copernicus_sar.odata_url', 'https://catalogue.dataspace.copernicus.eu/odata/v1'), '/');
        $this->downloadUrl = rtrim((string) config('maritime.providers.copernicus_sar.download_url', 'https://download.dataspace.copernicus.eu/odata/v1'), '/');
        $this->timeout = (int) config('maritime.providers.copernicus_sar.timeout', 30);
    }

    /**
     * Check whether authentication credentials have been configured.
     */
    public function isAuthConfigured(): bool
    {
        if ($this->authMode === 'client_credentials') {
            return !empty($this->clientId) && !empty($this->clientSecret) && $this->clientId !== 'cdse-public';
        }

        // Default: password grant (username & password)
        return !empty($this->username) && !empty($this->password);
    }

    /**
     * Backward-compatible alias for provider configuration check.
     */
    public function isConfigured(): bool
    {
        return $this->isAuthConfigured();
    }

    /**
     * Get the active authentication mode ('password' or 'client_credentials').
     */
    public function getAuthMode(): string
    {
        return $this->authMode;
    }

    /**
     * Obtain a valid OAuth2 Bearer token from Copernicus Keycloak.
     * Manages expiration, refresh tokens, concurrency locks, and caching.
     */
    public function getAccessToken(): ?string
    {
        // 1. Check if token already exists in cache
        $cachedToken = Cache::get('copernicus_cdse_access_token') ?? Cache::get('copernicus_cdse_oauth_token');
        if (!empty($cachedToken)) {
            return $cachedToken;
        }

        if (!$this->isAuthConfigured()) {
            return null;
        }

        // 2. Prevent parallel thundering herd on Keycloak with concurrency lock
        try {
            $lock = Cache::lock('copernicus_token_acquisition_lock', 10);
            return $lock->block(5, function () {
                $token = Cache::get('copernicus_cdse_access_token') ?? Cache::get('copernicus_cdse_oauth_token');
                if (!empty($token)) {
                    return $token;
                }

                return $this->acquireToken();
            });
        } catch (\Throwable) {
            return $this->acquireToken();
        }
    }

    /**
     * Internal token acquisition workflow: tries refresh token first, then full grant.
     */
    protected function acquireToken(): ?string
    {
        // A. Attempt refresh token first if available
        $refreshToken = Cache::get('copernicus_cdse_refresh_token');
        if (!empty($refreshToken)) {
            $token = $this->attemptTokenRefresh($refreshToken);
            if (!empty($token)) {
                return $token;
            }
        }

        // B. Perform primary token grant request
        return $this->requestNewToken();
    }

    /**
     * Request a fresh access token using either Password Grant or Client Credentials.
     */
    protected function requestNewToken(): ?string
    {
        if ($this->authMode === 'client_credentials') {
            $payload = [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ];
        } else {
            $payload = [
                'client_id' => 'cdse-public',
                'username' => $this->username,
                'password' => $this->password,
                'grant_type' => 'password',
            ];

            if (!empty($this->totp)) {
                $payload['totp'] = $this->totp;
            }
        }

        $startTime = microtime(true);
        $attempts = 0;
        $maxRetries = 2;

        while ($attempts <= $maxRetries) {
            $attempts++;
            try {
                $response = Http::asForm()
                    ->timeout($this->timeout)
                    ->post($this->authUrl, $payload);

                $durationMs = round((microtime(true) - $startTime) * 1000, 2);

                if ($response->successful()) {
                    $json = $response->json();
                    $token = $json['access_token'] ?? null;
                    if (!$token) {
                        $this->recordAuthError(500, 'malformed_response', 'Keycloak merespons status 200 namun tidak menyertakan access_token.', $durationMs);
                        return null;
                    }

                    // Dynamic TTL calculation based on expires_in with 60s safety buffer
                    $expiresIn = (int) ($json['expires_in'] ?? 600);
                    $safeTtl = max(60, $expiresIn - 60);

                    Cache::put('copernicus_cdse_access_token', $token, $safeTtl);
                    Cache::put('copernicus_cdse_oauth_token', $token, $safeTtl);

                    // Cache refresh token if present
                    if (!empty($json['refresh_token'])) {
                        $refreshExpires = (int) ($json['refresh_expires_in'] ?? 1800);
                        Cache::put('copernicus_cdse_refresh_token', $json['refresh_token'], max(60, $refreshExpires - 60));
                    }

                    $this->lastAuthDiagnostics = null;
                    return $token;
                }

                $status = $response->status();
                $body = $response->json() ?? [];
                $error = $body['error'] ?? 'unknown_error';
                $description = $this->sanitizeMessage($body['error_description'] ?? $response->body());

                // HTTP 429 Rate Limiting
                if ($status === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 2);
                    $this->recordAuthError(429, 'rate_limited', "Keycloak rate limit terlampaui (Retry-After: {$retryAfter}s).", $durationMs);
                    if ($attempts <= $maxRetries) {
                        sleep(min(3, max(1, $retryAfter)));
                        continue;
                    }
                    return null;
                }

                // HTTP 400 & 401: Invalid credentials or grant (DO NOT RETRY)
                if ($status === 400 || $status === 401) {
                    $category = ($error === 'invalid_grant' && str_contains(strtolower($description), 'totp'))
                        ? 'missing_2fa'
                        : 'invalid_credentials';

                    $this->recordAuthError($status, $category, "Otentikasi ditolak Keycloak [{$error}]: {$description}", $durationMs);
                    return null;
                }

                // HTTP 403: Forbidden
                if ($status === 403) {
                    $this->recordAuthError(403, 'access_denied', "Akses Keycloak ditolak (HTTP 403): {$description}", $durationMs);
                    return null;
                }

                // HTTP 5xx: Transient Server Error (Retry allowed)
                if ($status >= 500) {
                    $this->recordAuthError($status, 'server_error', "Server Keycloak mengalami gangguan internal (HTTP {$status}).", $durationMs);
                    if ($attempts <= $maxRetries) {
                        usleep(500000); // 500ms backoff
                        continue;
                    }
                    return null;
                }

                $this->recordAuthError($status, 'http_error', "Keycloak merespons dengan HTTP {$status}: {$description}", $durationMs);
                return null;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);
                $this->recordAuthError(0, 'timeout', 'Koneksi ke Copernicus Keycloak timeout (' . $this->timeout . 's): ' . $this->sanitizeMessage($e->getMessage()), $durationMs);

                if ($attempts <= $maxRetries) {
                    usleep(1000000); // 1s backoff for connection retry
                    continue;
                }
                return null;
            } catch (\Throwable $e) {
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);
                $this->recordAuthError(0, 'exception', 'Exception saat menghubungi Keycloak: ' . $this->sanitizeMessage($e->getMessage()), $durationMs);
                return null;
            }
        }

        return null;
    }

    /**
     * Attempt to refresh an expired access token using the stored refresh token.
     */
    protected function attemptTokenRefresh(string $refreshToken): ?string
    {
        $clientId = ($this->authMode === 'client_credentials') ? $this->clientId : 'cdse-public';
        $startTime = microtime(true);

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->post($this->authUrl, [
                    'client_id' => $clientId,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            if ($response->successful()) {
                $json = $response->json();
                $token = $json['access_token'] ?? null;
                if ($token) {
                    $expiresIn = (int) ($json['expires_in'] ?? 600);
                    $safeTtl = max(60, $expiresIn - 60);

                    Cache::put('copernicus_cdse_access_token', $token, $safeTtl);
                    Cache::put('copernicus_cdse_oauth_token', $token, $safeTtl);

                    if (!empty($json['refresh_token'])) {
                        $refreshExpires = (int) ($json['refresh_expires_in'] ?? 1800);
                        Cache::put('copernicus_cdse_refresh_token', $json['refresh_token'], max(60, $refreshExpires - 60));
                    }

                    return $token;
                }
            }

            // If refresh fails (e.g. refresh token expired on Keycloak), purge and return null to trigger fresh login
            Cache::forget('copernicus_cdse_refresh_token');
            return null;
        } catch (\Throwable) {
            Cache::forget('copernicus_cdse_refresh_token');
            return null;
        }
    }

    /**
     * Record sanitized authentication diagnostics without exposing secrets.
     */
    protected function recordAuthError(int $httpStatus, string $category, string $description, float $durationMs): void
    {
        $this->lastAuthDiagnostics = [
            'http_status' => $httpStatus,
            'category' => $category,
            'description' => $description,
            'duration_ms' => $durationMs,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::warning("[Copernicus CDSE Auth] Failure [{$category}]: {$description} (Duration: {$durationMs}ms, HTTP: {$httpStatus})");
    }

    /**
     * Get diagnostic details from the latest authentication attempt.
     */
    public function getLastAuthDiagnostics(): ?array
    {
        return $this->lastAuthDiagnostics;
    }

    /**
     * Health check: Separates public OData Catalogue verification from OAuth2 Keycloak download auth.
     */
    public function healthCheck(): array
    {
        if (!$this->isAuthConfigured()) {
            $this->updateProviderStatus('not_configured', 'Kredensial unduhan CDSE belum dikonfigurasi.');
            return [
                'status' => 'not_configured',
                'message' => 'Copernicus Data Space Ecosystem credentials (CDSE_USERNAME / PASSWORD) belum dikonfigurasi.',
                'catalog_status' => 'inactive',
                'oauth2_status' => 'not_configured',
                'auth_mode' => $this->authMode,
                'download_capability' => 'disabled',
                'reference_id' => null,
                'latency_ms' => null,
                'timestamp' => now()->toISOString(),
            ];
        }

        $startTime = microtime(true);

        // 1. Verify Public OData Catalogue (WITHOUT requiring Bearer token)
        $catalogStatus = 'unavailable';
        $catalogMessage = '';
        $refId = null;

        try {
            $catResponse = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'GFVIP-MaritimePlatform/1.0 (Copernicus CDSE Health Check)',
            ])
            ->timeout($this->timeout)
            ->get("{$this->odataUrl}/Products?\$top=1");

            if ($catResponse->successful()) {
                $catalogStatus = 'available';
                $catalogMessage = 'Katalog OData publik CDSE aktif dan responsif (HTTP 200).';
            } elseif ($catResponse->status() === 403) {
                $rawBody = $catResponse->body();
                if (preg_match('/reference ID:\s*(\d+)/i', $rawBody, $m)) {
                    $refId = $m[1];
                }
                $catalogStatus = 'access_denied_waf';
                $catalogMessage = 'Akses katalog OData dibatasi oleh firewall CDSE WAF (HTTP 403' . ($refId ? ", Ref ID: {$refId}" : '') . ').';
            } else {
                $catalogStatus = 'error';
                $catalogMessage = "Katalog OData CDSE merespons status HTTP {$catResponse->status()}.";
            }
        } catch (\Throwable $e) {
            $catalogStatus = 'unavailable';
            $catalogMessage = 'Koneksi ke katalog OData gagal: ' . $this->sanitizeMessage($e->getMessage());
        }

        // 2. Verify OAuth2 Keycloak Authentication (Used for Product Download)
        $oauthStatus = 'not_configured';
        $oauthMessage = 'Kredensial unduhan produk CDSE belum dikonfigurasi.';

        if ($this->isAuthConfigured()) {
            $token = $this->getAccessToken();
            if ($token) {
                $oauthStatus = 'authenticated';
                $oauthMessage = 'Autentikasi Keycloak terverifikasi (OAuth2 Bearer token aktif).';
            } else {
                $oauthStatus = 'authentication_failed';
                $diag = $this->lastAuthDiagnostics;
                $oauthMessage = $diag ? $diag['description'] : 'Gagal memperoleh token dari Copernicus Keycloak.';
            }
        }

        $totalLatency = round((microtime(true) - $startTime) * 1000, 2);

        // 3. Synthesize composite status
        if ($catalogStatus === 'available' && $oauthStatus === 'authenticated') {
            $status = 'authenticated';
            $message = 'Koneksi ke Copernicus Data Space Ecosystem terverifikasi (OAuth2 Bearer token valid dan katalog OData aktif).';
        } elseif ($catalogStatus === 'available' && $oauthStatus === 'not_configured') {
            $status = 'ready_to_sync';
            $message = 'Katalog OData publik Copernicus CDSE aktif dan siap sinkronisasi metadata (Kredensial unduhan produk belum dikonfigurasi).';
        } elseif ($catalogStatus === 'available' && $oauthStatus === 'authentication_failed') {
            $status = 'catalog_available_auth_failed';
            $message = "Katalog OData publik aktif untuk metadata, namun autentikasi unduhan Keycloak gagal: {$oauthMessage}";
        } elseif ($catalogStatus === 'access_denied_waf') {
            $status = 'catalog_access_failed';
            $message = $catalogMessage;
        } else {
            $status = 'error';
            $message = $catalogMessage;
        }

        $this->updateProviderStatus($status, $status === 'authenticated' ? null : $message, $totalLatency, [
            'odata_catalog' => $catalogStatus,
            'oauth2_keycloak' => $oauthStatus,
            'auth_mode' => $this->authMode,
            'last_auth_category' => $this->lastAuthDiagnostics['category'] ?? null,
            'reference_id' => $refId,
        ]);

        return [
            'status' => $status,
            'message' => $message,
            'catalog_status' => $catalogStatus,
            'oauth2_status' => $oauthStatus,
            'auth_mode' => $this->authMode,
            'download_capability' => $oauthStatus === 'authenticated' ? 'ready' : 'disabled',
            'reference_id' => $refId,
            'latency_ms' => $totalLatency,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Synchronize Sentinel-1 SAR products from Copernicus OData catalogue.
     * Metadata queries run against public OData API and do not require Keycloak token.
     */
    public function sync(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = (int) ($options['limit'] ?? 10);
        $aoiWkt = $options['aoi_polygon'] ?? null;

        $provider = DataProvider::firstOrCreate(
            ['slug' => 'copernicus-sentinel-1'],
            [
                'name' => 'Copernicus Data Space Ecosystem (Sentinel-1 SAR)',
                'type' => 'copernicus_sar',
                'api_url' => $this->baseUrl,
                'auth_type' => 'oauth2',
                'license_type' => 'Copernicus Open Access (Free & Open)',
                'commercial_use_allowed' => true,
                'retention_allowed' => true,
                'redistribution_allowed' => true,
            ]
        );

        $run = ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_sar',
            'status' => 'running',
            'started_at' => now(),
            'metadata_json' => json_encode(['dry_run' => $dryRun, 'limit' => $limit]),
        ]);

        $fetched = 0;
        $inserted = 0;
        $skipped = 0;
        $plannedInsert = 0;
        $newProducts = [];
        $totalEstimatedBytes = 0;
        $errors = [];

        try {
            // OData Catalogue search is public and does not require Bearer token
            $filter = "Collection/Name eq 'SENTINEL-1' and Attributes/OData.CSC.StringAttribute/any(att:att/Name eq 'productType' and att/OData.CSC.StringAttribute/Value eq 'GRD')";
            if ($aoiWkt) {
                $filter .= " and OData.CSC.Intersects(area=geography'SRID=4326;{$aoiWkt}')";
            }

            // Optional Bearer token header if token is available
            $token = $this->getAccessToken();
            $request = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'GFVIP-MaritimePlatform/1.0 (Copernicus CDSE Sentinel-1 Sync)',
            ])->timeout($this->timeout);

            if (!empty($token)) {
                $request = $request->withToken($token);
            }

            $response = $request->get("{$this->odataUrl}/Products", [
                '$filter' => $filter,
                '$top' => $limit,
                '$orderby' => 'ContentDate/Start desc',
            ]);

            if ($response->successful()) {
                $products = $response->json('value') ?? [];
                $fetched = count($products);

                foreach ($products as $prod) {
                    $sceneId = $prod['Id'] ?? $prod['Name'] ?? null;
                    if (!$sceneId) {
                        $skipped++;
                        continue;
                    }

                    if (SatelliteScene::where('scene_id', $sceneId)->exists()) {
                        $skipped++;
                        continue;
                    }

                    $newProducts[] = $sceneId;
                    $contentLen = $prod['ContentLength'] ?? 0;
                    $totalEstimatedBytes += $contentLen;

                    if ($dryRun) {
                        $plannedInsert++;
                        continue;
                    }

                    $footprint = $prod['GeoFootprint'] ?? [
                        'type' => 'Polygon',
                        'coordinates' => [
                            [
                                [95.0, 5.0], [98.0, 5.0], [98.0, 7.0], [95.0, 7.0], [95.0, 5.0]
                            ]
                        ]
                    ];

                    $prodName = (string) ($prod['Name'] ?? '');
                    $platformName = 'Sentinel-1';
                    if (str_starts_with($prodName, 'S1B')) {
                        $platformName = 'Sentinel-1B';
                    } elseif (str_starts_with($prodName, 'S1C')) {
                        $platformName = 'Sentinel-1C';
                    } elseif (str_starts_with($prodName, 'S1A')) {
                        $platformName = 'Sentinel-1A';
                    }

                    SatelliteScene::create([
                        'scene_id' => $sceneId,
                        'platform_name' => $platformName,
                        'instrument' => 'C-SAR',
                        'sensor_mode' => 'IW',
                        'polarization' => 'VV+VH',
                        'orbit_direction' => 'DESCENDING',
                        'acquisition_start' => $prod['ContentDate']['Start'] ?? now()->subHours(6),
                        'acquisition_end' => $prod['ContentDate']['End'] ?? now()->subHours(5),
                        'footprint_geojson' => json_encode($footprint),
                        'cloud_cover_percent' => 0.0,
                        'provider_url' => "https://dataspace.copernicus.eu/browser/?id={$sceneId}",
                        'processing_status' => 'cataloged',
                        'metadata' => [
                            'product_name' => $prodName,
                            'content_length' => $contentLen,
                            'origin' => 'ESA Copernicus Data Space',
                        ],
                    ]);

                    $inserted++;
                }

                $run->update([
                    'status' => 'completed',
                    'items_fetched' => $fetched,
                    'items_inserted' => $inserted,
                    'items_skipped' => $skipped,
                    'completed_at' => now(),
                    'metadata_json' => json_encode([
                        'dry_run' => $dryRun,
                        'planned_insert' => $plannedInsert,
                        'new_products_count' => count($newProducts),
                        'estimated_download_mb' => round($totalEstimatedBytes / (1024 * 1024), 2),
                    ]),
                ]);

                $provider->update([
                    'last_sync_at' => now(),
                    'health_status' => $dryRun ? 'ready_to_sync' : 'connected',
                    'last_error_message' => null,
                ]);
            } else {
                $rawBody = $response->body();
                $refId = null;
                if (preg_match('/reference ID:\s*(\d+)/i', $rawBody, $m)) {
                    $refId = $m[1];
                }

                $errorMsg = ($response->status() === 403)
                    ? 'Akses katalog OData ditolak oleh firewall CDSE WAF (HTTP 403' . ($refId ? ", Ref ID: {$refId}" : '') . ').'
                    : "Copernicus OData Catalog error: HTTP {$response->status()}";

                $provider->update([
                    'health_status' => ($response->status() === 403) ? 'catalog_access_failed' : 'error',
                    'last_error_message' => $errorMsg,
                ]);

                throw new \Exception($errorMsg);
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            Log::error('Copernicus SAR Sync Error: ' . $this->sanitizeMessage($e->getMessage()));

            $run->update([
                'status' => 'failed',
                'error_message' => $this->sanitizeMessage($e->getMessage()),
                'completed_at' => now(),
            ]);
        }

        return [
            'status' => empty($errors) ? 'completed' : 'failed',
            'dry_run' => $dryRun,
            'fetched' => $fetched,
            'inserted' => $inserted,
            'planned_insert' => $plannedInsert,
            'skipped' => $skipped,
            'new_products' => $newProducts,
            'estimated_download_mb' => round($totalEstimatedBytes / (1024 * 1024), 2),
            'database_writes' => $dryRun ? 0 : $inserted,
            'errors' => $errors,
        ];
    }

    /**
     * Update DataProvider record in database.
     */
    protected function updateProviderStatus(string $status, ?string $error = null, ?float $latency = null, ?array $extraInfo = null): void
    {
        $rateLimitInfo = $extraInfo ?? [];
        if ($latency !== null) {
            $rateLimitInfo['latency_ms'] = $latency;
        }

        DataProvider::updateOrCreate(
            ['slug' => 'copernicus-sentinel-1'],
            [
                'name' => 'Copernicus Data Space Ecosystem (Sentinel-1 SAR)',
                'type' => 'copernicus_sar',
                'api_url' => $this->baseUrl,
                'auth_type' => 'oauth2',
                'is_enabled' => true,
                'health_status' => $status,
                'last_health_check_at' => now(),
                'last_error_message' => $error,
                'license_type' => 'Copernicus Open Access',
                'commercial_use_allowed' => true,
                'rate_limit_info' => !empty($rateLimitInfo) ? $rateLimitInfo : null,
            ]
        );
    }

    /**
     * Sanitize sensitive credentials from log and error messages.
     */
    public function sanitizeMessage(string $msg): string
    {
        $secrets = array_filter([
            $this->password,
            $this->clientSecret,
            $this->totp,
        ]);

        foreach ($secrets as $secret) {
            if (strlen((string) $secret) > 3) {
                $msg = str_replace($secret, '[REDACTED]', $msg);
            }
        }

        $msg = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [REDACTED]', $msg);
        $msg = preg_replace('/password=[^\s&]+/i', 'password=[REDACTED]', $msg);
        $msg = preg_replace('/client_secret=[^\s&]+/i', 'client_secret=[REDACTED]', $msg);
        $msg = preg_replace('/totp=[^\s&]+/i', 'totp=[REDACTED]', $msg);

        return $msg;
    }

    public function getMetadata(): array
    {
        return [
            'provider' => 'European Space Agency (ESA) Copernicus Data Space',
            'mission' => 'Sentinel-1 C-Band SAR',
            'configured' => $this->isAuthConfigured(),
            'auth_mode' => $this->authMode,
            'commercial_license' => true,
            'terms_url' => 'https://dataspace.copernicus.eu/terms-and-conditions',
            'notice' => 'Copernicus Sentinel data is made available under full, free, and open access terms.',
        ];
    }
}
