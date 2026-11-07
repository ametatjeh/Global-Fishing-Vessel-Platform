<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_password_update_endpoint(): void
    {
        $response = $this->patch('/settings/password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'google_id' => null,
        ]);

        $response = $this->actingAs($user)->patch('/settings/password', [
            'current_password' => 'WrongPassword123!',
            'password' => 'NewValidPassword123!',
            'password_confirmation' => 'NewValidPassword123!',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('CorrectPassword123!', $user->fresh()->password));
        $this->assertFalse(Hash::check('NewValidPassword123!', $user->fresh()->password));
    }

    public function test_mismatched_password_confirmation_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'google_id' => null,
        ]);

        $response = $this->actingAs($user)->patch('/settings/password', [
            'current_password' => 'CorrectPassword123!',
            'password' => 'NewValidPassword123!',
            'password_confirmation' => 'MismatchPassword123!',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('CorrectPassword123!', $user->fresh()->password));
    }

    public function test_short_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
            'google_id' => null,
        ]);

        $response = $this->actingAs($user)->patch('/settings/password', [
            'current_password' => 'CorrectPassword123!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('CorrectPassword123!', $user->fresh()->password));
    }

    public function test_valid_new_password_is_successfully_saved_as_hash(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OriginalPassword123!'),
            'google_id' => null,
        ]);

        $response = $this->actingAs($user)->patch('/settings/password', [
            'current_password' => 'OriginalPassword123!',
            'password' => 'BrandNewPassword123!',
            'password_confirmation' => 'BrandNewPassword123!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Kata sandi berhasil diperbarui.');

        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword123!', $user->password));
        $this->assertFalse(Hash::check('OriginalPassword123!', $user->password));
        // Ensure not saved in plain text
        $this->assertNotEquals('BrandNewPassword123!', $user->password);
        $this->assertNotNull($user->password_set_at);
    }

    public function test_user_can_login_with_new_password_and_old_password_fails(): void
    {
        $user = User::factory()->create([
            'email' => 'officer@maritime-ops.org',
            'password' => Hash::make('OldSecretPass123!'),
            'google_id' => null,
        ]);

        // Change password
        $this->actingAs($user)->patch('/settings/password', [
            'current_password' => 'OldSecretPass123!',
            'password' => 'NewSecretPass456!',
            'password_confirmation' => 'NewSecretPass456!',
        ]);

        // Logout
        $this->post('/logout');
        $this->assertGuest();

        // 1. Attempt login with old password -> must fail
        $failedLogin = $this->post('/login', [
            'email' => 'officer@maritime-ops.org',
            'password' => 'OldSecretPass123!',
        ]);
        $failedLogin->assertSessionHasErrors('email');
        $this->assertGuest();

        // 2. Attempt login with new password -> must succeed
        $successfulLogin = $this->post('/login', [
            'email' => 'officer@maritime-ops.org',
            'password' => 'NewSecretPass456!',
        ]);
        $successfulLogin->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_sso_user_without_local_password_can_establish_local_password(): void
    {
        $googleUser = User::create([
            'name' => 'SSO Navigator',
            'email' => 'navigator@gmail.com',
            'google_id' => 'google-oauth-unique-9988',
            'role' => 'analyst',
            'organization' => 'External / Google SSO',
            'password' => Hash::make('SystemGeneratedRandom32CharsLongString'),
            'password_set_at' => null,
            'is_active' => true,
        ]);

        $this->assertFalse($googleUser->hasSetPassword());

        // Can establish local password without current_password
        $response = $this->actingAs($googleUser)->patch('/settings/password', [
            'password' => 'CustomLocalPassword123!',
            'password_confirmation' => 'CustomLocalPassword123!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Kata sandi lokal berhasil dibuat. Anda kini dapat masuk menggunakan email & kata sandi selain Google SSO.');

        $googleUser->refresh();
        $this->assertTrue($googleUser->hasSetPassword());
        $this->assertNotNull($googleUser->password_set_at);
        $this->assertTrue(Hash::check('CustomLocalPassword123!', $googleUser->password));

        // Now that local password is set, changing it again requires current_password
        $attemptWithoutCurrent = $this->actingAs($googleUser)->patch('/settings/password', [
            'password' => 'AnotherNewPass123!',
            'password_confirmation' => 'AnotherNewPass123!',
        ]);
        $attemptWithoutCurrent->assertSessionHasErrors('current_password');
    }

    public function test_user_cannot_change_another_users_password(): void
    {
        $userA = User::factory()->create([
            'email' => 'user_a@intel.org',
            'password' => Hash::make('UserAPassword123!'),
        ]);

        $userB = User::factory()->create([
            'email' => 'user_b@intel.org',
            'password' => Hash::make('UserBPassword123!'),
        ]);

        // User A submits request
        $response = $this->actingAs($userA)->patch('/settings/password', [
            'current_password' => 'UserAPassword123!',
            'password' => 'UserANewPassword123!',
            'password_confirmation' => 'UserANewPassword123!',
        ]);

        $response->assertRedirect();

        // User A's password changed
        $this->assertTrue(Hash::check('UserANewPassword123!', $userA->fresh()->password));

        // User B's password is completely untouched
        $this->assertTrue(Hash::check('UserBPassword123!', $userB->fresh()->password));
        $this->assertFalse(Hash::check('UserANewPassword123!', $userB->fresh()->password));
    }

    public function test_passwords_are_never_reflashed_into_session_on_validation_failure(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OriginalPassword123!'),
        ]);

        $response = $this->actingAs($user)->patch('/settings/password', [
            'current_password' => 'OriginalPassword123!',
            'password' => 'Mismatch123!',
            'password_confirmation' => 'Different123!',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->assertNull(session()->getOldInput('current_password'));
    }

    public function test_user_settings_page_renders_password_form(): void
    {
        $user = User::factory()->create([
            'name' => 'Kapten Penguji',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->actingAs($user)->get('/settings/profile');

        $response->assertStatus(200);
        $response->assertSee('Ubah Kata Sandi');
        $response->assertSee('Kata Sandi Saat Ini');
        $response->assertSee('Kata Sandi Baru');
        $response->assertSee('Konfirmasi Kata Sandi Baru');
    }
}

