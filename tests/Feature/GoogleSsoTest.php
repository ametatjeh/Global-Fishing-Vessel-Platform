<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_login_page_renders_google_sso_button(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Sign in with Google');
        $response->assertSee(route('auth.google.redirect'));
    }

    public function test_redirect_to_google_warns_when_credentials_unconfigured(): void
    {
        Config::set('services.google.client_id', null);
        Config::set('services.google.client_secret', null);

        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect('/login');
        $response->assertSessionHas('error');
    }

    public function test_redirect_to_google_redirects_to_oauth_provider(): void
    {
        Config::set('services.google.client_id', 'mock-client-id');
        Config::set('services.google.client_secret', 'mock-client-secret');

        $providerMock = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $providerMock->shouldReceive('scopes')->with(['openid', 'profile', 'email'])->andReturnSelf();
        $providerMock->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($providerMock);

        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_google_callback_rejects_unregistered_email_and_does_not_create_user(): void
    {
        $googleUserMock = Mockery::mock('Laravel\Socialite\Two\User');
        $googleUserMock->shouldReceive('getId')->andReturn('google-unique-id-998877');
        $googleUserMock->shouldReceive('getName')->andReturn('Maritime Observer Google');
        $googleUserMock->shouldReceive('getEmail')->andReturn('unregistered.visitor@maritime-intel.org');

        $providerMock = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $providerMock->shouldReceive('user')->andReturn($googleUserMock);

        Socialite::shouldReceive('driver')->with('google')->andReturn($providerMock);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/login');
        $response->assertSessionHas('error');
        $this->assertGuest();

        $this->assertDatabaseMissing('users', ['email' => 'unregistered.visitor@maritime-intel.org']);
    }

    public function test_google_callback_links_existing_user_without_creating_duplicate(): void
    {
        $existing = User::factory()->create([
            'email' => 'commander@maritime-intel.org',
            'role' => 'admin',
            'google_id' => null,
        ]);

        $googleUserMock = Mockery::mock('Laravel\Socialite\Two\User');
        $googleUserMock->shouldReceive('getId')->andReturn('google-id-commander-001');
        $googleUserMock->shouldReceive('getName')->andReturn('Commander Name');
        $googleUserMock->shouldReceive('getEmail')->andReturn('commander@maritime-intel.org');

        $providerMock = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $providerMock->shouldReceive('user')->andReturn($googleUserMock);

        Socialite::shouldReceive('driver')->with('google')->andReturn($providerMock);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($existing);

        // Verify count of users with this email is strictly 1 (no duplicate)
        $this->assertEquals(1, User::where('email', 'commander@maritime-intel.org')->count());
        $existing->refresh();
        $this->assertEquals('google-id-commander-001', $existing->google_id);
        $this->assertEquals('admin', $existing->role); // Preserves existing privileged role
    }

    public function test_google_callback_authenticates_existing_google_id_user(): void
    {
        $existing = User::factory()->create([
            'email' => 'operator1@maritime-intel.org',
            'google_id' => 'known-google-id-4455',
            'role' => 'analyst',
        ]);

        $googleUserMock = Mockery::mock('Laravel\Socialite\Two\User');
        $googleUserMock->shouldReceive('getId')->andReturn('known-google-id-4455');
        $googleUserMock->shouldReceive('getName')->andReturn('Operator Name');
        $googleUserMock->shouldReceive('getEmail')->andReturn('operator1@maritime-intel.org');

        $providerMock = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $providerMock->shouldReceive('user')->andReturn($googleUserMock);

        Socialite::shouldReceive('driver')->with('google')->andReturn($providerMock);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($existing);
    }

    public function test_google_callback_handles_oauth_cancellation_gracefully(): void
    {
        $response = $this->get('/auth/google/callback?error=access_denied');

        $response->assertRedirect('/login');
        $response->assertSessionHas('error');
        $this->assertGuest();
    }
}

