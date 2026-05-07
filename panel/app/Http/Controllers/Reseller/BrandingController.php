<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\HostingPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    public function edit(Request $request): Response
    {
        $reseller = $request->user();

        return Inertia::render('Reseller/Branding', [
            'brand_name' => $reseller->brand_name,
            'brand_color' => $reseller->brand_color ?? '#6366f1',
            'default_hosting_package_id' => $reseller->default_hosting_package_id,
            'packages' => HostingPackage::query()
                ->where('is_active', true)
                ->where('available_to_resellers', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'description']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:60'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_hosting_package_id' => ['nullable', 'exists:hosting_packages,id'],
        ]);

        if ($data['default_hosting_package_id'] ?? null) {
            HostingPackage::query()
                ->where('is_active', true)
                ->where('available_to_resellers', true)
                ->findOrFail($data['default_hosting_package_id']);
        }

        $request->user()->update($data);

        return back()->with('success', 'Reseller settings updated.');
    }
}
