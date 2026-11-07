<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $admin;
    protected User $analyst;
    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'name' => 'Chief Security Officer',
            'email' => 'superadmin@maritime-ops.org',
            'role' => 'super_admin',
            'organization' => 'Headquarters',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'name' => 'System Administrator',
            'email' => 'admin@maritime-ops.org',
            'role' => 'admin',
            'organization' => 'IT Operations',
            'is_active' => true,
        ]);

        $this->analyst = User::factory()->create([
            'name' => 'Intelligence Analyst',
            'email' => 'analyst@maritime-ops.org',
            'role' => 'analyst',
            'organization' => 'Fisheries Intelligence',
            'is_active' => true,
        ]);

        $this->viewer = User::factory()->create([
            'name' => 'Guest Port Observer',
            'email' => 'viewer@maritime-ops.org',
            'role' => 'viewer',
            'organization' => 'Port Authority',
            'is_active' => true,
        ]);
    }

    /**
     * Requirement 1: Super admin can open management pages.
     */
    public function test_super_admin_can_access_user_management_pages(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/users')
            ->assertStatus(200)
            ->assertSee('Manajemen Akun &amp; Hak Akses Pengguna', false)
            ->assertSee('Last Login')
            ->assertDontSee('Pencarian')
            ->assertDontSee('Cari &amp; Filter', false)
            ->assertSee('Tampilkan')
            ->assertSee('baris per halaman')
            ->assertSee('Menampilkan')
            ->assertSee('hasil')
            ->assertSee($this->analyst->email);

        // Verify per_page parameter functions
        $this->actingAs($this->superAdmin)
            ->get('/admin/users?per_page=10')
            ->assertStatus(200)
            ->assertSee('selected', false);

        $this->actingAs($this->superAdmin)
            ->get('/admin/users/create')
            ->assertStatus(200)
            ->assertSee('Tambah Akun Pengguna Baru');

        $this->actingAs($this->superAdmin)
            ->get("/admin/users/{$this->analyst->id}")
            ->assertStatus(200)
            ->assertSee($this->analyst->name);

        $this->actingAs($this->superAdmin)
            ->get("/admin/users/{$this->analyst->id}/edit")
            ->assertStatus(200)
            ->assertSee('Edit Akun');
    }

    /**
     * Requirement 2: Regular users do not see management menu in dashboard.
     */
    public function test_non_super_admins_do_not_see_management_menu(): void
    {
        $response = $this->actingAs($this->analyst)->get('/dashboard');
        $response->assertStatus(200);
        $response->assertDontSee('Manajemen Akun');

        // Decommissioned viewer role is rejected with 403
        $viewerResponse = $this->actingAs($this->viewer)->get('/dashboard');
        $viewerResponse->assertStatus(403);

        $adminResponse = $this->actingAs($this->admin)->get('/dashboard');
        $adminResponse->assertStatus(200);
        $adminResponse->assertDontSee('Manajemen Akun');
    }

    /**
     * Requirement 3: Unauthorized users receive 403 on direct URL access.
     */
    public function test_unauthorized_users_receive_403_on_management_endpoints(): void
    {
        $endpoints = [
            ['GET', '/admin/users'],
            ['GET', '/admin/users/create'],
            ['POST', '/admin/users'],
            ['GET', "/admin/users/{$this->analyst->id}"],
            ['GET', "/admin/users/{$this->analyst->id}/edit"],
            ['PATCH', "/admin/users/{$this->analyst->id}"],
            ['POST', "/admin/users/{$this->analyst->id}/toggle-status"],
            ['POST', "/admin/users/{$this->analyst->id}/reset-password"],
        ];

        foreach ([$this->admin, $this->analyst, $this->viewer] as $user) {
            foreach ($endpoints as [$method, $url]) {
                $response = $this->actingAs($user)->call($method, $url);
                $this->assertEquals(403, $response->getStatusCode(), "User {$user->role} was not rejected with 403 on {$method} {$url}");
            }
        }

        // Guests redirected to login
        auth()->logout();
        $this->get('/admin/users')->assertRedirect('/login');
    }

    /**
     * Requirement 4: Account creation validation and email uniqueness.
     */
    public function test_super_admin_can_create_user_with_unique_email_and_valid_roles(): void
    {
        // 1. Success creation
        $response = $this->actingAs($this->superAdmin)->post('/admin/users', [
            'name' => 'New Regional Officer',
            'email' => 'new.officer@maritime.org',
            'role' => 'analyst',
            'organization' => 'Western Fleet',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'is_active' => '1',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'new.officer@maritime.org',
            'name' => 'New Regional Officer',
            'role' => 'analyst',
            'is_active' => true,
        ]);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_user',
            'entity_type' => 'user',
        ]);

        // 2. Duplicate email rejected
        $dupResponse = $this->actingAs($this->superAdmin)->post('/admin/users', [
            'name' => 'Another Officer',
            'email' => 'new.officer@maritime.org',
            'role' => 'viewer',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);

        $dupResponse->assertSessionHasErrors('email');
    }

    /**
     * Requirement 5: Role modification by super admin.
     */
    public function test_super_admin_can_update_user_role_and_details(): void
    {
        $response = $this->actingAs($this->superAdmin)->patch("/admin/users/{$this->viewer->id}", [
            'name' => 'Promoted Analyst',
            'email' => 'promoted@maritime-ops.org',
            'role' => 'analyst',
            'organization' => 'Advanced Fisheries Monitoring',
            'is_active' => '1',
        ]);

        $response->assertRedirect();
        $this->viewer->refresh();
        $this->assertEquals('analyst', $this->viewer->role);
        $this->assertEquals('Promoted Analyst', $this->viewer->name);
        $this->assertEquals('promoted@maritime-ops.org', $this->viewer->email);
    }

    /**
     * Requirement 6: User cannot self-elevate role via manipulated request.
     */
    public function test_user_cannot_self_elevate_role(): void
    {
        // Analyst attempts to PATCH their own role to super_admin
        $response = $this->actingAs($this->analyst)->patch("/settings/profile", [
            'name' => 'Hacker Name',
            'role' => 'super_admin', // Attempted mass-assignment
        ]);

        $this->analyst->refresh();
        $this->assertEquals('analyst', $this->analyst->role);

        // Attempting to hit /admin/users/{id} as analyst
        $adminHit = $this->actingAs($this->analyst)->patch("/admin/users/{$this->analyst->id}", [
            'name' => 'Hacker Name',
            'email' => $this->analyst->email,
            'role' => 'super_admin',
        ]);
        $adminHit->assertStatus(403);
        $this->assertEquals('analyst', $this->analyst->fresh()->role);
    }

    /**
     * Requirement 7: Last active super admin cannot be deactivated or demoted.
     */
    public function test_last_active_super_admin_cannot_be_deactivated_or_demoted(): void
    {
        // $this->superAdmin is the ONLY super admin currently in the DB
        $this->assertEquals(1, User::where('role', 'super_admin')->where('is_active', true)->count());

        // Attempt to demote self
        $demoteResponse = $this->actingAs($this->superAdmin)->patch("/admin/users/{$this->superAdmin->id}", [
            'name' => $this->superAdmin->name,
            'email' => $this->superAdmin->email,
            'role' => 'admin', // Demotion
            'is_active' => '1',
        ]);
        $demoteResponse->assertSessionHasErrors('role');
        $this->assertEquals('super_admin', $this->superAdmin->fresh()->role);

        // Attempt to deactivate self
        $deactivateResponse = $this->actingAs($this->superAdmin)->post("/admin/users/{$this->superAdmin->id}/toggle-status");
        $deactivateResponse->assertSessionHas('error');
        $this->assertTrue($this->superAdmin->fresh()->is_active);

        // Even if a second super admin is created, but is inactive, the active one cannot be deactivated
        $inactiveSuperAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => false,
        ]);

        $secondDeactivate = $this->actingAs($this->superAdmin)->post("/admin/users/{$this->superAdmin->id}/toggle-status");
        $secondDeactivate->assertSessionHas('error');
        $this->assertTrue($this->superAdmin->fresh()->is_active);
    }

    /**
     * Requirement 8: Reset password does not leak credentials and updates hash.
     */
    public function test_super_admin_can_reset_password_safely(): void
    {
        $response = $this->actingAs($this->superAdmin)->post("/admin/users/{$this->analyst->id}/reset-password", [
            'password' => 'NewResetPassword789!',
            'password_confirmation' => 'NewResetPassword789!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $this->analyst->refresh();
        $this->assertTrue(Hash::check('NewResetPassword789!', $this->analyst->password));
        $this->assertNotEquals('NewResetPassword789!', $this->analyst->password);

        // Audit log recorded
        $log = AuditLog::where('action', 'admin_reset_password')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('NewResetPassword789!', json_encode($log->details));
    }

    /**
     * Requirement 9: Deactivated user cannot authenticate.
     */
    public function test_deactivated_user_cannot_login(): void
    {
        $target = User::factory()->create([
            'email' => 'deactivated@maritime.org',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'email' => 'deactivated@maritime.org',
            'password' => 'SecretPass123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Requirement 10: Toggle active status works properly for non-super-admin.
     */
    public function test_super_admin_can_toggle_user_status(): void
    {
        $this->assertTrue($this->analyst->is_active);

        $response = $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$this->analyst->id}/toggle-status");

        $response->assertRedirect();
        $this->assertFalse($this->analyst->fresh()->is_active);

        // Toggle back to active
        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$this->analyst->id}/toggle-status");

        $this->assertTrue($this->analyst->fresh()->is_active);
    }
}
