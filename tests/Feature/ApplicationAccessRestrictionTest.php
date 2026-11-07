<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class ApplicationAccessRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $admin;
    protected User $analyst;
    protected User $deactivatedUser;
    protected User $legacyViewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'name' => 'Chief Operations Officer',
            'email' => 'superadmin@maritime-intel.org',
            'role' => 'super_admin',
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Systems Administrator',
            'email' => 'admin@maritime-intel.org',
            'role' => 'admin',
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        $this->analyst = User::factory()->create([
            'name' => 'Intelligence Analyst',
            'email' => 'analyst@maritime-intel.org',
            'role' => 'analyst',
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);

        $this->deactivatedUser = User::factory()->create([
            'name' => 'Deactivated Personnel',
            'email' => 'inactive@maritime-intel.org',
            'role' => 'analyst',
            'is_active' => false,
            'password' => Hash::make('Secret123!'),
        ]);

        $this->legacyViewer = User::factory()->create([
            'name' => 'Legacy Viewer User',
            'email' => 'viewer@maritime-intel.org',
            'role' => 'viewer',
            'is_active' => true,
            'password' => Hash::make('Secret123!'),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 1. Pengunjung tanpa login tidak dapat membuka dashboard.
     */
    public function test_guest_cannot_access_dashboard(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');

        $homeResponse = $this->get('/');
        $homeResponse->assertRedirect('/login');
    }

    /**
     * 2. Pengunjung tanpa login tidak dapat membuka halaman AIS.
     */
    public function test_guest_cannot_access_ais_tracking_page(): void
    {
        $response = $this->get('/tracking');
        $response->assertRedirect('/login');
    }

    /**
     * 3. Pengunjung tanpa login tidak dapat membuka halaman GFW.
     */
    public function test_guest_cannot_access_gfw_pages(): void
    {
        $vesselsResponse = $this->get('/vessels');
        $vesselsResponse->assertRedirect('/login');

        $encountersResponse = $this->get('/encounters');
        $encountersResponse->assertRedirect('/login');

        $complianceResponse = $this->get('/compliance');
        $complianceResponse->assertRedirect('/login');
    }

    /**
     * 4. Pengunjung tanpa login tidak dapat membuka halaman SAR.
     */
    public function test_guest_cannot_access_sar_pages(): void
    {
        $sarResponse = $this->get('/dark-vessels');
        $sarResponse->assertRedirect('/login');

        $candidatesResponse = $this->get('/sar/candidates');
        $candidatesResponse->assertRedirect('/login');
    }

    /**
     * 5. Pengunjung tanpa login tidak dapat mengakses endpoint API internal.
     */
    public function test_guest_cannot_access_internal_api_endpoints(): void
    {
        $layersResponse = $this->getJson('/api/layers');
        $layersResponse->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated: Pengguna belum terautentikasi.']);

        $vesselsResponse = $this->getJson('/api/layers/vessels');
        $vesselsResponse->assertStatus(401);

        $searchResponse = $this->getJson('/api/map/search?q=test');
        $searchResponse->assertStatus(401);
    }

    /**
     * 6. Pengguna terdaftar dan aktif dengan role sah dapat mengakses halaman sesuai permission.
     */
    public function test_active_user_with_valid_role_can_access_authorized_pages(): void
    {
        // Analyst has access to operational modules
        $this->actingAs($this->analyst)->get('/dashboard')->assertStatus(200);
        $this->actingAs($this->analyst)->get('/tracking')->assertStatus(200);
        $this->actingAs($this->analyst)->get('/vessels')->assertStatus(200);
        $this->actingAs($this->analyst)->get('/dark-vessels')->assertStatus(200);
        $this->actingAs($this->analyst)->get('/compliance')->assertStatus(200);
        $this->actingAs($this->analyst)->get('/investigations')->assertStatus(200);

        // Internal API access returns 200 for active analyst
        $apiResponse = $this->actingAs($this->analyst)->getJson('/api/layers/vessels');
        $apiResponse->assertStatus(200);

        // Admin has access to system management
        $this->actingAs($this->admin)->get('/settings')->assertStatus(200);
        $this->actingAs($this->admin)->get('/audit-logs')->assertStatus(200);
        $this->actingAs($this->admin)->get('/providers')->assertStatus(200);

        // Super Admin has access to everything including Admin Users
        $this->actingAs($this->superAdmin)->get('/admin/users')->assertStatus(200);
    }

    /**
     * 7. Pengguna tidak terdaftar ditolak.
     */
    public function test_unregistered_users_are_rejected(): void
    {
        // 7a. Normal password login with unregistered email
        $response = $this->post('/login', [
            'email' => 'unregistered.hacker@evil-corp.com',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // 7b. Google SSO with unregistered email does NOT create user and is rejected
        $googleUserMock = Mockery::mock('Laravel\Socialite\Two\User');
        $googleUserMock->shouldReceive('getId')->andReturn('google-unregistered-id-12345');
        $googleUserMock->shouldReceive('getName')->andReturn('Unregistered Google Visitor');
        $googleUserMock->shouldReceive('getEmail')->andReturn('unknown.person@gmail.com');

        $providerMock = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $providerMock->shouldReceive('user')->andReturn($googleUserMock);

        Socialite::shouldReceive('driver')->with('google')->andReturn($providerMock);

        $ssoResponse = $this->get('/auth/google/callback');
        $ssoResponse->assertRedirect('/login');
        $ssoResponse->assertSessionHas('error');
        $this->assertGuest();

        // Ensure user was NOT created in database
        $this->assertDatabaseMissing('users', ['email' => 'unknown.person@gmail.com']);
    }

    /**
     * 8. Pengguna yang dinonaktifkan ditolak.
     */
    public function test_deactivated_users_are_rejected(): void
    {
        // 8a. Login attempt rejected
        $response = $this->post('/login', [
            'email' => $this->deactivatedUser->email,
            'password' => 'Secret123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // 8b. Authenticated session rejected on web request
        $webResponse = $this->actingAs($this->deactivatedUser)->get('/dashboard');
        $webResponse->assertStatus(403);
        $this->assertGuest();

        // 8c. Authenticated session rejected on API request with 403
        $apiResponse = $this->actingAs($this->deactivatedUser)->getJson('/api/layers/vessels');
        $apiResponse->assertStatus(403);
    }

    /**
     * 9. Pengguna dengan role viewer ditolak.
     */
    public function test_users_with_role_viewer_are_rejected(): void
    {
        // 9a. Normal login rejected with clear role disabled message
        $response = $this->post('/login', [
            'email' => $this->legacyViewer->email,
            'password' => 'Secret123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // 9b. Direct navigation by session with role viewer returns 403
        $webResponse = $this->actingAs($this->legacyViewer)->get('/dashboard');
        $webResponse->assertStatus(403);
        $this->assertGuest();

        // 9c. Direct API access returns 403
        $apiResponse = $this->actingAs($this->legacyViewer)->getJson('/api/layers/vessels');
        $apiResponse->assertStatus(403);
    }

    /**
     * 10. Pengguna biasa tidak dapat mengakses Admin Users.
     */
    public function test_regular_users_cannot_access_admin_users(): void
    {
        // Analyst receives 403
        $this->actingAs($this->analyst)->get('/admin/users')->assertStatus(403);
        $this->actingAs($this->analyst)->get('/admin/users/create')->assertStatus(403);
        $this->actingAs($this->analyst)->post('/admin/users', [])->assertStatus(403);

        // System Admin (non-super-admin) receives 403
        $this->actingAs($this->admin)->get('/admin/users')->assertStatus(403);
        $this->actingAs($this->admin)->get("/admin/users/{$this->analyst->id}")->assertStatus(403);
    }

    /**
     * 11. Administrator (Super Admin) tetap dapat mengelola pengguna.
     */
    public function test_super_admin_can_manage_users(): void
    {
        // View listing
        $indexResponse = $this->actingAs($this->superAdmin)->get('/admin/users');
        $indexResponse->assertStatus(200);

        // Create new analyst user
        $createResponse = $this->actingAs($this->superAdmin)->post('/admin/users', [
            'name' => 'New Regional Analyst',
            'email' => 'new.regional@maritime-intel.org',
            'role' => 'analyst',
            'organization' => 'Regional Fisheries Commission',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'is_active' => '1',
        ]);

        $createResponse->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'new.regional@maritime-intel.org',
            'role' => 'analyst',
            'is_active' => true,
        ]);

        // Attempting to create user with role 'viewer' is rejected
        $createViewerResponse = $this->actingAs($this->superAdmin)->post('/admin/users', [
            'name' => 'Invalid Viewer Attempt',
            'email' => 'bad.viewer@maritime-intel.org',
            'role' => 'viewer',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $createViewerResponse->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'bad.viewer@maritime-intel.org']);

        // Toggle user status
        $newUser = User::where('email', 'new.regional@maritime-intel.org')->first();
        $this->actingAs($this->superAdmin)->post("/admin/users/{$newUser->id}/toggle-status");
        $newUser->refresh();
        $this->assertFalse($newUser->is_active);
    }

    /**
     * 12. Pengguna tidak dapat memberikan role admin kepada dirinya sendiri.
     */
    public function test_user_cannot_self_elevate_or_demote_illegally(): void
    {
        // 12a. Analyst cannot touch admin/users to change their role
        $this->actingAs($this->analyst)->patch("/admin/users/{$this->analyst->id}", [
            'name' => $this->analyst->name,
            'email' => $this->analyst->email,
            'role' => 'super_admin',
        ])->assertStatus(403);

        $this->analyst->refresh();
        $this->assertEquals('analyst', $this->analyst->role);

        // 12b. Super Admin cannot demote themselves
        $selfDemoteResponse = $this->actingAs($this->superAdmin)->patch("/admin/users/{$this->superAdmin->id}", [
            'name' => $this->superAdmin->name,
            'email' => $this->superAdmin->email,
            'role' => 'analyst',
            'is_active' => '1',
        ]);

        $selfDemoteResponse->assertSessionHasErrors('role');
        $this->superAdmin->refresh();
        $this->assertEquals('super_admin', $this->superAdmin->role);
    }

    /**
     * 13. Pengguna tidak dapat mengaktifkan akun yang dinonaktifkan melalui manipulasi request.
     */
    public function test_user_cannot_activate_account_without_authorization(): void
    {
        // Analyst cannot toggle status of deactivated user
        $response = $this->actingAs($this->analyst)
            ->post("/admin/users/{$this->deactivatedUser->id}/toggle-status");

        $response->assertStatus(403);
        $this->deactivatedUser->refresh();
        $this->assertFalse($this->deactivatedUser->is_active);

        // Deactivated user cannot toggle own status
        $selfResponse = $this->actingAs($this->deactivatedUser)
            ->post("/admin/users/{$this->deactivatedUser->id}/toggle-status");

        $selfResponse->assertStatus(403);
        $this->deactivatedUser->refresh();
        $this->assertFalse($this->deactivatedUser->is_active);
    }

    /**
     * 14. Login dan logout tetap berfungsi normal.
     */
    public function test_login_and_logout_work_normally(): void
    {
        // Login with valid credentials
        $loginResponse = $this->post('/login', [
            'email' => $this->analyst->email,
            'password' => 'Secret123!',
        ]);

        $loginResponse->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->analyst);

        // Logout
        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect('/login');
        $this->assertGuest();
    }
}

