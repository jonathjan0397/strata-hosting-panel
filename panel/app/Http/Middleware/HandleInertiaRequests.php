<?php

namespace App\Http\Middleware;

use App\Services\StrataLicense;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id'               => $request->user()->id,
                    'name'             => $request->user()->name,
                    'email'            => $request->user()->email,
                    'roles'            => $request->user()->getRoleNames(),
                    'two_factor_enabled' => (bool) $request->user()->two_factor_confirmed_at,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
            ],
            // License data — served from cache, never blocks the request.
            'license' => fn () => [
                'status'    => StrataLicense::status(),
                'features'  => StrataLicense::features(),
                'managed'   => StrataLicense::isManaged(),
                'synced_at' => StrataLicense::cached()['synced_at'] ?? null,
            ],
            'app' => [
                'version'   => config('strata.version', 'dev'),
                'demo_mode' => (bool) config('strata.demo_mode'),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
