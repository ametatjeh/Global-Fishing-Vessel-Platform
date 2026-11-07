<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'organization', 'avatar_path', 'google_id', 'email_verified_at', 'password_set_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function assignedInvestigations(): HasMany
    {
        return $this->hasMany(Investigation::class, 'assigned_to_user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public const ROLE_PERMISSIONS = [
        'super_admin' => [
            'investigations.view',
            'investigations.create',
            'investigations.update',
            'investigations.export',
            'investigations.assign',
            'vessels.view',
            'vessels.enrich',
            'ownership.view',
            'ownership.manage',
            'eez.analysis.view',
            'iuu.analysis.view',
            'research.audit.view',
            'users.manage',
            'settings.manage',
        ],
        'admin' => [
            'users.manage',
            'research.audit.view',
            'settings.manage',
            'vessels.view',
            'investigations.view',
            'investigations.create',
            'investigations.update',
            'investigations.export',
        ],
        'research_lead' => [
            'investigations.view',
            'investigations.create',
            'investigations.update',
            'investigations.export',
            'investigations.assign',
            'vessels.view',
            'vessels.enrich',
            'ownership.view',
            'ownership.manage',
            'eez.analysis.view',
            'iuu.analysis.view',
            'research.audit.view',
        ],
        'researcher' => [
            'investigations.view',
            'investigations.create',
            'investigations.update',
            'investigations.export',
            'vessels.view',
            'vessels.enrich',
            'ownership.view',
            'eez.analysis.view',
            'iuu.analysis.view',
        ],
        'analyst' => [
            'investigations.view',
            'investigations.create',
            'investigations.update',
            'investigations.export',
            'vessels.view',
            'vessels.enrich',
            'ownership.view',
            'eez.analysis.view',
            'iuu.analysis.view',
        ],
        'institutional_researcher' => [
            'investigations.view',
            'investigations.update',
            'vessels.view',
            'ownership.view',
            'eez.analysis.view',
            'iuu.analysis.view',
        ],
    ];

    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin', 'administrator'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isResearchLead(): bool
    {
        return in_array($this->role, ['super_admin', 'research_lead'], true);
    }

    public function isResearcher(): bool
    {
        return in_array($this->role, ['super_admin', 'admin', 'administrator', 'research_lead', 'researcher', 'analyst', 'institutional_researcher'], true);
    }

    public function isInstitutionalResearcher(): bool
    {
        return $this->role === 'institutional_researcher';
    }

    public function isAnalyst(): bool
    {
        return in_array($this->role, ['super_admin', 'admin', 'administrator', 'analyst', 'research_lead', 'researcher', 'institutional_researcher'], true);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $normalizedRole = match ($this->role) {
            'administrator' => 'admin',
            default => $this->role,
        };

        return in_array($permission, self::ROLE_PERMISSIONS[$normalizedRole] ?? [], true);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Determine if the user has an established local password.
     */
    public function hasSetPassword(): bool
    {
        if (empty($this->google_id)) {
            return true;
        }

        return $this->password_set_at !== null;
    }

    /**
     * Resolves the profile avatar URL with safe default SVG fallback.
     */
    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar_path && Storage::disk('public')->exists($this->avatar_path)) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        return asset('assets/default-avatar.svg');
    }
}
