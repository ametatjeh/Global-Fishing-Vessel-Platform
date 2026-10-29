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
        if (!$request->user()) {
            return redirect()->route('login');
        }

        if ($request->user()->is_active === false) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Akses ditolak: Akun pengguna Anda telah dinonaktifkan oleh administrator.');
        }

        $userRole = $request->user()->role;
        $effectiveRole = match ($userRole) {
            'administrator' => 'admin',
            default => $userRole,
        };

        // Super Admin has universal access
        if ($effectiveRole === 'super_admin') {
            return $next($request);
        }

        if (!in_array($effectiveRole, $roles, true)) {
            abort(403, 'Akses ditolak: Peran pengguna (' . $userRole . ') tidak memiliki otorisasi untuk tindakan operasional ini.');
        }

        return $next($request);
    }
}
