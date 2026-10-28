<?php

namespace App\Services\Providers;

use App\Models\DataProvider;
use App\Models\ProviderIngestionRun;
use App\Models\SatelliteScene;
use App\Models\SatelliteDetection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CopernicusSarAdapter implements ProviderAdapterInterface
{
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected string $baseUrl;
    protected string $authUrl;
    protected string $odataUrl;

    public function __construct()
    {
        $this->clientId = config('maritime.providers.copernicus_sar.client_id');
        $this->clientSecret = config('maritime.providers.copernicus_sar.client_secret');
        $this->baseUrl = config('maritime.providers.copernicus_sar.base_url', 'https://dataspace.copernicus.eu/analyse/apis');
        $this->authUrl = config('maritime.providers.copernicus_sar.auth_url', 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token');
        $this->odataUrl = config('maritime.providers.copernicus_sar.odata_url', 'https://catalogue.dataspace.copernicus.eu/odata/v1');
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function getAccessToken(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::remember('copernicus_cdse_oauth_token', 1500, function () {
            try {
                $response = Http::asForm()->timeout(15)->post($this->authUrl, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ]);

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('Copernicus CDSE OAuth2 Token Error: ' . $response->body());
                return null;
            } catch (\Throwable $e) {
                Log::error('Copernicus CDSE OAuth2 Exception: ' . $e->getMessage());
                return null;
            }
        });
    }

    public function healthCheck(): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'not_configured',
                'message' => 'Copernicus Data Space Ecosystem credentials (COPERNICUS_CLIENT_ID / SECRET) belum dikonfigurasi.',
                'latency_ms' => null,
                'timestamp' => now()->toISOString(),
            ];
        }

        $startTime = microtime(true);

        try {
            // 1. Verify OAuth2 Token acquisition from Keycloak
            $token = $this->getAccessToken();
            if (!$token) {
                $latency = round((microtime(true) - $startTime) * 1000, 2);
                $this->updateProviderStatus('error', 'Gagal memperoleh OAuth2 Bearer token dari Copernicus Keycloak.', $latency);

                return [
                    'status' => 'error',
                    'message' => 'Gagal memperoleh OAuth2 Bearer token dari Copernicus Keycloak (periksa client_id dan client_secret).',
                    'latency_ms' => $latency,
                    'timestamp' => now()->toISOString(),
                ];
            }

            // 2. Query OData catalog with Bearer token
            $response = Http::withToken($token)->timeout(15)->get("{$this->odataUrl}/Products?\$top=1");
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            if ($response->successful()) {
                $status = 'authenticated';
                $message = 'Koneksi ke Copernicus Data Space Ecosystem terverifikasi (OAuth2 Bearer token valid dan OData catalog aktif).';

                $this->updateProviderStatus($status, null, $latency);

                return [
                    'status' => $status,
                    'message' => $message,
                    'latency_ms' => $latency,
                    'timestamp' => now()->toISOString(),
                ];
            }

            $errorMsg = "Copernicus OData API merespons dengan status {$response->status()}";
            $this->updateProviderStatus('error', $errorMsg, $latency);

            return [
                'status' => 'error',
                'message' => $errorMsg,
                'latency_ms' => $latency,
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $this->updateProviderStatus('error', $e->getMessage(), $latency);

            return [
                'status' => 'error',
                'message' => 'Gagal menghubungi Copernicus API: ' . $e->getMessage(),
                'latency_ms' => $latency,
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    public function sync(array $options = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'not_configured',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Copernicus credentials are not configured.'],
            ];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'status' => 'failed',
                'fetched' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Failed to authenticate with Copernicus CDSE OAuth2 Keycloak.'],
            ];
        }

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
        ]);

        $fetched = 0;
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        try {
            // Query Sentinel-1 products from Copernicus catalogue API with Bearer token
            $filter = "Collection/Name eq 'SENTINEL-1' and Attributes/OData.CSC.StringAttribute/any(att:att/Name eq 'productType' and att/OData.CSC.StringAttribute/Value eq 'GRD')";

            $response = Http::withToken($token)->timeout(30)->get("{$this->odataUrl}/Products", [
                '$filter' => $filter,
                '$top' => $options['limit'] ?? 10,
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

                    $footprint = $prod['GeoFootprint'] ?? [
                        'type' => 'Polygon',
                        'coordinates' => [
                            [
                                [95.0, 5.0], [98.0, 5.0], [98.0, 7.0], [95.0, 7.0], [95.0, 5.0]
                            ]
                        ]
                    ];

                    $scene = SatelliteScene::create([
                        'scene_id' => $sceneId,
                        'platform_name' => 'Sentinel-1A',
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
                            'content_length' => $prod['ContentLength'] ?? null,
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
                ]);

                $provider->update([
                    'last_sync_at' => now(),
                    'health_status' => 'connected',
                ]);
            } else {
                throw new \Exception('Copernicus Catalog error: ' . $response->body());
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            Log::error('Copernicus SAR Sync Error: ' . $e->getMessage());

            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
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

    protected function updateProviderStatus(string $status, ?string $error = null, ?float $latency = null): void
    {
        DataProvider::updateOrCreate(
            ['slug' => 'copernicus-sentinel-1'],
            [
                'name' => 'Copernicus Data Space Ecosystem (Sentinel-1 SAR)',
                'type' => 'copernicus_sar',
                'api_url' => $this->baseUrl,
                'auth_type' => 'oauth2',
                'is_enabled' => $this->isConfigured(),
                'health_status' => $status,
                'last_health_check_at' => now(),
                'last_error_message' => $error,
                'license_type' => 'Copernicus Open Access',
                'commercial_use_allowed' => true,
                'rate_limit_info' => $latency ? ['latency_ms' => $latency] : null,
            ]
        );
    }

    public function getMetadata(): array
    {
        return [
            'provider' => 'European Space Agency (ESA) Copernicus Data Space',
            'mission' => 'Sentinel-1 C-Band SAR',
            'configured' => $this->isConfigured(),
            'commercial_license' => true,
            'terms_url' => 'https://dataspace.copernicus.eu/terms-and-conditions',
            'notice' => 'Copernicus Sentinel data is made available under full, free, and open access terms.',
        ];
    }
}
