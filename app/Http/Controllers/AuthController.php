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
            $request->session()->regenerate();

            AuditLog::log('login', 'user', (string) Auth::id(), [
                'email' => Auth::user()->email,
                'role' => Auth::user()->role,
            ]);

            return redirect()->intended(route('dashboard'));
        }

        throw ValidationException::withMessages([
            'email' => __('Kredensial yang diberikan tidak cocok dengan data kami.'),
        ]);
    }

    /**
     * Redirect to Google OAuth provider.
     */
    public function redirectToGoogle()
    {
        if (empty(config('services.google.client_id')) || empty(config('services.google.client_secret'))) {
            return redirect()->route('login')->with('error', 'Google SSO belum dikonfigurasi pada environment ini (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET belum diisi).');
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
            return redirect()->route('login')->with('error', 'Autentikasi Google dibatalkan atau ditolak.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::warning('Google SSO authentication failed: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Gagal memverifikasi identitas dengan Google. Silakan coba kembali.');
        }

        $email = $googleUser->getEmail();
        $googleId = (string) $googleUser->getId();

        if (empty($email) || empty($googleId)) {
            return redirect()->route('login')->with('error', 'Akun Google tidak menyediakan email atau identitas yang valid.');
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
                $user->save();
            } else {
                // 3. Register new user with safe default role
                $user = User::create([
                    'name' => $googleUser->getName() ?: 'Google User',
                    'email' => $email,
                    'google_id' => $googleId,
                    'role' => 'viewer',
                    'organization' => 'External / Google SSO',
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(32)),
                ]);
            }
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        AuditLog::log('google_login', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'auth_method' => 'google_sso',
        ]);

        return redirect()->intended(route('dashboard'))->with('status', "Selamat datang, {$user->name}!");
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
