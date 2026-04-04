<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
                ['role' => 'Admin',    'email' => 'demo-admin@example.com', 'password' => 'DemoAdmin2026'],
                ['role' => 'End User', 'email' => 'demo-user@example.com',  'password' => 'DemoUser2026!'],
            ] : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'The provided credentials are incorrect.',
            ])->onlyInput('email');
        }

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
}
