<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        $demo = (bool) config('strata.demo_mode');

        return Inertia::render('Auth/Login', [
            'demoMode' => $demo,
            'demoCredentials' => $demo ? [
                ['role' => 'Admin', 'email' => 'demo-admin@stratadevplatform.net', 'password' => 'DemoAdmin2026!'],
                ['role' => 'Reseller', 'email' => 'demo-reseller@stratadevplatform.net', 'password' => 'DemoReseller2026!'],
                ['role' => 'End User', 'email' => 'demo-user@stratadevplatform.net', 'password' => 'DemoUser2026!'],
                ['role' => 'Client', 'email' => 'demo-client@stratadevplatform.net', 'password' => 'DemoClient2026!'],
            ] : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttleKey = $this->throttleKey($request->input('email', ''), $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 300);
            return back()->withErrors([
                'email' => 'The provided credentials are incorrect.',
            ])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return Str::lower(trim($email)) . '|' . ($ip ?: 'unknown');
    }
}
