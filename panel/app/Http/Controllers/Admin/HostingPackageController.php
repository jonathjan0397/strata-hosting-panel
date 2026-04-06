<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureList;
use App\Models\HostingPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HostingPackageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Packages/Index', [
            'packages' => HostingPackage::query()
                ->with('featureList:id,name')
                ->withCount('accounts')
                ->orderBy('name')
                ->get()
                ->map(fn (HostingPackage $package) => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'slug' => $package->slug,
                    'description' => $package->description,
                    'feature_list' => $package->featureList?->name,
                    'php_version' => $package->php_version,
                    'disk_limit_mb' => $package->disk_limit_mb,
                    'bandwidth_limit_mb' => $package->bandwidth_limit_mb,
                    'max_domains' => $package->max_domains,
                    'max_email_accounts' => $package->max_email_accounts,
                    'max_databases' => $package->max_databases,
                    'max_ftp_accounts' => $package->max_ftp_accounts,
                    'available_to_resellers' => $package->available_to_resellers,
                    'is_active' => $package->is_active,
                    'accounts_count' => $package->accounts_count,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Packages/Create', [
            'featureLists' => FeatureList::orderBy('name')->get(['id', 'name']),
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        HostingPackage::create($this->validated($request));

        return redirect()->route('admin.packages.index')
            ->with('success', 'Hosting package created.');
    }

    public function edit(HostingPackage $package): Response
    {
        return Inertia::render('Admin/Packages/Edit', [
            'package' => $package->only([
                'id',
                'name',
                'slug',
                'description',
                'feature_list_id',
                'php_version',
                'disk_limit_mb',
                'bandwidth_limit_mb',
                'max_domains',
                'max_subdomains',
                'max_email_accounts',
                'max_databases',
                'max_ftp_accounts',
                'available_to_resellers',
                'is_active',
            ]),
            'featureLists' => FeatureList::orderBy('name')->get(['id', 'name']),
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
        ]);
    }

    public function update(Request $request, HostingPackage $package): RedirectResponse
    {
        $package->update($this->validated($request, $package));

        return redirect()->route('admin.packages.index')
            ->with('success', 'Hosting package updated.');
    }

    public function destroy(HostingPackage $package): RedirectResponse
    {
        $package->delete();

        return redirect()->route('admin.packages.index')
            ->with('success', 'Hosting package deleted.');
    }

    private function validated(Request $request, ?HostingPackage $package = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('hosting_packages', 'name')->ignore($package?->id)],
            'slug' => ['nullable', 'string', 'max:100', Rule::unique('hosting_packages', 'slug')->ignore($package?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'feature_list_id' => ['nullable', 'exists:feature_lists,id'],
            'php_version' => ['required', 'in:8.1,8.2,8.3,8.4'],
            'disk_limit_mb' => ['nullable', 'integer', 'min:0'],
            'bandwidth_limit_mb' => ['nullable', 'integer', 'min:0'],
            'max_domains' => ['nullable', 'integer', 'min:0'],
            'max_subdomains' => ['nullable', 'integer', 'min:0'],
            'max_email_accounts' => ['nullable', 'integer', 'min:0'],
            'max_databases' => ['nullable', 'integer', 'min:0'],
            'max_ftp_accounts' => ['nullable', 'integer', 'min:0'],
            'available_to_resellers' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
