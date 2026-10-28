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

        $userRole = $request->user()->role;

        // Administrative roles have full access
        if (in_array($userRole, ['super_admin', 'admin', 'administrator'], true)) {
            return $next($request);
        }

        if (!in_array($userRole, $roles, true)) {
            abort(403, 'Akses ditolak: Peran pengguna (' . $userRole . ') tidak memiliki otorisasi untuk tindakan operasional ini.');
        }

        return $next($request);
    }
}
