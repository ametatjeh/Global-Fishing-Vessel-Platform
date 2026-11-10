<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserProfileController extends Controller
{
    /**
     * Show the user profile settings and password management view.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,
            'hasPassword' => $user->hasSetPassword(),
        ]);
    }

    /**
     * Update user profile information (name and optional avatar).
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ], [
            'name.required' => 'Full name or operational callsign is required.',
            'name.min' => 'Name must be at least :min characters.',
            'avatar.image' => 'The file must be a valid image (JPEG, PNG, or WebP).',
            'avatar.mimes' => 'Only JPEG, PNG, and WebP image formats are permitted.',
            'avatar.max' => 'The image size may not exceed 2 MB.',
        ]);

        $oldName = $user->name;
        $user->name = $validated['name'];

        $avatarUpdated = false;
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $realPath = $file->getRealPath();
            $imageInfo = @getimagesize($realPath);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

            if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedMimes, true)) {
                return back()->withErrors(['avatar' => 'Image file is invalid or corrupted. Please ensure file format is JPEG, PNG, or WebP.']);
            }

            $ext = match ($imageInfo['mime']) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            $filename = 'user_' . $user->id . '_' . Str::random(24) . '.' . $ext;
            $newPath = $file->storeAs('avatars', $filename, 'public');

            if ($newPath) {
                $oldPath = $user->avatar_path;
                $user->avatar_path = $newPath;
                $avatarUpdated = true;

                if ($oldPath && $oldPath !== $newPath && Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }
        }

        $user->save();

        AuditLog::log('update_profile', 'user', (string) $user->id, [
            'old_name' => $oldName,
            'new_name' => $user->name,
            'email' => $user->email,
            'avatar_updated' => $avatarUpdated,
        ]);

        $statusMessage = $avatarUpdated
            ? 'Profile details and photo updated successfully.'
            : 'Profile name updated successfully.';

        return back()->with('status', $statusMessage);
    }

    /**
     * Upload and update user avatar image.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // 2 MB limit
            ],
        ], [
            'avatar.required' => 'Please select an image file first.',
            'avatar.image' => 'The file must be a valid image (JPEG, PNG, or WebP).',
            'avatar.mimes' => 'Only JPEG, PNG, and WebP image formats are permitted.',
            'avatar.max' => 'The image size may not exceed 2 MB.',
        ]);

        $file = $request->file('avatar');

        // Rigorous server-side image validation (verify binary header & true MIME)
        $realPath = $file->getRealPath();
        $imageInfo = @getimagesize($realPath);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

        if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedMimes, true)) {
            return back()->withErrors(['avatar' => 'Image file is invalid or corrupted. Please ensure file format is JPEG, PNG, or WebP.']);
        }

        // Determine sanitized extension
        $ext = match ($imageInfo['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        // Secure, randomized filename to prevent collisions and path traversal
        $filename = 'user_' . $user->id . '_' . Str::random(24) . '.' . $ext;
        $storageDir = 'avatars';

        // Store new avatar file to public disk
        $newPath = $file->storeAs($storageDir, $filename, 'public');

        if (!$newPath) {
            return back()->withErrors(['avatar' => 'Failed to save profile photo to storage. Please try again.']);
        }

        // Keep reference to old avatar to delete ONLY after successful replacement
        $oldPath = $user->avatar_path;

        $user->avatar_path = $newPath;
        $user->save();

        if ($oldPath && $oldPath !== $newPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        AuditLog::log('upload_avatar', 'user', (string) $user->id, [
            'avatar_path' => $newPath,
            'file_size' => $file->getSize(),
            'mime_type' => $imageInfo['mime'],
        ]);

        return back()->with('status', 'Profile photo updated successfully.');
    }

    /**
     * Delete user avatar and revert to default.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            $path = $user->avatar_path;
            $user->avatar_path = null;
            $user->save();

            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            AuditLog::log('delete_avatar', 'user', (string) $user->id, [
                'deleted_path' => $path,
            ]);

            return back()->with('status', 'Profile photo removed successfully. Reverted to default avatar.');
        }

        return back()->with('warning', 'You do not have a custom profile photo set.');
    }

    /**
     * Update user password or establish local password for Google SSO user.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        $hasSetPassword = $user->hasSetPassword();

        $rules = [
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ];

        $messages = [
            'current_password.required' => 'Current password is required.',
            'current_password.current_password' => 'The current password does not match our records.',
            'password.required' => 'New password is required.',
            'password.min' => 'New password must be at least :min characters.',
            'password.confirmed' => 'The new password confirmation does not match.',
        ];

        if ($hasSetPassword) {
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        $validated = $request->validate($rules, $messages);

        $user->password = Hash::make($validated['password']);
        $user->password_set_at = now();
        $user->save();

        $request->session()->regenerate();

        AuditLog::log($hasSetPassword ? 'update_password' : 'set_local_password', 'user', (string) $user->id, [
            'email' => $user->email,
            'action' => $hasSetPassword ? 'password_changed' : 'local_password_created',
        ]);

        $message = $hasSetPassword
            ? 'Password updated successfully.'
            : 'Local password created successfully. You can now sign in using email & password in addition to Google SSO.';

        return back()->with('status', $message);
    }
}
