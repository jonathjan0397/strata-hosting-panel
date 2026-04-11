<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\HostingPackage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /** View a single client account + edit their resource limits. */
    public function show(Request $request, Account $account): Response
    {
        $this->authorize($request->user(), $account);

        $account->load(['user', 'node', 'domains', 'emailAccounts', 'databases', 'ftpAccounts', 'hostingPackage']);

        return Inertia::render('Reseller/Clients/Show', [
            'account' => [
                'id'                  => $account->id,
                'hosting_package_id'  => $account->hosting_package_id,
                'hosting_package'     => $account->hostingPackage?->only(['id', 'name', 'slug']),
                'username'            => $account->username,
                'status'              => $account->status,
                'php_version'         => $account->php_version,
                'disk_limit_mb'       => $account->disk_limit_mb,
                'bandwidth_limit_mb'  => $account->bandwidth_limit_mb,
                'max_domains'         => $account->max_domains,
                'max_email_accounts'  => $account->max_email_accounts,
                'max_databases'       => $account->max_databases,
                'created_at'          => $account->created_at?->toDateTimeString(),
                'node'                => $account->node?->only('id', 'name', 'hostname'),
                'user'                => $account->user?->only('id', 'name', 'email'),
                'domains'             => $account->domains->map(fn ($domain) => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                ])->values(),
                'domain_count'        => $account->domains->count(),
                'email_count'         => $account->emailAccounts->count(),
                'database_count'      => $account->databases->count(),
                'ftp_count'           => $account->ftpAccounts->count(),
            ],
            'packages' => HostingPackage::query()
                ->where('is_active', true)
                ->where('available_to_resellers', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'slug',
                    'php_version',
                    'disk_limit_mb',
                    'bandwidth_limit_mb',
                    'max_domains',
                    'max_email_accounts',
                    'max_databases',
                    'max_ftp_accounts',
                ]),
        ]);
    }

    /** Update resource limits for a client account. */
    public function update(Request $request, Account $account): RedirectResponse
    {
        $this->authorize($request->user(), $account);

        $reseller  = $request->user();
        $clientIds = $reseller->resellerClients()->pluck('id');
        $others    = Account::whereIn('user_id', $clientIds)
            ->where('id', '!=', $account->id)
            ->get();

        $data = $request->validate([
            'hosting_package_id'    => ['nullable', 'exists:hosting_packages,id'],
            'disk_limit_mb'       => ['nullable', 'integer', 'min:0'],
            'bandwidth_limit_mb'  => ['nullable', 'integer', 'min:0'],
            'max_domains'         => ['nullable', 'integer', 'min:0'],
            'max_email_accounts'  => ['nullable', 'integer', 'min:0'],
            'max_databases'       => ['nullable', 'integer', 'min:0'],
        ]);

        $package = isset($data['hosting_package_id']) && $data['hosting_package_id']
            ? HostingPackage::where('is_active', true)
                ->where('available_to_resellers', true)
                ->findOrFail($data['hosting_package_id'])
            : null;

        if ($package) {
            $data = array_merge($data, [
                'hosting_package_id' => $package->id,
                'plan' => $package->slug,
                'php_version' => $package->php_version,
                'disk_limit_mb' => $package->disk_limit_mb,
                'bandwidth_limit_mb' => $package->bandwidth_limit_mb,
                'max_domains' => $package->max_domains,
                'max_subdomains' => $package->max_subdomains,
                'max_email_accounts' => $package->max_email_accounts,
                'max_databases' => $package->max_databases,
                'max_ftp_accounts' => $package->max_ftp_accounts,
            ]);
        } else {
            $data['hosting_package_id'] = null;
        }

        // Quota enforcement (sum of other accounts + new value must not exceed reseller pool)
        $diskRequested = $data['disk_limit_mb'] ?? 0;
        if ($reseller->quota_disk_mb !== null &&
            ($others->sum('disk_limit_mb') + $diskRequested) > $reseller->quota_disk_mb) {
            return back()->withErrors(['disk_limit_mb' => "Disk allocation would exceed your quota of {$reseller->quota_disk_mb} MB."]);
        }

        $bandwidthRequested = $data['bandwidth_limit_mb'] ?? 0;
        if ($reseller->quota_bandwidth_mb !== null &&
            ($others->sum('bandwidth_limit_mb') + $bandwidthRequested) > $reseller->quota_bandwidth_mb) {
            return back()->withErrors(['bandwidth_limit_mb' => "Bandwidth allocation would exceed your quota of {$reseller->quota_bandwidth_mb} MB."]);
        }

        $domainsRequested = $data['max_domains'] ?? 0;
        if ($reseller->quota_domains !== null &&
            ($others->sum('max_domains') + $domainsRequested) > $reseller->quota_domains) {
            return back()->withErrors(['max_domains' => "Domain allocation would exceed your quota of {$reseller->quota_domains}."]);
        }

        $emailRequested = $data['max_email_accounts'] ?? 0;
        if ($reseller->quota_email_accounts !== null &&
            ($others->sum('max_email_accounts') + $emailRequested) > $reseller->quota_email_accounts) {
            return back()->withErrors(['max_email_accounts' => "Email allocation would exceed your quota of {$reseller->quota_email_accounts}."]);
        }

        $databaseRequested = $data['max_databases'] ?? 0;
        if ($reseller->quota_databases !== null &&
            ($others->sum('max_databases') + $databaseRequested) > $reseller->quota_databases) {
            return back()->withErrors(['max_databases' => "Database allocation would exceed your quota of {$reseller->quota_databases}."]);
        }

        $account->update($data);

        AuditLog::record('account.limits_updated', $account, [
            'updated_by' => 'reseller:' . $reseller->id,
            'changes'    => $data,
        ]);

        return back()->with('success', 'Resource limits updated.');
    }

    private function authorize(User $reseller, Account $account): void
    {
        $owns = $reseller->resellerClients()->where('id', $account->user_id)->exists();
        abort_unless($owns, 403);
    }
}
