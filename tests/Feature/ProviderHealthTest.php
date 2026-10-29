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
        config(['maritime.providers.gfw.token' => 'test-token']);

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
        $this->assertEquals(0, $syncResult['inserted']);
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
}
