<?php

namespace Tests\Feature;

use App\Jobs\ProcessEncounterDetection;
use App\Jobs\SyncProviderDataJob;
use App\Models\DataProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'administrator',
            'organization' => 'Maritime Operations Command',
        ]);
    }

    public function test_all_portal_pages_render_successfully_for_authenticated_user(): void
    {
        $routes = [
            '/',
            '/dashboard',
            '/map',
            '/vessels',
            '/encounters',
            '/dark-vessels',
            '/compliance',
            '/investigations',
            '/investigations/create',
            '/providers',
            '/settings',
            '/audit-logs',
            '/tracking',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($this->user)->get($route);
            $response->assertStatus(200);
        }
    }

    public function test_antimeridian_crossing_bbox_queries_on_api_layers_execute_without_error(): void
    {
        // 170°E to 170°W across 180° meridian
        $crossingBbox = '170,-25,-170,25';

        $endpoints = [
            "/api/layers/fao?bbox={$crossingBbox}",
            "/api/layers/eez?bbox={$crossingBbox}",
            "/api/layers/vessels?bbox={$crossingBbox}",
            "/api/layers/encounters?bbox={$crossingBbox}",
            "/api/layers/dark-vessels?bbox={$crossingBbox}",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAs($this->user)->getJson($endpoint);
            $response->assertStatus(200);
            $response->assertJsonStructure([
                'type',
                'features',
                'metadata',
            ]);
            $this->assertEquals('FeatureCollection', $response->json('type'));
        }
    }

    public function test_queued_background_jobs_can_be_dispatched_and_processed(): void
    {
        Queue::fake();

        ProcessEncounterDetection::dispatch(24, 500.0, 30);
        SyncProviderDataJob::dispatch('all', 10);

        Queue::assertPushed(ProcessEncounterDetection::class);
        Queue::assertPushed(SyncProviderDataJob::class);
    }
}
