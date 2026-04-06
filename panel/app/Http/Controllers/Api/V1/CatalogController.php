<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeatureList;
use App\Models\HostingPackage;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function packages(): JsonResponse
    {
        $packages = HostingPackage::with('featureList:id,name,slug,features')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (HostingPackage $package) => [
                'id' => $package->id,
                'name' => $package->name,
                'slug' => $package->slug,
                'description' => $package->description,
                'available_to_resellers' => $package->available_to_resellers,
                'php_version' => $package->php_version,
                'limits' => [
                    'disk_limit_mb' => $package->disk_limit_mb,
                    'bandwidth_limit_mb' => $package->bandwidth_limit_mb,
                    'max_domains' => $package->max_domains,
                    'max_subdomains' => $package->max_subdomains,
                    'max_email_accounts' => $package->max_email_accounts,
                    'max_databases' => $package->max_databases,
                    'max_ftp_accounts' => $package->max_ftp_accounts,
                ],
                'feature_list' => $package->featureList ? [
                    'id' => $package->featureList->id,
                    'name' => $package->featureList->name,
                    'slug' => $package->featureList->slug,
                    'features' => $package->featureList->features ?? [],
                ] : null,
            ]);

        return response()->json(['data' => $packages]);
    }

    public function featureLists(): JsonResponse
    {
        $featureLists = FeatureList::orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'features'])
            ->map(fn (FeatureList $featureList) => [
                'id' => $featureList->id,
                'name' => $featureList->name,
                'slug' => $featureList->slug,
                'description' => $featureList->description,
                'features' => $featureList->features ?? [],
            ]);

        return response()->json([
            'data' => $featureLists,
            'catalog' => FeatureList::catalog(),
        ]);
    }
}
