<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('Global Fishing Vessel Tracking');
        $response->assertSee('Operational Authentication');
    }

    public function test_user_can_authenticate_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@maritime-intel.org',
            'password' => Hash::make('MaritimeSecret2026!'),
            'role' => 'analyst',
        ]);

        $response = $this->post('/login', [
            'email' => 'operator@maritime-intel.org',
            'password' => 'MaritimeSecret2026!',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    public function test_user_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@maritime-intel.org',
            'password' => Hash::make('CorrectPassword'),
        ]);

        $this->post('/login', [
            'email' => 'operator@maritime-intel.org',
            'password' => 'WrongPassword',
        ]);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }
}
