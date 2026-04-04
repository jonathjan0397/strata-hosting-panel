<?php

namespace App\Http\Middleware;

use App\Services\StrataLicense;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    private function resolveBranding(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) return null;

        // Reseller viewing their own portal — show their own branding
        if ($user->isReseller() && ($user->brand_name || $user->brand_color)) {
            return ['name' => $user->brand_name, 'color' => $user->brand_color];
        }

        // End user whose reseller has branding set
        if ($user->reseller_id) {
            $reseller = $user->reseller;
            if ($reseller && ($reseller->brand_name || $reseller->brand_color)) {
                return ['name' => $reseller->brand_name, 'color' => $reseller->brand_color];
            }
        }

        return null;
    }

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
            // White-label: if the authenticated user has a reseller with branding,
            // pass it through so the UI can swap the panel name/colour.
            'branding' => fn () => $this->resolveBranding($request),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
