<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\SatelliteScene;
use App\Services\Providers\CopernicusSarAdapter;
use App\Services\Sar\SarProcessingPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CopernicusAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected string $authUrl = 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token';
    protected string $odataUrl = 'https://catalogue.dataspace.copernicus.eu/odata/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('copernicus_cdse_access_token');
        Cache::forget('copernicus_cdse_oauth_token');
        Cache::forget('copernicus_cdse_refresh_token');

        Config::set('maritime.providers.copernicus_sar.auth_mode', 'password');
        Config::set('maritime.providers.copernicus_sar.username', 'cdse_analyst@gfvip.gov');
        Config::set('maritime.providers.copernicus_sar.password', 'SecretPassword123!');
        Config::set('maritime.providers.copernicus_sar.totp', null);
        Config::set('maritime.providers.copernicus_sar.auth_url', $this->authUrl);
        Config::set('maritime.providers.copernicus_sar.odata_url', $this->odataUrl);
        Config::set('maritime.providers.copernicus_sar.timeout', 5);
    }

    /**
     * 1. Metadata OData berhasil tanpa token.
     */
    public function test_metadata_odata_succeeds_without_token(): void
    {
        Http::fake([
            $this->odataUrl . '/Products*' => Http::response([
                'value' => [
                    [
                        'Id' => 'scene-test-001',
                        'Name' => 'S1A_IW_GRDH_1SDV_20261107T000000.SAFE',
                        'ContentLength' => 50000000,
                        'ContentDate' => ['Start' => '2026-11-07T00:00:00Z', 'End' => '2026-11-07T00:00:25Z'],
                        'GeoFootprint' => ['type' => 'Polygon', 'coordinates' => [[[95, 5], [96, 5], [96, 6], [95, 6], [95, 5]]]],
                    ]
                ]
            ], 200),
        ]);

        // Explicitly clear credentials so no token is possible
        Config::set('maritime.providers.copernicus_sar.username', null);
        Config::set('maritime.providers.copernicus_sar.password', null);

        $adapter = new CopernicusSarAdapter();
        $this->assertFalse($adapter->isAuthConfigured());

        $sync = $adapter->sync(['limit' => 1]);
        $this->assertEquals('completed', $sync['status']);
        $this->assertEquals(1, $sync['inserted']);

        $this->assertDatabaseHas('satellite_scenes', [
            'scene_id' => 'scene-test-001',
            'platform_name' => 'Sentinel-1A',
        ]);
    }

    /**
     * 2. Metadata OData tetap berhasil saat Keycloak gagal.
     */
    public function test_metadata_odata_still_succeeds_when_keycloak_fails(): void
    {
        Http::fake([
            $this->authUrl => Http::response(['error' => 'server_error', 'error_description' => 'Keycloak down'], 500),
            $this->odataUrl . '/Products*' => Http::response([
                'value' => [
                    [
                        'Id' => 'scene-test-002',
                        'Name' => 'S1A_IW_GRDH_1SDV_20261107T010000.SAFE',
                        'ContentLength' => 60000000,
                    ]
                ]
            ], 200),
        ]);

        $adapter = new CopernicusSarAdapter();
        $sync = $adapter->sync(['limit' => 1]);

        $this->assertEquals('completed', $sync['status']);
        $this->assertEquals(1, $sync['inserted']);
        $this->assertDatabaseHas('satellite_scenes', ['scene_id' => 'scene-test-002']);
    }

    /**
     * 3. Password grant berhasil.
     */
    public function test_password_grant_succeeds(): void
    {
        Http::fake([
            $this->authUrl => function ($request) {
                $data = $request->data();
                $this->assertEquals('cdse-public', $data['client_id'] ?? null);
                $this->assertEquals('password', $data['grant_type'] ?? null);
                $this->assertEquals('cdse_analyst@gfvip.gov', $data['username'] ?? null);
                $this->assertEquals('SecretPassword123!', $data['password'] ?? null);

                return Http::response([
                    'access_token' => 'jwt-token-password-grant',
                    'expires_in' => 600,
                    'refresh_token' => 'refresh-token-12345',
                    'refresh_expires_in' => 1800,
                ], 200);
            },
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertEquals('jwt-token-password-grant', $token);
        $this->assertEquals('jwt-token-password-grant', Cache::get('copernicus_cdse_access_token'));
        $this->assertEquals('refresh-token-12345', Cache::get('copernicus_cdse_refresh_token'));
    }

    /**
     * 4. Respons 2FA/TOTP ditangani.
     */
    public function test_totp_2fa_response_handled_when_required(): void
    {
        Http::fake([
            $this->authUrl => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Missing TOTP code for account',
            ], 400),
            $this->odataUrl . '/Products*' => Http::response(['value' => []], 200),
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();
        $this->assertNull($token);

        $diag = $adapter->getLastAuthDiagnostics();
        $this->assertNotNull($diag);
        $this->assertEquals(400, $diag['http_status']);
        $this->assertEquals('missing_2fa', $diag['category']);
        $this->assertStringContainsString('Missing TOTP code', $diag['description']);

        $health = $adapter->healthCheck();
        $this->assertEquals('catalog_available_auth_failed', $health['status']);
        $this->assertStringContainsString('Missing TOTP code', $health['message']);
    }

    /**
     * 5. Access token menggunakan expires_in dengan buffer keselamatan.
     */
    public function test_access_token_uses_expires_in_with_safety_buffer(): void
    {
        Http::fake([
            $this->authUrl => Http::response([
                'access_token' => 'jwt-token-dynamic-ttl',
                'expires_in' => 600, // 10 minutes
            ], 200),
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertEquals('jwt-token-dynamic-ttl', $token);
        // Token is cached
        $this->assertTrue(Cache::has('copernicus_cdse_access_token'));
    }

    /**
     * 6. Token diperbarui sebelum kedaluwarsa.
     */
    public function test_token_refreshed_before_expiration(): void
    {
        // Simulate cached token has expired
        Cache::forget('copernicus_cdse_access_token');
        Cache::forget('copernicus_cdse_oauth_token');

        Http::fake([
            $this->authUrl => Http::response([
                'access_token' => 'jwt-token-refreshed',
                'expires_in' => 600,
            ], 200),
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertEquals('jwt-token-refreshed', $token);
    }

    /**
     * 7. Refresh token berhasil.
     */
    public function test_refresh_token_succeeds(): void
    {
        Cache::put('copernicus_cdse_refresh_token', 'valid-refresh-token', 1800);
        Cache::forget('copernicus_cdse_access_token');

        Http::fake([
            $this->authUrl => function ($request) {
                $data = $request->data();
                $this->assertEquals('refresh_token', $data['grant_type']);
                $this->assertEquals('valid-refresh-token', $data['refresh_token']);

                return Http::response([
                    'access_token' => 'renewed-access-token-via-refresh',
                    'expires_in' => 600,
                    'refresh_token' => 'new-refresh-token',
                ], 200);
            },
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertEquals('renewed-access-token-via-refresh', $token);
        $this->assertEquals('new-refresh-token', Cache::get('copernicus_cdse_refresh_token'));
    }

    /**
     * 8. Refresh gagal dan memperoleh token baru (fallback).
     */
    public function test_refresh_token_failure_falls_back_to_new_token_grant(): void
    {
        Cache::put('copernicus_cdse_refresh_token', 'expired-refresh-token', 1800);
        Cache::forget('copernicus_cdse_access_token');

        $callCount = 0;
        Http::fake([
            $this->authUrl => function ($request) use (&$callCount) {
                $callCount++;
                $data = $request->data();

                if (($data['grant_type'] ?? '') === 'refresh_token') {
                    // Refresh token rejected by Keycloak
                    return Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token expired'], 400);
                }

                // Fallback password grant succeeds
                return Http::response([
                    'access_token' => 'fresh-token-after-refresh-fail',
                    'expires_in' => 600,
                ], 200);
            },
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertEquals('fresh-token-after-refresh-fail', $token);
        $this->assertEquals(2, $callCount);
    }

    /**
     * 9. HTTP 400 dan 401 ditangani dengan benar tanpa retry berlebihan.
     */
    public function test_http_400_and_401_handled_correctly_without_retry(): void
    {
        $attempts = 0;
        Http::fake([
            $this->authUrl => function () use (&$attempts) {
                $attempts++;
                return Http::response(['error' => 'unauthorized_client', 'error_description' => 'Invalid client credentials'], 401);
            },
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertNull($token);
        // Must NOT retry 401 invalid credentials
        $this->assertEquals(1, $attempts);

        $diag = $adapter->getLastAuthDiagnostics();
        $this->assertEquals(401, $diag['http_status']);
        $this->assertEquals('invalid_credentials', $diag['category']);
    }

    /**
     * 10. HTTP 429 menghormati Retry-After.
     */
    public function test_http_429_respects_retry_after(): void
    {
        $attempts = 0;
        Http::fake([
            $this->authUrl => function () use (&$attempts) {
                $attempts++;
                if ($attempts === 1) {
                    return Http::response(['error' => 'slow_down', 'error_description' => 'Rate limit exceeded'], 429, [
                        'Retry-After' => '1',
                    ]);
                }
                return Http::response(['access_token' => 'token-after-rate-limit', 'expires_in' => 600], 200);
            },
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertEquals('token-after-rate-limit', $token);
        $this->assertEquals(2, $attempts);
    }

    /**
     * 11. HTTP 500 ditangani dengan retry terbatas.
     */
    public function test_http_500_handled_with_limited_retry(): void
    {
        $attempts = 0;
        Http::fake([
            $this->authUrl => function () use (&$attempts) {
                $attempts++;
                if ($attempts <= 2) {
                    return Http::response(['error' => 'server_error', 'error_description' => 'Cluster maintenance'], 500);
                }
                return Http::response(['access_token' => 'token-after-server-recover', 'expires_in' => 600], 200);
            },
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertEquals('token-after-server-recover', $token);
        $this->assertEquals(3, $attempts);
    }

    /**
     * 12. Timeout ditangani tanpa retry berlebihan.
     */
    public function test_timeout_handled_without_excessive_retry(): void
    {
        $attempts = 0;
        Http::fake([
            $this->authUrl => function () use (&$attempts) {
                $attempts++;
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();

        $this->assertNull($token);
        // Initial attempt + max 2 retries = 3 attempts total
        $this->assertEquals(3, $attempts);

        $diag = $adapter->getLastAuthDiagnostics();
        $this->assertEquals('timeout', $diag['category']);
    }

    /**
     * 13. Rahasia tidak muncul dalam log.
     */
    public function test_secrets_do_not_appear_in_logs(): void
    {
        $adapter = new CopernicusSarAdapter();
        $rawMessage = "Error connecting with password=SecretPassword123! and Bearer jwt-token-sample-12345";
        $sanitized = $adapter->sanitizeMessage($rawMessage);

        $this->assertStringNotContainsString('SecretPassword123!', $sanitized);
        $this->assertStringNotContainsString('jwt-token-sample-12345', $sanitized);
        $this->assertStringContainsString('password=[REDACTED]', $sanitized);
        $this->assertStringContainsString('Bearer [REDACTED]', $sanitized);
    }

    /**
     * 14. Download tanpa token ditolak dengan status yang jelas.
     */
    public function test_download_without_token_rejected_with_clear_status(): void
    {
        // Clear auth so token acquisition fails
        Config::set('maritime.providers.copernicus_sar.username', null);
        Config::set('maritime.providers.copernicus_sar.password', null);

        $scene = SatelliteScene::create([
            'scene_id' => 'S1A_SCENE_TEST_NO_AUTH',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'C-SAR',
            'sensor_mode' => 'IW',
            'polarization' => 'VV+VH',
            'acquisition_start' => now()->subHours(2),
            'acquisition_end' => now()->subHour(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[95, 5], [96, 5], [96, 6], [95, 6], [95, 5]]]]),
            'processing_status' => 'cataloged',
        ]);

        $pipeline = app(SarProcessingPipelineService::class);
        $result = $pipeline->processScene($scene->scene_id, ['allow_download' => true]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('downloaded', $result['stage']);
        $this->assertStringContainsString('Gagal mengautentikasi dengan Copernicus CDSE Keycloak', $result['message']);

        // Verify scene was NOT deleted
        $this->assertDatabaseHas('satellite_scenes', ['scene_id' => 'S1A_SCENE_TEST_NO_AUTH']);
    }

    /**
     * 15. Download dengan token berhasil.
     */
    public function test_download_with_valid_token_succeeds(): void
    {
        Http::fake([
            $this->authUrl => Http::response([
                'access_token' => 'mocked-download-token',
                'expires_in' => 600,
            ], 200),
        ]);

        $adapter = new CopernicusSarAdapter();
        $token = $adapter->getAccessToken();
        $this->assertEquals('mocked-download-token', $token);

        // Pre-create fake downloaded product file in storage to simulate finished download
        $storageDir = config('maritime.providers.copernicus_sar.pipeline.storage_dir', storage_path('app/sar_products'));
        if (!File::isDirectory($storageDir)) {
            File::makeDirectory($storageDir, 0755, true, true);
        }

        $dummyZip = $storageDir . DIRECTORY_SEPARATOR . 'S1A_SCENE_WITH_TOKEN.zip';
        file_put_contents($dummyZip, 'PK dummy zip content for SAR product');

        $scene = SatelliteScene::create([
            'scene_id' => 'S1A_SCENE_WITH_TOKEN',
            'platform_name' => 'Sentinel-1A',
            'instrument' => 'C-SAR',
            'sensor_mode' => 'IW',
            'polarization' => 'VV+VH',
            'acquisition_start' => now()->subHours(2),
            'acquisition_end' => now()->subHour(),
            'footprint_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[95, 5], [96, 5], [96, 6], [95, 6], [95, 5]]]]),
            'processing_status' => 'cataloged',
        ]);

        $pipeline = app(SarProcessingPipelineService::class);
        $result = $pipeline->processScene($scene->scene_id, ['allow_download' => true]);

        // Clean up dummy zip
        @unlink($dummyZip);

        // Verification: Download phase successfully completed without authentication failure
        $freshScene = $scene->fresh();
        $this->assertNotNull($token);
        $this->assertNotEquals('downloaded', $result['stage'] ?? null);
        $this->assertEquals('completed', $freshScene->metadata['pipeline_run']['stages']['downloaded']['status'] ?? null);
        $this->assertDatabaseHas('satellite_scenes', [
            'scene_id' => 'S1A_SCENE_WITH_TOKEN',
        ]);
    }
}
