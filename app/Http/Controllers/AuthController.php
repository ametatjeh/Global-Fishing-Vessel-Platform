<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();

            if ($user->is_active === false) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => __('Akun ini telah dinonaktifkan oleh administrator. Silakan hubungi Super Admin.'),
                ]);
            }

            if ($user->role === 'viewer' || !in_array($user->role, ['super_admin', 'admin', 'analyst', 'research_lead', 'researcher', 'institutional_researcher'], true)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => __('Akses ditolak: Peran akun Anda (' . ($user->role ?? 'none') . ') telah dinonaktifkan. Silakan hubungi Super Administrator.'),
                ]);
            }

            $request->session()->regenerate();

            $authUser = Auth::user();
            $authUser->last_login_at = now();
            $authUser->save();

            AuditLog::log('login', 'user', (string) Auth::id(), [
                'email' => Auth::user()->email,
                'role' => Auth::user()->role,
            ]);

            return redirect()->intended(route('dashboard'));
        }

        throw ValidationException::withMessages([
            'email' => __('These credentials do not match our records.'),
        ]);
    }

    /**
     * Redirect to Google OAuth provider.
     */
    public function redirectToGoogle()
    {
        if (empty(config('services.google.client_id')) || empty(config('services.google.client_secret'))) {
            return redirect()->route('login')->with('error', 'Google SSO is not configured on this environment (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET missing).');
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    /**
     * Handle callback from Google OAuth provider.
     */
    public function handleGoogleCallback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('login')->with('error', 'Google authentication was cancelled or declined.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::warning('Google SSO authentication failed: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Failed to verify identity with Google. Please try again.');
        }

        $email = $googleUser->getEmail();
        $googleId = (string) $googleUser->getId();

        if (empty($email) || empty($googleId)) {
            return redirect()->route('login')->with('error', 'Google account did not provide a valid email address or identity.');
        }

        // 1. Find by stable Google ID
        $user = User::where('google_id', $googleId)->first();

        // 2. Or match verified email to link account
        if (!$user) {
            $user = User::where('email', $email)->first();

            if ($user) {
                $user->google_id = $googleId;
                if (!$user->email_verified_at) {
                    $user->email_verified_at = now();
                }
                if ($user->password && !$user->password_set_at) {
                    $user->password_set_at = now();
                }
                $user->save();
            } else {
                // Reject unregistered Google users! Self-registration is strictly disabled.
                AuditLog::log('rejected_unregistered_sso', 'user', '0', [
                    'email' => $email,
                    'google_id' => $googleId,
                    'reason' => 'unregistered_email',
                ]);

                return redirect()->route('login')->with('error', 'Access Denied: This Google email address (' . $email . ') is not registered in the system. Account provisioning is restricted to Super Administrators.');
            }
        }

        if ($user && $user->is_active === false) {
            return redirect()->route('login')->with('error', 'This account has been deactivated by an administrator. Please contact your Super Administrator.');
        }

        if ($user && ($user->role === 'viewer' || !in_array($user->role, ['super_admin', 'admin', 'analyst', 'research_lead', 'researcher', 'institutional_researcher'], true))) {
            return redirect()->route('login')->with('error', 'Access Denied: Your assigned account role (' . $user->role . ') has been deprecated. Please contact your Super Administrator.');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        $user->last_login_at = now();
        $user->save();

        AuditLog::log('google_login', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'auth_method' => 'google_sso',
        ]);

        return redirect()->intended(route('dashboard'))->with('status', "Welcome back, {$user->name}!");
    }

    public function logout(Request $request)
    {
        if (Auth::check()) {
            AuditLog::log('logout', 'user', (string) Auth::id());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
