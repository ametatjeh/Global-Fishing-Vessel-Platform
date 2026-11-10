<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_guest_cannot_access_user_settings(): void
    {
        $response = $this->get('/settings/profile');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_user_settings(): void
    {
        $user = User::factory()->create([
            'name' => 'Kapten Laut Analyst',
            'email' => 'kapten@maritime-intel.org',
            'role' => 'analyst',
        ]);

        $response = $this->actingAs($user)->get('/settings/profile');

        $response->assertStatus(200);
        $response->assertSee('User Profile Settings');
        $response->assertSee('Kapten Laut Analyst');
        $response->assertSee('kapten@maritime-intel.org');
        $response->assertSee('Profile Photo');
    }

    public function test_user_can_update_profile_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'operator@maritime.org',
        ]);

        $response = $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Updated Captain Name',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Profile name updated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Captain Name',
        ]);
    }

    public function test_user_cannot_update_name_with_empty_or_short_value(): void
    {
        $user = User::factory()->create([
            'name' => 'Valid Operator',
        ]);

        $response = $this->actingAs($user)->patch('/settings/profile', [
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertEquals('Valid Operator', $user->fresh()->name);
    }

    public function test_user_can_upload_valid_avatar_image(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('captain_profile.jpg', 300, 300);

        $response = $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Profile photo updated successfully.');

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
        $this->assertStringContainsString('avatars/user_' . $user->id, $user->avatar_path);
        $this->assertStringContainsString('/storage/' . $user->avatar_path, $user->avatar_url);
    }

    public function test_user_can_upload_valid_png_and_webp_avatar_image(): void
    {
        $user = User::factory()->create();

        // PNG test
        $png = UploadedFile::fake()->image('profile.png', 200, 200);
        $resPng = $this->actingAs($user)->post('/settings/profile/avatar', ['avatar' => $png]);
        $resPng->assertRedirect();
        $user->refresh();
        $this->assertStringEndsWith('.png', $user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);

        // WebP test
        $webp = UploadedFile::fake()->image('profile.webp', 200, 200);
        $resWebp = $this->actingAs($user)->post('/settings/profile/avatar', ['avatar' => $webp]);
        $resWebp->assertRedirect();
        $user->refresh();
        $this->assertStringEndsWith('.webp', $user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_user_can_update_profile_and_avatar_via_main_form(): void
    {
        $user = User::factory()->create(['name' => 'Original Captain']);

        $file = UploadedFile::fake()->image('main_form_avatar.jpg', 250, 250);

        $response = $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Renamed Captain',
            'avatar' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Profile details and photo updated successfully.');

        $user->refresh();
        $this->assertEquals('Renamed Captain', $user->name);
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_user_cannot_modify_other_user_avatar(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $file = UploadedFile::fake()->image('user_a.jpg', 200, 200);
        $this->actingAs($userA)->post('/settings/profile/avatar', ['avatar' => $file]);

        $userA->refresh();
        $userB->refresh();

        $this->assertNotNull($userA->avatar_path);
        $this->assertNull($userB->avatar_path);
    }

    public function test_avatar_retains_after_reloading_profile_view(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('profile_persist.jpg', 200, 200);
        $this->actingAs($user)->post('/settings/profile/avatar', ['avatar' => $file]);
        $user->refresh();

        $response = $this->actingAs($user)->get('/settings/profile');
        $response->assertStatus(200);
        $response->assertSee($user->avatar_path);
        $response->assertSee('Custom Photo Active');
    }

    public function test_avatar_upload_rejects_pdf_file(): void
    {
        $user = User::factory()->create();
        $pdf = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => $pdf,
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_upload_rejects_oversized_file(): void
    {
        $user = User::factory()->create();

        // 3 MB file exceeds 2048 KB limit
        $oversizedFile = UploadedFile::fake()->image('large.jpg')->size(3072);

        $response = $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => $oversizedFile,
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_upload_rejects_disallowed_and_executable_formats(): void
    {
        $user = User::factory()->create();

        // Attempt upload of text/php file
        $maliciousFile = UploadedFile::fake()->create('exploit.php', 10, 'text/x-php');

        $response = $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => $maliciousFile,
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_upload_rejects_spoofed_non_image_content(): void
    {
        $user = User::factory()->create();

        // File with .png extension but plain text content
        $fakeImage = UploadedFile::fake()->createWithContent('corrupted.png', 'NOT_AN_IMAGE_CONTENT');

        $response = $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => $fakeImage,
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_old_avatar_file_is_pruned_when_replaced(): void
    {
        $user = User::factory()->create();

        // First upload
        $file1 = UploadedFile::fake()->image('avatar1.jpg', 200, 200);
        $this->actingAs($user)->post('/settings/profile/avatar', ['avatar' => $file1]);
        $user->refresh();
        $firstPath = $user->avatar_path;
        Storage::disk('public')->assertExists($firstPath);

        // Second upload
        $file2 = UploadedFile::fake()->image('avatar2.png', 200, 200);
        $this->actingAs($user)->post('/settings/profile/avatar', ['avatar' => $file2]);
        $user->refresh();
        $secondPath = $user->avatar_path;

        // Verify old file was deleted and new file exists
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_user_can_delete_avatar_and_revert_to_default(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('to_delete.jpg', 200, 200);
        $this->actingAs($user)->post('/settings/profile/avatar', ['avatar' => $file]);
        $user->refresh();
        $uploadedPath = $user->avatar_path;
        Storage::disk('public')->assertExists($uploadedPath);

        // Delete avatar
        $response = $this->actingAs($user)->delete('/settings/profile/avatar');
        $response->assertRedirect();
        $response->assertSessionHas('status');

        $user->refresh();
        $this->assertNull($user->avatar_path);
        Storage::disk('public')->assertMissing($uploadedPath);
        $this->assertStringContainsString('default-avatar.svg', $user->avatar_url);
    }
}

