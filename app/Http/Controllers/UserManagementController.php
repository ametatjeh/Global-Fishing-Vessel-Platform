<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    /**
     * Display a listing of all users for Super Admin.
     */
    public function index(Request $request): View
    {
        $query = User::query();

        // Search by name or email
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('organization', 'ilike', "%{$search}%");
            });
        }

        // Filter by role
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        // Filter by status (active / inactive)
        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $users = $query->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'super_admin' => User::where('role', 'super_admin')->count(),
            'admin' => User::where('role', 'admin')->count(),
            'analyst' => User::where('role', 'analyst')->count(),
            'researcher' => User::whereIn('role', ['research_lead', 'researcher', 'institutional_researcher'])->count(),
            'google_sso' => User::whereNotNull('google_id')->count(),
        ];

        return view('admin.users.index', compact('users', 'stats'));
    }

    /**
     * Show the form for creating a new user account.
     */
    public function create(): View
    {
        $roles = $this->getAvailableRoles();

        return view('admin.users.create', compact('roles'));
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:super_admin,admin,analyst,research_lead,researcher,institutional_researcher'],
            'organization' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'User name is required.',
            'name.min' => 'User name must be at least 2 characters.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Invalid email address format.',
            'email.unique' => 'Email address is already registered in the system.',
            'role.required' => 'User role must be selected.',
            'role.in' => 'The selected role is invalid or has been deprecated.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
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
            ->with('status', "User account {$user->name} ({$user->email}) created successfully.");
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

        return view('admin.users.show', compact('targetUser', 'recentLogs'));
    }

    /**
     * Show the form for editing an existing user account.
     */
    public function edit(int $id): View
    {
        $targetUser = User::findOrFail($id);
        $roles = $this->getAvailableRoles();

        return view('admin.users.edit', compact('targetUser', 'roles'));
    }

    /**
     * Get available user roles and their enterprise descriptions.
     */
    private function getAvailableRoles(): array
    {
        return [
            'super_admin' => 'Super Administrator (Full System Authority)',
            'admin' => 'Administrator (User & Operational Management)',
            'analyst' => 'Maritime Intelligence Analyst',
            'research_lead' => 'Research Lead (Private Investigations & Dossiers)',
            'researcher' => 'Maritime Researcher',
            'institutional_researcher' => 'Institutional Partner / External Researcher',
        ];
    }

    /**
     * Update an existing user in storage.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $targetUser = User::findOrFail($id);
        $currentUser = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $targetUser->id],
            'role' => ['required', 'string', 'in:super_admin,admin,analyst,research_lead,researcher,institutional_researcher'],
            'organization' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'User name is required.',
            'name.min' => 'User name must be at least 2 characters.',
            'email.required' => 'Email address is required.',
            'email.unique' => 'Email address is already in use by another account.',
            'role.required' => 'User role must be selected.',
            'role.in' => 'The selected role is invalid or has been deprecated.',
        ]);

        $newRole = $validated['role'];
        $newIsActive = $request->boolean('is_active');

        // Safety Rule 1: Self-demotion / Self-deactivation protection
        if ($targetUser->id === $currentUser->id) {
            if ($newRole !== 'super_admin') {
                return back()->withErrors(['role' => 'You cannot demote the Super Admin role for your own account.']);
            }
            if (!$newIsActive) {
                return back()->withErrors(['is_active' => 'You cannot deactivate your own account while signed in.']);
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
                    return back()->withErrors(['role' => 'Cannot demote the last remaining Super Admin in the system. At least one active Super Admin is required.']);
                }
                if (!$newIsActive) {
                    return back()->withErrors(['is_active' => 'Cannot deactivate the last remaining active Super Admin in the system.']);
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
            ->with('status', "Account details for {$targetUser->name} updated successfully.");
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
            return back()->with('error', 'You cannot deactivate your own account while signed in.');
        }

        // Last super admin check
        if ($targetUser->role === 'super_admin' && $targetUser->is_active) {
            $otherActiveSuperAdmins = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->where('id', '!=', $targetUser->id)
                ->count();

            if ($otherActiveSuperAdmins === 0) {
                return back()->with('error', 'Cannot deactivate the last remaining active Super Admin in the system.');
            }
        }

        $targetUser->is_active = !$targetUser->is_active;
        $targetUser->save();

        $actionText = $targetUser->is_active ? 'activated' : 'deactivated';

        AuditLog::log('toggle_user_status', 'user', (string) $targetUser->id, [
            'target_id' => $targetUser->id,
            'target_email' => $targetUser->email,
            'is_active' => $targetUser->is_active,
            'performed_by' => $currentUser->email,
        ]);

        return back()->with('status', "Account {$targetUser->name} ({$targetUser->email}) {$actionText} successfully.");
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
            'password.required' => 'New password is required.',
            'password.min' => 'New password must be at least 8 characters.',
            'password.confirmed' => 'New password confirmation does not match.',
        ]);

        $targetUser->password = Hash::make($validated['password']);
        $targetUser->password_set_at = now();
        $targetUser->save();

        AuditLog::log('admin_reset_password', 'user', (string) $targetUser->id, [
            'target_id' => $targetUser->id,
            'target_email' => $targetUser->email,
            'reset_by' => $currentUser->email,
        ]);

        return back()->with('status', "Password for user {$targetUser->name} reset successfully.");
    }
}
