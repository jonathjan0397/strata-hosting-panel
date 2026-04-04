<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
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

        $account->load(['user', 'node', 'domains', 'emailAccounts', 'hostingDatabases', 'ftpAccounts']);

        return Inertia::render('Reseller/Clients/Show', [
            'account' => [
                'id'                  => $account->id,
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
                'domain_count'        => $account->domains->count(),
                'email_count'         => $account->emailAccounts->count(),
                'database_count'      => $account->hostingDatabases->count(),
                'ftp_count'           => $account->ftpAccounts->count(),
            ],
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
            'disk_limit_mb'       => ['nullable', 'integer', 'min:0'],
            'bandwidth_limit_mb'  => ['nullable', 'integer', 'min:0'],
            'max_domains'         => ['nullable', 'integer', 'min:0'],
            'max_email_accounts'  => ['nullable', 'integer', 'min:0'],
            'max_databases'       => ['nullable', 'integer', 'min:0'],
        ]);

        // Quota enforcement (sum of other accounts + new value must not exceed reseller pool)
        $diskRequested = $data['disk_limit_mb'] ?? 0;
        if ($reseller->quota_disk_mb !== null &&
            ($others->sum('disk_limit_mb') + $diskRequested) > $reseller->quota_disk_mb) {
            return back()->withErrors(['disk_limit_mb' => "Disk allocation would exceed your quota of {$reseller->quota_disk_mb} MB."]);
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
