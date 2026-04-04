<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
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
            'brand_name'  => $reseller->brand_name,
            'brand_color' => $reseller->brand_color ?? '#6366f1',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_name'  => ['nullable', 'string', 'max:60'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'Branding updated.');
    }
}
