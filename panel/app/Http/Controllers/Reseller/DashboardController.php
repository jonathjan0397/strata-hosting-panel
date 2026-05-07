<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\HostingPackage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $reseller = $request->user();

        $clients = $reseller->resellerClients()
            ->with('account')
            ->latest()
            ->get();

        $accounts = Account::whereIn('user_id', $clients->pluck('id'))->get();

        $used = [
            'accounts'       => $clients->count(),
            'disk_mb'        => $accounts->sum('disk_limit_mb'),
            'bandwidth_mb'   => $accounts->sum('bandwidth_limit_mb'),
            'domains'        => $accounts->sum('max_domains'),
            'email_accounts' => $accounts->sum('max_email_accounts'),
            'databases'      => $accounts->sum('max_databases'),
        ];

        $quota = [
            'accounts'       => $reseller->quota_accounts,
            'disk_mb'        => $reseller->quota_disk_mb,
            'bandwidth_mb'   => $reseller->quota_bandwidth_mb,
            'domains'        => $reseller->quota_domains,
            'email_accounts' => $reseller->quota_email_accounts,
            'databases'      => $reseller->quota_databases,
        ];

        return Inertia::render('Reseller/Dashboard', [
            'quota' => $quota,
            'used' => $used,
            'clients' => $clients->take(5),
            'packageCount' => HostingPackage::query()
                ->where('is_active', true)
                ->where('available_to_resellers', true)
                ->count(),
        ]);
    }
}
