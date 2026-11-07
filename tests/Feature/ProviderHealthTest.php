<?php

namespace Tests\Feature;

use App\Models\DataProvider;
use App\Models\ProviderIngestionRun;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Providers\AisStreamAdapter;
use App\Services\Providers\CopernicusSarAdapter;
use App\Services\Providers\GlobalFishingWatchAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_providers_are_not_configured_when_api_keys_are_missing(): void
    {
        $provider = DataProvider::create([
            'name' => 'Global Fishing Watch',
            'slug' => 'global-fishing-watch',
            'type' => 'events_api',
            'health_status' => 'not_configured',
            'license_type' => 'Research / Non-Commercial',
            'commercial_use_allowed' => false,
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('not_configured', $result['status']);
        $this->assertStringContainsString('GFW API Token', $result['message']);
    }

    public function test_copernicus_sar_adapter_reports_not_configured_without_credentials(): void
    {
        $adapter = app(CopernicusSarAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('not_configured', $result['status']);
        $this->assertStringContainsString('Copernicus', $result['message']);
    }

    public function test_provider_management_page_displays_real_status_to_authenticated_users(): void
    {
        $user = User::factory()->create(['role' => 'administrator']);

        DataProvider::create([
            'name' => 'Copernicus Data Space Ecosystem (Sentinel-1 SAR)',
            'slug' => 'copernicus-sentinel-1',
            'type' => 'sar_satellite',
            'health_status' => 'not_configured',
            'license_type' => 'Copernicus Open Access / Legal Notice',
            'commercial_use_allowed' => true,
        ]);

        $response = $this->actingAs($user)->get('/providers');

        $response->assertStatus(200);
        $response->assertSee('Data Sources & Providers');
        $response->assertSee('Copernicus Data Space Ecosystem');
        $response->assertSee('Not Configured');
    }

    public function test_copernicus_oauth2_authentication_and_odata_sync(): void
    {
        config([
            'maritime.providers.copernicus_sar.auth_mode' => 'client_credentials',
            'maritime.providers.copernicus_sar.client_id' => 'test-client-id',
            'maritime.providers.copernicus_sar.client_secret' => 'test-client-secret',
            'maritime.providers.copernicus_sar.auth_url' => 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token',
            'maritime.providers.copernicus_sar.odata_url' => 'https://catalogue.dataspace.copernicus.eu/odata/v1',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://identity.dataspace.copernicus.eu/*' => \Illuminate\Support\Facades\Http::response([
                'access_token' => 'mocked-jwt-token',
                'expires_in' => 1800,
                'token_type' => 'Bearer',
            ], 200),
            'https://catalogue.dataspace.copernicus.eu/odata/v1/Products*' => \Illuminate\Support\Facades\Http::response([
                'value' => [
                    [
                        'Id' => 'scene-uuid-001',
                        'Name' => 'S1A_IW_GRDH_1SDV_20261019T000000.SAFE',
                        'ContentDate' => [
                            'Start' => '2026-10-19T00:00:00.000Z',
                            'End' => '2026-10-19T00:00:25.000Z',
                        ],
                        'GeoFootprint' => [
                            'type' => 'Polygon',
                            'coordinates' => [[[105.0, -5.0], [107.0, -5.0], [107.0, -3.0], [105.0, -3.0], [105.0, -5.0]]],
                        ],
                    ]
                ]
            ], 200),
        ]);

        $adapter = app(CopernicusSarAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('authenticated', $result['status']);
        $this->assertStringContainsString('OAuth2 Bearer token valid', $result['message']);

        // Test sync inserts scene
        $syncResult = $adapter->sync(['limit' => 1]);
        $this->assertEquals('completed', $syncResult['status']);
        $this->assertEquals(1, $syncResult['inserted']);

        $this->assertDatabaseHas('satellite_scenes', [
            'scene_id' => 'scene-uuid-001',
            'platform_name' => 'Sentinel-1A',
            'processing_status' => 'cataloged',
        ]);
    }

    public function test_ais_stream_status_distinguishes_receiving_data_and_stale(): void
    {
        config(['maritime.providers.ais_stream.api_key' => 'test-key']);

        $provider = DataProvider::create([
            'slug' => 'ais-stream',
            'name' => 'AIS Live Stream Telemetry',
            'type' => 'ais_stream',
            'health_status' => 'reachable',
        ]);

        $vessel = \App\Models\Vessel::create([
            'mmsi' => '525000999',
            'name' => 'TEST SHIP',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // 1. Create a stale position (> 2 hours ago)
        $stalePos = \App\Models\VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $provider->id,
            'latitude' => -6.0,
            'longitude' => 106.0,
            'observed_at' => now()->subHours(3),
            'received_at' => now()->subHours(3),
        ]);

        $this->assertFalse($stalePos->isFresh());

        // 2. Create fresh position (< 2 hours)
        $freshPos = \App\Models\VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $provider->id,
            'latitude' => -6.1,
            'longitude' => 106.1,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
        ]);

        $this->assertTrue($freshPos->isFresh());
    }

    public function test_gfw_events_sync_handles_403_permission_errors(): void
    {
        config(['maritime.providers.gfw.token' => 'secret-gfw-bearer-token-12345']);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/events*' => \Illuminate\Support\Facades\Http::response([
                'message' => 'Not authorized by permissions',
                'error' => 'Forbidden',
                'statusCode' => 403,
            ], 403),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $syncResult = $adapter->sync(['limit' => 5]);

        $this->assertEquals('failed', $syncResult['status']);
        $this->assertEquals('forbidden_research_required', $syncResult['error_type']);
        $this->assertEquals(0, $syncResult['inserted']);
        $this->assertEquals(0, \App\Models\VesselEncounter::count());
        $this->assertStringContainsString('Research Access required', $syncResult['errors'][0]);
        // Guarantee token is never leaked
        $this->assertStringNotContainsString('secret-gfw-bearer-token-12345', $syncResult['errors'][0]);
    }

    public function test_gfw_health_check_returns_research_access_required_on_403_events(): void
    {
        $testToken = 'gfw-test-jwt-token-alpha-omega-999';
        config(['maritime.providers.gfw.token' => $testToken]);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/datasets*' => \Illuminate\Support\Facades\Http::response([
                'entries' => [['id' => 'public-global-encounters-events:latest']],
                'total' => 1,
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => \Illuminate\Support\Facades\Http::response([
                'message' => 'Not authorized by permissions',
                'error' => 'Forbidden',
                'statusCode' => 403,
            ], 403),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('research_access_required', $result['status']);
        $this->assertStringContainsString('Access denied — Research Access required', $result['message']);
        $this->assertEquals('https://globalfishingwatch.org/our-apis/tokens', $result['guidance_url']);
        $this->assertStringNotContainsString($testToken, $result['message']);

        $provider = DataProvider::where('slug', 'global-fishing-watch')->first();
        $this->assertNotNull($provider);
        $this->assertEquals('research_access_required', $provider->health_status);
        $this->assertStringContainsString('Research Access permission required', $provider->last_error_message);
        $this->assertStringNotContainsString($testToken, $provider->last_error_message);
        $this->assertFalse($provider->commercial_use_allowed);
    }

    public function test_gfw_health_check_returns_unauthenticated_on_401(): void
    {
        $testToken = 'gfw-expired-invalid-token';
        config(['maritime.providers.gfw.token' => $testToken]);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/datasets*' => \Illuminate\Support\Facades\Http::response([
                'message' => 'Invalid or expired token',
                'statusCode' => 401,
            ], 401),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('unauthenticated', $result['status']);
        $this->assertStringContainsString('Autentikasi gagal (HTTP 401 Unauthorized)', $result['message']);
        $this->assertStringNotContainsString($testToken, $result['message']);

        $provider = DataProvider::where('slug', 'global-fishing-watch')->first();
        $this->assertEquals('unauthenticated', $provider->health_status);
    }

    public function test_gfw_health_check_returns_rate_limited_on_429(): void
    {
        config(['maritime.providers.gfw.token' => 'valid-token']);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/datasets*' => \Illuminate\Support\Facades\Http::response([
                'message' => 'Rate limit exceeded',
                'statusCode' => 429,
            ], 429),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('rate_limited', $result['status']);
        $this->assertStringContainsString('Batas laju permintaan', $result['message']);
    }

    public function test_gfw_health_check_returns_server_error_on_500(): void
    {
        config(['maritime.providers.gfw.token' => 'valid-token']);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/datasets*' => \Illuminate\Support\Facades\Http::response([
                'message' => 'Internal server error',
                'statusCode' => 500,
            ], 500),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('server_error', $result['status']);
        $this->assertStringContainsString('kesalahan internal pada server', $result['message']);
    }

    public function test_gfw_health_check_returns_network_error_on_timeout(): void
    {
        config(['maritime.providers.gfw.token' => 'valid-token']);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/datasets*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
            },
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('network_error', $result['status']);
        $this->assertStringContainsString('timeout atau jaringan terputus', $result['message']);
    }

    public function test_gfw_health_check_returns_receiving_data_when_events_present(): void
    {
        config(['maritime.providers.gfw.token' => 'valid-token']);

        $provider = DataProvider::create([
            'slug' => 'global-fishing-watch',
            'name' => 'Global Fishing Watch (Events API v3)',
            'type' => 'gfw_events',
            'health_status' => 'connected',
        ]);

        $vessel1 = Vessel::create(['mmsi' => '111222333', 'name' => 'BOAT 1']);
        $vessel2 = Vessel::create(['mmsi' => '444555666', 'name' => 'BOAT 2']);

        \App\Models\VesselEncounter::create([
            'encounter_code' => 'ENC-GFW-TEST01',
            'vessel_1_id' => $vessel1->id,
            'vessel_2_id' => $vessel2->id,
            'category' => 'fishing_carrier',
            'status' => 'candidate',
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHours(1),
            'duration_minutes' => 60,
            'min_distance_meters' => 250.0,
            'median_distance_meters' => 350.0,
            'latitude' => -5.5,
            'longitude' => 105.5,
            'parameters_used' => ['test' => true],
            'source_provider_id' => $provider->id,
            'created_at' => now()->subHours(2),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/datasets*' => \Illuminate\Support\Facades\Http::response([
                'entries' => [['id' => 'public-global-encounters-events:latest']],
                'total' => 1,
            ], 200),
            'https://gateway.api.globalfishingwatch.org/v3/events*' => \Illuminate\Support\Facades\Http::response([
                'entries' => [],
                'total' => 0,
            ], 200),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('receiving_data', $result['status']);
        $this->assertStringContainsString('aktif menerima event perikanan', $result['message']);
    }

    public function test_gfw_sanitize_error_message_scrubs_tokens_and_secrets(): void
    {
        $rawToken = 'eyJhGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.gfw-super-secret-user-token.signature999';
        config(['maritime.providers.gfw.token' => $rawToken]);

        $adapter = app(GlobalFishingWatchAdapter::class);

        $dirtyMessage1 = "Failed to connect with token {$rawToken} to gateway";
        $cleanMessage1 = $adapter->sanitizeErrorMessage($dirtyMessage1);
        $this->assertStringNotContainsString($rawToken, $cleanMessage1);
        $this->assertStringContainsString('[REDACTED_GFW_TOKEN]', $cleanMessage1);

        $dirtyMessage2 = "HTTP 401: Authorization Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.other.token rejected";
        $cleanMessage2 = $adapter->sanitizeErrorMessage($dirtyMessage2);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.other.token', $cleanMessage2);
        $this->assertStringContainsString('Bearer [REDACTED]', $cleanMessage2);

        $dirtyMessage3 = "URL: https://api.com?token=supersecret123&api_key=apikey999&limit=10";
        $cleanMessage3 = $adapter->sanitizeErrorMessage($dirtyMessage3);
        $this->assertStringNotContainsString('supersecret123', $cleanMessage3);
        $this->assertStringNotContainsString('apikey999', $cleanMessage3);
    }

    public function test_gfw_events_sync_distinguishes_empty_results_from_error(): void
    {
        config(['maritime.providers.gfw.token' => 'test-token']);

        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/events*' => \Illuminate\Support\Facades\Http::response([
                'entries' => [],
                'total' => 0,
            ], 200),
        ]);

        $adapter = app(GlobalFishingWatchAdapter::class);
        $syncResult = $adapter->sync(['limit' => 5]);

        $this->assertEquals('empty', $syncResult['status']);
        $this->assertEquals(0, $syncResult['fetched']);
        $this->assertEquals(0, $syncResult['inserted']);
        $this->assertEmpty($syncResult['errors']);
    }

    public function test_ais_stream_concurrency_lock_prevents_duplicate_workers(): void
    {
        config(['maritime.providers.ais_stream.api_key' => 'test-ais-key']);

        // Hold active worker lock
        $lock = \Illuminate\Support\Facades\Cache::lock('ais_stream_active_worker', 60);
        $this->assertTrue($lock->get());

        $adapter = app(\App\Services\Providers\AisStreamAdapter::class);
        $result = $adapter->consumeStream(1, 5);

        $this->assertEquals('busy', $result['status']);
        $this->assertStringContainsString('concurrency lock', $result['errors'][0]);

        $lock->release();
    }

    public function test_ais_stream_health_check_returns_not_configured_when_key_is_missing(): void
    {
        config(['maritime.providers.ais_stream.api_key' => null]);

        $adapter = app(AisStreamAdapter::class);
        $result = $adapter->healthCheck();

        $this->assertEquals('not_configured', $result['status']);
        $this->assertStringContainsString('AIS Stream API Key belum dikonfigurasi', $result['message']);
    }

    public function test_ais_stream_health_check_returns_unreachable_when_tls_fails(): void
    {
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-key']);

        $adapter = app(AisStreamAdapter::class);
        $result = $adapter->healthCheck(forceTlsConnected: false);

        $this->assertEquals('unreachable', $result['status']);
        $this->assertStringContainsString('Gagal membuka koneksi TLS', $result['message']);

        $provider = DataProvider::where('slug', 'ais-stream')->first();
        $this->assertEquals('unreachable', $provider->health_status);
    }

    public function test_ais_stream_health_check_returns_awaiting_telemetry_when_tls_connected_without_positions(): void
    {
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-key']);

        $adapter = app(AisStreamAdapter::class);
        $result = $adapter->healthCheck(forceTlsConnected: true);

        $this->assertEquals('awaiting_telemetry', $result['status']);
        $this->assertStringContainsString('Menunggu', $result['message']);

        $provider = DataProvider::where('slug', 'ais-stream')->first();
        $this->assertEquals('awaiting_telemetry', $provider->health_status);
    }

    public function test_ais_stream_health_check_returns_stale_when_latest_position_is_old(): void
    {
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-key']);

        $provider = DataProvider::create([
            'slug' => 'ais-stream',
            'name' => 'AIS Live Stream Telemetry',
            'type' => 'ais_stream',
            'health_status' => 'reachable',
        ]);

        $vessel = Vessel::create([
            'mmsi' => '525111222',
            'name' => 'KM HISTORIS',
            'first_seen_at' => now()->subHours(4),
            'last_seen_at' => now()->subHours(4),
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $provider->id,
            'latitude' => -5.5,
            'longitude' => 105.5,
            'observed_at' => now()->subHours(3),
            'received_at' => now()->subHours(3),
        ]);

        $adapter = app(AisStreamAdapter::class);
        $result = $adapter->healthCheck(forceTlsConnected: true);

        $this->assertEquals('stale', $result['status']);
        $this->assertStringContainsString('Telemetri AIS historis tersedia', $result['message']);

        $provider->refresh();
        $this->assertEquals('stale', $provider->health_status);
    }

    public function test_ais_stream_health_check_returns_streaming_when_position_is_fresh(): void
    {
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-key']);

        $provider = DataProvider::create([
            'slug' => 'ais-stream',
            'name' => 'AIS Live Stream Telemetry',
            'type' => 'ais_stream',
            'health_status' => 'reachable',
        ]);

        $vessel = Vessel::create([
            'mmsi' => '525333444',
            'name' => 'KM SEGAR',
            'first_seen_at' => now()->subMinutes(15),
            'last_seen_at' => now()->subMinutes(15),
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'source_provider_id' => $provider->id,
            'latitude' => -5.6,
            'longitude' => 105.6,
            'observed_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(10),
        ]);

        $adapter = app(AisStreamAdapter::class);
        $result = $adapter->healthCheck(forceTlsConnected: true);

        $this->assertEquals('streaming', $result['status']);
        $this->assertStringContainsString('aktif menerima pesan telemetri', $result['message']);

        $provider->refresh();
        $this->assertEquals('streaming', $provider->health_status);
    }

    public function test_ais_stream_health_check_detects_persistence_error(): void
    {
        config(['maritime.providers.ais_stream.api_key' => 'test-valid-key']);

        $provider = DataProvider::create([
            'slug' => 'ais-stream',
            'name' => 'AIS Live Stream Telemetry',
            'type' => 'ais_stream',
            'health_status' => 'reachable',
        ]);

        ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_ais',
            'status' => 'failed',
            'error_message' => 'SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "vessel_positions" does not exist',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
        ]);

        $adapter = app(AisStreamAdapter::class);
        $result = $adapter->healthCheck(forceTlsConnected: true);

        $this->assertEquals('persistence_error', $result['status']);
        $this->assertStringContainsString('Kegagalan penyimpanan database telemetri', $result['message']);

        $provider->refresh();
        $this->assertEquals('persistence_error', $provider->health_status);
    }

    public function test_sync_provider_job_isolates_gfw_failure_from_other_providers(): void
    {
        config(['maritime.providers.gfw.token' => 'test-token']);

        // Fake GFW to return 403 Forbidden
        \Illuminate\Support\Facades\Http::fake([
            'https://gateway.api.globalfishingwatch.org/v3/events*' => \Illuminate\Support\Facades\Http::response([
                'message' => 'Not authorized by permissions',
                'statusCode' => 403,
            ], 403),
        ]);

        // Running SyncProviderDataJob should catch GFW errors and complete gracefully without throwing
        $job = new \App\Jobs\SyncProviderDataJob(providerSlug: null);
        $job->handle(
            app(GlobalFishingWatchAdapter::class),
            app(CopernicusSarAdapter::class),
            app(AisStreamAdapter::class),
            app(\App\Services\Providers\FaoFishingAreaAdapter::class),
            app(\App\Services\Providers\MarineRegionsEezAdapter::class)
        );

        $provider = DataProvider::where('slug', 'global-fishing-watch')->first();
        $this->assertEquals('research_access_required', $provider->health_status);
    }
}
