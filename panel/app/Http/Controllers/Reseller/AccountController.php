<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionAccount;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\HostingPackage;
use App\Models\Node;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $reseller = $request->user();

        $clientIds = $reseller->resellerClients()->pluck('id');

        $accounts = Account::with(['user', 'node'])
            ->whereIn('user_id', $clientIds)
            ->when($request->input('search'), fn ($q, $s) =>
                $q->where(function ($searchQuery) use ($s) {
                    $searchQuery->where('username', 'like', "%{$s}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('email', 'like', "%{$s}%"));
                })
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Reseller/Accounts/Index', [
            'accounts' => $accounts,
            'filters'  => $request->only('search'),
        ]);
    }

    public function create(Request $request): Response
    {
        $reseller = $request->user();

        $clientIds = $reseller->resellerClients()->pluck('id');
        $existing  = Account::whereIn('user_id', $clientIds)->get();

        $remaining = [
            'accounts'       => $reseller->quota_accounts !== null
                ? max(0, $reseller->quota_accounts - $clientIds->count())
                : null,
            'disk_mb'        => $reseller->quota_disk_mb !== null
                ? max(0, $reseller->quota_disk_mb - $existing->sum('disk_limit_mb'))
                : null,
            'bandwidth_mb'   => $reseller->quota_bandwidth_mb !== null
                ? max(0, $reseller->quota_bandwidth_mb - $existing->sum('bandwidth_limit_mb'))
                : null,
            'domains'        => $reseller->quota_domains !== null
                ? max(0, $reseller->quota_domains - $existing->sum('max_domains'))
                : null,
            'email_accounts' => $reseller->quota_email_accounts !== null
                ? max(0, $reseller->quota_email_accounts - $existing->sum('max_email_accounts'))
                : null,
            'databases'      => $reseller->quota_databases !== null
                ? max(0, $reseller->quota_databases - $existing->sum('max_databases'))
                : null,
        ];

        $packages = HostingPackage::where('is_active', true)
            ->where('available_to_resellers', true)
            ->orderBy('name')
            ->get([
                'id', 'name', 'slug', 'php_version', 'disk_limit_mb', 'bandwidth_limit_mb',
                'max_domains', 'max_email_accounts', 'max_databases', 'max_ftp_accounts',
            ]);

        return Inertia::render('Reseller/Accounts/Create', [
            'nodes'       => Node::where('status', 'online')->select('id', 'name', 'hostname')->get(),
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
            'packages'    => $packages,
            'defaultPackageId' => $packages->contains('id', $reseller->default_hosting_package_id)
                ? $reseller->default_hosting_package_id
                : null,
            'remaining'   => $remaining,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $reseller = $request->user();

        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:100'],
            'email'                => ['required', 'email', 'unique:users,email'],
            'password'             => ['required', 'string', 'min:12'],
            'username'             => ['required', 'regex:/^[a-z][a-z0-9_]{1,31}$/', 'unique:accounts,username'],
            'node_id'              => ['required', 'exists:nodes,id'],
            'hosting_package_id'   => ['nullable', 'exists:hosting_packages,id'],
            'php_version'          => ['required', 'in:8.1,8.2,8.3,8.4'],
            'disk_limit_mb'        => ['nullable', 'integer', 'min:0'],
            'bandwidth_limit_mb'   => ['nullable', 'integer', 'min:0'],
            'max_domains'          => ['nullable', 'integer', 'min:0'],
            'max_email_accounts'   => ['nullable', 'integer', 'min:0'],
            'max_databases'        => ['nullable', 'integer', 'min:0'],
        ]);

        $package = isset($data['hosting_package_id'])
            ? HostingPackage::where('is_active', true)->where('available_to_resellers', true)->findOrFail($data['hosting_package_id'])
            : null;

        // Quota enforcement
        $clientIds = $reseller->resellerClients()->pluck('id');
        $existing  = Account::whereIn('user_id', $clientIds)->get();

        if ($reseller->quota_accounts !== null && $clientIds->count() >= $reseller->quota_accounts) {
            return back()->withErrors(['quota' => 'Account quota reached. Contact your provider to increase your limit.']);
        }

        $diskRequested = $package?->disk_limit_mb ?? ($data['disk_limit_mb'] ?? 0);
        if ($reseller->quota_disk_mb !== null && ($existing->sum('disk_limit_mb') + $diskRequested) > $reseller->quota_disk_mb) {
            return back()->withErrors(['disk_limit_mb' => "Disk allocation would exceed your quota of {$reseller->quota_disk_mb} MB."]);
        }

        $bwRequested = $package?->bandwidth_limit_mb ?? ($data['bandwidth_limit_mb'] ?? 0);
        if ($reseller->quota_bandwidth_mb !== null && ($existing->sum('bandwidth_limit_mb') + $bwRequested) > $reseller->quota_bandwidth_mb) {
            return back()->withErrors(['bandwidth_limit_mb' => "Bandwidth allocation would exceed your quota of {$reseller->quota_bandwidth_mb} MB."]);
        }

        $domainsRequested = $package?->max_domains ?? ($data['max_domains'] ?? 0);
        if ($reseller->quota_domains !== null && ($existing->sum('max_domains') + $domainsRequested) > $reseller->quota_domains) {
            return back()->withErrors(['max_domains' => "Domain allocation would exceed your quota of {$reseller->quota_domains}."]);
        }

        $emailRequested = $package?->max_email_accounts ?? ($data['max_email_accounts'] ?? 0);
        if ($reseller->quota_email_accounts !== null && ($existing->sum('max_email_accounts') + $emailRequested) > $reseller->quota_email_accounts) {
            return back()->withErrors(['max_email_accounts' => "Email account allocation would exceed your quota of {$reseller->quota_email_accounts}."]);
        }

        $dbRequested = $package?->max_databases ?? ($data['max_databases'] ?? 0);
        if ($reseller->quota_databases !== null && ($existing->sum('max_databases') + $dbRequested) > $reseller->quota_databases) {
            return back()->withErrors(['max_databases' => "Database allocation would exceed your quota of {$reseller->quota_databases}."]);
        }

        $accountAttributes = [
            'php_version' => $data['php_version'],
            'disk_limit_mb' => $data['disk_limit_mb'] ?? 0,
            'bandwidth_limit_mb' => $data['bandwidth_limit_mb'] ?? 0,
            'max_domains' => $data['max_domains'] ?? 0,
            'max_email_accounts' => $data['max_email_accounts'] ?? 0,
            'max_databases' => $data['max_databases'] ?? 0,
        ];
        if ($package) {
            $accountAttributes = array_merge($accountAttributes, $package->accountAttributes());
        }

        $user = User::create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => bcrypt($data['password']),
            'reseller_id' => $reseller->id,
        ]);
        $user->assignRole('user');

        $account = Account::create([
            'user_id'            => $user->id,
            'node_id'            => $data['node_id'],
            'reseller_id'        => $reseller->id,
            'hosting_package_id' => $package?->id,
            'username'           => $data['username'],
            'status'             => 'provisioning',
            ...$accountAttributes,
        ]);

        ProvisionAccount::dispatch($account->id, $reseller->id);

        AuditLog::record('account.provisioning_queued', $account, [
            'username'    => $data['username'],
            'reseller_id' => $reseller->id,
            'queued' => true,
        ]);

        return redirect()->route('reseller.accounts.index')
            ->with('success', "Account {$data['username']} was created and provisioning was queued.");
    }

    public function suspend(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeAccount($request->user(), $account);

        if (! $account->isActive()) {
            return back()->with('error', 'Only active accounts can be suspended.');
        }

        $account->update(['status' => 'suspended', 'suspended_at' => now()]);
        AuditLog::record('account.suspended', $account);

        return back()->with('success', "Account {$account->username} suspended.");
    }

    public function unsuspend(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeAccount($request->user(), $account);

        if (! $account->isSuspended()) {
            return back()->with('error', 'Only suspended accounts can be unsuspended.');
        }

        $account->update(['status' => 'active', 'suspended_at' => null]);
        AuditLog::record('account.unsuspended', $account);

        return back()->with('success', "Account {$account->username} unsuspended.");
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeAccount($request->user(), $account);

        [$success, $error] = app(AccountProvisioner::class)->deprovision($account);

        if (! $success) {
            return redirect()->route('reseller.accounts.index')
                ->with('error', "Server cleanup failed, account was kept in the panel: {$error}");
        }

        AuditLog::record('account.deleted', $account, [
            'username'      => $account->username,
            'deprovisioned' => true,
        ]);

        $account->user->delete();
        $account->delete();

        return redirect()->route('reseller.accounts.index')
            ->with('success', 'Account deleted.');
    }

    private function authorizeAccount(User $reseller, Account $account): void
    {
        $owns = $reseller->resellerClients()->where('id', $account->user_id)->exists();
        abort_unless($owns, 403);
    }
}
