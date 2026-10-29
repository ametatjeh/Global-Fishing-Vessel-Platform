<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserProfileController extends Controller
{
    /**
     * Show the user profile settings form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user profile basic information.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
        ]);

        $oldName = $user->name;
        $user->name = $validated['name'];
        $user->save();

        AuditLog::log('update_profile', 'user', (string) $user->id, [
            'old_name' => $oldName,
            'new_name' => $user->name,
            'email' => $user->email,
        ]);

        return back()->with('status', 'Nama profil berhasil diperbarui.');
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
            'avatar.required' => 'Pilih berkas foto terlebih dahulu.',
            'avatar.image' => 'Berkas harus berupa gambar valid (JPEG, PNG, atau WebP).',
            'avatar.mimes' => 'Format foto yang diizinkan hanya JPEG, PNG, dan WebP.',
            'avatar.max' => 'Ukuran foto tidak boleh melebihi 2 MB.',
        ]);

        $file = $request->file('avatar');

        // Rigorous server-side image validation (verify binary header & true MIME)
        $realPath = $file->getRealPath();
        $imageInfo = @getimagesize($realPath);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

        if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedMimes, true)) {
            return back()->withErrors(['avatar' => 'Berkas gambar tidak valid atau terkorupsi. Pastikan format file adalah JPEG, PNG, atau WebP.']);
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
            return back()->withErrors(['avatar' => 'Gagal menyimpan foto profil ke storage. Silakan coba kembali.']);
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

        return back()->with('status', 'Foto profil berhasil diperbarui.');
    }

    /**
     * Delete user avatar and revert to default.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            $oldPath = $user->avatar_path;
            $user->avatar_path = null;
            $user->save();

            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            AuditLog::log('delete_avatar', 'user', (string) $user->id, [
                'deleted_path' => $oldPath,
            ]);

            return back()->with('status', 'Foto profil telah dihapus dan dikembalikan ke avatar default.');
        }

        return back()->with('warning', 'Anda belum memiliki foto profil kustom.');
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
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'current_password.current_password' => 'Kata sandi saat ini tidak cocok dengan data kami.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.min' => 'Kata sandi baru minimal harus terdiri dari :min karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
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
            ? 'Kata sandi berhasil diperbarui.'
            : 'Kata sandi lokal berhasil dibuat. Anda kini dapat masuk menggunakan email & kata sandi selain Google SSO.';

        return back()->with('status', $message);
    }
}

