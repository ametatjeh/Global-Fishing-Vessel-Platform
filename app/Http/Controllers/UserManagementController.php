<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    /**
     * Allowed active system roles with clear privilege boundaries.
     * Role 'viewer' has been permanently decommissioned and disabled.
     */
    public const ROLES = [
        'super_admin' => 'Super Administrator (Akses Penuh)',
        'admin' => 'Administrator Sistem',
        'research_lead' => 'Research Lead / Principal Investigator',
        'researcher' => 'Maritime Researcher',
        'analyst' => 'Intelligence Analyst',
        'institutional_researcher' => 'Institutional / Partner Researcher',
    ];

    /**
     * Display a listing of user accounts with pagination.
     */
    public function index(Request $request): View
    {
        $perPage = (int) $request->query('per_page', 50);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 50;
        }

        $users = User::orderBy('id', 'desc')->paginate($perPage)->withQueryString();

        $stats = [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'super_admin' => User::where('role', 'super_admin')->where('is_active', true)->count(),
            'google_sso' => User::whereNotNull('google_id')->count(),
        ];

        return view('admin.users.index', [
            'users' => $users,
            'stats' => $stats,
            'roles' => self::ROLES,
        ]);
    }

    /**
     * Show form to create a new user account.
     */
    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => self::ROLES,
        ]);
    }

    /**
     * Store a newly created user account.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(array_keys(self::ROLES))],
            'organization' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Nama pengguna wajib diisi.',
            'name.min' => 'Nama pengguna minimal 2 karakter.',
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Alamat email sudah terdaftar dalam sistem.',
            'role.required' => 'Peran pengguna wajib dipilih.',
            'role.in' => 'Peran yang dipilih tidak valid atau telah dinonaktifkan (role viewer tidak lagi didukung).',
            'password.required' => 'Kata sandi wajib diisi.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'organization' => $validated['organization'] ?? 'Maritime Operations Center',
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
            'email_verified_at' => now(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::log('create_user', 'user', (string) $user->id, [
            'target_name' => $user->name,
            'target_email' => $user->email,
            'target_role' => $user->role,
            'is_active' => $user->is_active,
            'creator_id' => $request->user()->id,
            'creator_email' => $request->user()->email,
        ]);

        return redirect()->route('admin.users.show', $user->id)
            ->with('status', "Akun pengguna {$user->name} ({$user->email}) berhasil dibuat.");
    }

    /**
     * Display specific user account details and activity history.
     */
    public function show(int $id): View
    {
        $targetUser = User::findOrFail($id);

        $recentLogs = AuditLog::where('user_id', $targetUser->id)
            ->orWhere('entity_id', (string) $targetUser->id)
            ->latest()
            ->take(15)
            ->get();

        return view('admin.users.show', [
            'targetUser' => $targetUser,
            'roles' => self::ROLES,
            'recentLogs' => $recentLogs,
        ]);
    }

    /**
     * Show form to edit user account information.
     */
    public function edit(int $id): View
    {
        $targetUser = User::findOrFail($id);

        return view('admin.users.edit', [
            'targetUser' => $targetUser,
            'roles' => self::ROLES,
        ]);
    }

    /**
     * Update user account information, role, and active status.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $targetUser = User::findOrFail($id);
        $currentUser = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($targetUser->id)],
            'role' => ['required', 'string', Rule::in(array_keys(self::ROLES))],
            'organization' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Nama pengguna wajib diisi.',
            'name.min' => 'Nama pengguna minimal 2 karakter.',
            'email.required' => 'Alamat email wajib diisi.',
            'email.unique' => 'Alamat email telah digunakan akun lain.',
            'role.required' => 'Peran akun wajib dipilih.',
            'role.in' => 'Peran yang dipilih tidak valid atau telah dinonaktifkan (role viewer tidak lagi didukung).',
        ]);

        $newRole = $validated['role'];
        $newIsActive = $request->boolean('is_active');

        // Safety Rule 1: Self-demotion / Self-deactivation protection
        if ($targetUser->id === $currentUser->id) {
            if ($newRole !== 'super_admin') {
                return back()->withErrors(['role' => 'Anda tidak dapat menurunkan peran Super Admin untuk akun Anda sendiri.']);
            }
            if (!$newIsActive) {
                return back()->withErrors(['is_active' => 'Anda tidak dapat menonaktifkan akun Anda sendiri saat sedang masuk.']);
            }
        }

        // Safety Rule 2: Last Active Super Admin Protection
        if ($targetUser->role === 'super_admin') {
            $otherActiveSuperAdmins = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->where('id', '!=', $targetUser->id)
                ->count();

            if ($otherActiveSuperAdmins === 0) {
                if ($newRole !== 'super_admin') {
                    return back()->withErrors(['role' => 'Tidak dapat menurunkan peran Super Admin terakhir dalam sistem. Minimal harus ada satu Super Admin aktif.']);
                }
                if (!$newIsActive) {
                    return back()->withErrors(['is_active' => 'Tidak dapat menonaktifkan Super Admin terakhir yang masih aktif dalam sistem.']);
                }
            }
        }

        $oldData = [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'role' => $targetUser->role,
            'organization' => $targetUser->organization,
            'is_active' => $targetUser->is_active,
        ];

        $targetUser->name = $validated['name'];
        $targetUser->email = strtolower($validated['email']);
        $targetUser->role = $newRole;
        $targetUser->organization = $validated['organization'] ?? $targetUser->organization;
        $targetUser->is_active = $newIsActive;
        $targetUser->save();

        AuditLog::log('update_user', 'user', (string) $targetUser->id, [
            'target_id' => $targetUser->id,
            'target_email' => $targetUser->email,
            'old' => $oldData,
            'new' => [
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role,
                'organization' => $targetUser->organization,
                'is_active' => $targetUser->is_active,
            ],
            'updated_by' => $currentUser->email,
        ]);

        return redirect()->route('admin.users.show', $targetUser->id)
            ->with('status', "Data akun {$targetUser->name} berhasil diperbarui.");
    }

    /**
     * Toggle active/inactive status for a user.
     */
    public function toggleStatus(Request $request, int $id): RedirectResponse
    {
        $targetUser = User::findOrFail($id);
        $currentUser = $request->user();

        // Self-check
        if ($targetUser->id === $currentUser->id) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri saat sedang masuk.');
        }

        // Last super admin check
        if ($targetUser->role === 'super_admin' && $targetUser->is_active) {
            $otherActiveSuperAdmins = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->where('id', '!=', $targetUser->id)
                ->count();

            if ($otherActiveSuperAdmins === 0) {
                return back()->with('error', 'Tidak dapat menonaktifkan Super Admin terakhir yang masih aktif dalam sistem.');
            }
        }

        $targetUser->is_active = !$targetUser->is_active;
        $targetUser->save();

        $actionText = $targetUser->is_active ? 'diaktifkan' : 'dinonaktifkan';

        AuditLog::log('toggle_user_status', 'user', (string) $targetUser->id, [
            'target_id' => $targetUser->id,
            'target_email' => $targetUser->email,
            'is_active' => $targetUser->is_active,
            'performed_by' => $currentUser->email,
        ]);

        return back()->with('status', "Akun {$targetUser->name} ({$targetUser->email}) berhasil {$actionText}.");
    }

    /**
     * Reset password for an account directly by Super Admin.
     */
    public function resetPassword(Request $request, int $id): RedirectResponse
    {
        $targetUser = User::findOrFail($id);
        $currentUser = $request->user();

        $validated = $request->validate([
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ], [
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.min' => 'Kata sandi baru minimal harus 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
        ]);

        $targetUser->password = Hash::make($validated['password']);
        $targetUser->password_set_at = now();
        $targetUser->save();

        AuditLog::log('admin_reset_password', 'user', (string) $targetUser->id, [
            'target_id' => $targetUser->id,
            'target_email' => $targetUser->email,
            'reset_by' => $currentUser->email,
        ]);

        return back()->with('status', "Kata sandi untuk pengguna {$targetUser->name} berhasil direset.");
    }
}

