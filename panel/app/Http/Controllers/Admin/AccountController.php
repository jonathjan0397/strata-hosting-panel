<?php

namespace App\Http\Controllers\Admin;

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
        $accounts = Account::with(['user', 'node', 'hostingPackage'])
            ->when($request->input('search'), fn ($q, $s) =>
                $q->where(function ($searchQuery) use ($s) {
                    $searchQuery->where('username', 'like', "%{$s}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('email', 'like', "%{$s}%"));
                })
            )
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Accounts/Index', [
            'accounts' => $accounts,
            'filters'  => $request->only('search', 'status'),
            'nodes'    => Node::select('id', 'name')->get(),
            'packages' => HostingPackage::where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id', 'name', 'slug', 'php_version', 'disk_limit_mb', 'bandwidth_limit_mb',
                    'max_domains', 'max_subdomains', 'max_email_accounts', 'max_databases', 'max_ftp_accounts',
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Accounts/Create', [
            'nodes'        => Node::where('status', 'online')->select('id', 'name', 'hostname')->get(),
            'phpVersions'  => ['8.1', '8.2', '8.3', '8.4'],
            'packages'     => HostingPackage::where('is_active', true)->orderBy('name')->get([
                'id', 'name', 'slug', 'php_version', 'disk_limit_mb', 'bandwidth_limit_mb',
                'max_domains', 'max_email_accounts', 'max_databases', 'max_ftp_accounts',
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
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
            ? HostingPackage::where('is_active', true)->findOrFail($data['hosting_package_id'])
            : null;

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

        // Create user record
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => bcrypt($data['password']),
        ]);
        $user->assignRole('user');

        // Create account record
        $account = Account::create([
            'user_id'            => $user->id,
            'node_id'            => $data['node_id'],
            'hosting_package_id' => $package?->id,
            'username'           => $data['username'],
            'status'             => 'provisioning',
            ...$accountAttributes,
        ]);

        ProvisionAccount::dispatch($account->id, $request->user()->id);

        AuditLog::record('account.provisioning_queued', $account, [
            'username' => $data['username'],
            'node'     => $data['node_id'],
            'queued' => true,
        ]);

        return redirect()->route('admin.accounts.show', $account)
            ->with('success', "Account {$data['username']} was created and provisioning was queued.");
    }

    public function show(Account $account): Response
    {
        $account->load(['user', 'node', 'domains']);

        return Inertia::render('Admin/Accounts/Show', [
            'account' => $account,
        ]);
    }

    public function suspend(Account $account): RedirectResponse
    {
        if (! $account->isActive()) {
            return back()->with('error', 'Only active accounts can be suspended.');
        }

        $account->update(['status' => 'suspended', 'suspended_at' => now()]);
        AuditLog::record('account.suspended', $account);

        return back()->with('success', "Account {$account->username} suspended.");
    }

    public function unsuspend(Account $account): RedirectResponse
    {
        if (! $account->isSuspended()) {
            return back()->with('error', 'Only suspended accounts can be unsuspended.');
        }

        $account->update(['status' => 'active', 'suspended_at' => null]);
        AuditLog::record('account.unsuspended', $account);

        return back()->with('success', "Account {$account->username} unsuspended.");
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:100'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
            'action' => ['required', 'in:suspend,unsuspend'],
        ]);

        $accounts = Account::whereIn('id', $data['account_ids'])->get();
        $targetStatus = $data['action'] === 'suspend' ? 'suspended' : 'active';
        $updated = 0;

        foreach ($accounts as $account) {
            if ($targetStatus === 'suspended' && ! $account->isActive()) {
                continue;
            }
            if ($targetStatus === 'active' && ! $account->isSuspended()) {
                continue;
            }
            if ($account->status === $targetStatus) {
                continue;
            }

            $account->update([
                'status' => $targetStatus,
                'suspended_at' => $targetStatus === 'suspended' ? now() : null,
            ]);

            $auditAction = $targetStatus === 'suspended' ? 'account.suspended' : 'account.unsuspended';
            AuditLog::record($auditAction, $account, [
                'bulk' => true,
                'action' => $data['action'],
            ]);

            $updated++;
        }

        $label = $data['action'] === 'suspend' ? 'suspended' : 'unsuspended';

        return back()->with('success', "{$updated} account(s) {$label}.");
    }

    public function bulkPackage(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:100'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
            'hosting_package_id' => ['required', 'exists:hosting_packages,id'],
        ]);

        $package = HostingPackage::where('is_active', true)->findOrFail($data['hosting_package_id']);
        $accounts = Account::whereIn('id', $data['account_ids'])->get();
        $updated = 0;

        foreach ($accounts as $account) {
            $account->update($package->accountAttributes());

            AuditLog::record('account.package_updated', $account, [
                'bulk' => true,
                'package_id' => $package->id,
                'package' => $package->slug,
            ]);

            $updated++;
        }

        return back()->with('success', "{$updated} account(s) moved to package {$package->name}.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        [$success, $error] = app(AccountProvisioner::class)->deprovision($account);

        if (! $success) {
            return redirect()->route('admin.accounts.index')
                ->with('error', "Server cleanup failed, account was kept in the panel: {$error}");
        }

        AuditLog::record('account.deleted', $account, [
            'username'      => $account->username,
            'deprovisioned' => true,
        ]);

        $account->user->delete();
        $account->delete();

        return redirect()->route('admin.accounts.index')
            ->with('success', "Account deleted.");
    }
}
