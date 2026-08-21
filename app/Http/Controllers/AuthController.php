<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('username'))
                ->with('login_error', "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.");
        }

        $user = User::query()->where('username', $credentials['username'])->first();

        if (! $user || ! $this->passwordMatches($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withInput($request->only('username'))->with('login_error', 'Username atau Password salah.');
        }

        RateLimiter::clear($throttleKey);

        if (preg_match('/^[a-f0-9]{32}$/i', $user->password) === 1) {
            $user->update([
                'password' => Hash::make($credentials['password']),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login', ['pesan' => 'logout']);
    }

    private function passwordMatches(string $plainPassword, string $storedPassword): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/i', $storedPassword) === 1) {
            return hash_equals(strtolower($storedPassword), md5($plainPassword));
        }

        return Hash::check($plainPassword, $storedPassword);
    }
}
