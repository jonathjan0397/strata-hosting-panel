<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->two_factor_confirmed_at) {
            return $next($request);
        }

        // Already passed 2FA this session
        if ($request->session()->get('auth.two_factor_confirmed')) {
            return $next($request);
        }

        // Don't redirect on the challenge routes themselves
        if ($request->routeIs('two-factor.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }
}
