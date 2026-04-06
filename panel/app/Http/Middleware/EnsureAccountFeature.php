<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->isAdmin()) {
            return $next($request);
        }

        $account = $user->account()
            ->with('hostingPackage.featureList')
            ->first();

        if (! $account || ! $account->hasFeature($feature)) {
            abort(403, 'This feature is not enabled for the current hosting package.');
        }

        return $next($request);
    }
}
