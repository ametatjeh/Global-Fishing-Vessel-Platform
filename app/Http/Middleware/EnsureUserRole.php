<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // 1. Authentication Check
        if (!$request->user()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated: Pengguna belum terautentikasi.'], 401);
            }
            return redirect()->route('login');
        }

        // 2. Active Account Check
        if ($request->user()->is_active === false) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Akses ditolak: Akun pengguna Anda telah dinonaktifkan oleh administrator.'], 403);
            }
            abort(403, 'Akses ditolak: Akun pengguna Anda telah dinonaktifkan oleh administrator.');
        }

        $userRole = $request->user()->role;
        $effectiveRole = match ($userRole) {
            'administrator' => 'admin',
            default => $userRole,
        };

        // 3. Decommissioned Role Check (Viewer is permanently disabled)
        if ($effectiveRole === 'viewer') {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Akses ditolak: Peran pengguna viewer telah dinonaktifkan secara permanen.'], 403);
            }
            abort(403, 'Akses ditolak: Peran pengguna viewer telah dinonaktifkan.');
        }

        // 4. Valid Active Role Verification (super_admin, admin, research_lead, researcher, analyst, institutional_researcher)
        if (!in_array($effectiveRole, ['super_admin', 'admin', 'analyst', 'research_lead', 'researcher', 'institutional_researcher'], true)) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Akses ditolak: Peran pengguna tidak sah.'], 403);
            }
            abort(403, 'Akses ditolak: Peran pengguna tidak sah.');
        }

        // 5. Super Admin has universal access across all modules
        if ($effectiveRole === 'super_admin') {
            return $next($request);
        }

        // 6. Role-specific or permission-specific privilege check if specific roles/permissions are required
        if (!empty($roles)) {
            $isAuthorized = false;
            foreach ($roles as $roleOrPermission) {
                if (str_starts_with($roleOrPermission, 'permission:')) {
                    $permission = substr($roleOrPermission, 11);
                    if ($request->user()->hasPermission($permission)) {
                        $isAuthorized = true;
                        break;
                    }
                } elseif ($effectiveRole === $roleOrPermission) {
                    $isAuthorized = true;
                    break;
                }
            }

            if (!$isAuthorized) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json(['message' => 'Akses ditolak: Peran pengguna (' . $userRole . ') tidak memiliki otorisasi untuk tindakan operasional ini.'], 403);
                }
                abort(403, 'Akses ditolak: Peran pengguna (' . $userRole . ') tidak memiliki otorisasi untuk tindakan operasional ini.');
            }
        }

        return $next($request);
    }
}
