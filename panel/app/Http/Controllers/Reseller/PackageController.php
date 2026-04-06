<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\HostingPackage;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Reseller/Packages/Index', [
            'packages' => HostingPackage::query()
                ->where('is_active', true)
                ->where('available_to_resellers', true)
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
                    'accounts_count' => $package->accounts_count,
                ]),
        ]);
    }
}
