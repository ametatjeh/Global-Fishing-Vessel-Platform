<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeprecatedFishingGroundsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_fishing_grounds_url_returns_http_410_gone_for_guest(): void
    {
        $response = $this->get('/fishing-grounds');

        $response->assertStatus(410);
        $response->assertSee('410');
    }

    public function test_fishing_grounds_url_returns_http_410_gone_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->get('/fishing-grounds');

        $response->assertStatus(410);
        $response->assertSee('410');
    }

    public function test_fishing_grounds_any_http_method_returns_410(): void
    {
        $postResponse = $this->post('/fishing-grounds');
        $postResponse->assertStatus(410);
    }

    public function test_sidebar_navigation_does_not_contain_fishing_grounds_link(): void
    {
        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertDontSee('/fishing-grounds');
        $response->assertDontSee('Fishing Grounds (FAO/EEZ)');
        $response->assertDontSee('Fishing Ground (FAO/EEZ)');
    }

    public function test_core_platform_pages_remain_functional_after_fishing_grounds_removal(): void
    {
        $routes = [
            '/dashboard',
            '/map',
            '/vessels',
            '/encounters',
            '/dark-vessels',
            '/compliance',
            '/investigations',
            '/providers',
            '/settings',
            '/audit-logs',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($this->superAdmin)->get($route);
            $response->assertStatus(200);
        }
    }
}
