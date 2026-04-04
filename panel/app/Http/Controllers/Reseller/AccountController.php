<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
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
                $q->where('username', 'like', "%{$s}%")
                  ->orWhereHas('user', fn ($q) => $q->where('email', 'like', "%{$s}%"))
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

        return Inertia::render('Reseller/Accounts/Create', [
            'nodes'       => Node::where('status', 'online')->select('id', 'name', 'hostname')->get(),
            'phpVersions' => ['8.1', '8.2', '8.3'],
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
            'php_version'          => ['required', 'in:8.1,8.2,8.3'],
            'disk_limit_mb'        => ['nullable', 'integer', 'min:0'],
            'bandwidth_limit_mb'   => ['nullable', 'integer', 'min:0'],
            'max_domains'          => ['nullable', 'integer', 'min:0'],
            'max_email_accounts'   => ['nullable', 'integer', 'min:0'],
            'max_databases'        => ['nullable', 'integer', 'min:0'],
        ]);

        // Quota enforcement
        $clientIds = $reseller->resellerClients()->pluck('id');
        $existing  = Account::whereIn('user_id', $clientIds)->get();

        if ($reseller->quota_accounts !== null && $clientIds->count() >= $reseller->quota_accounts) {
            return back()->withErrors(['quota' => 'Account quota reached. Contact your provider to increase your limit.']);
        }

        $diskRequested = $data['disk_limit_mb'] ?? 0;
        if ($reseller->quota_disk_mb !== null && ($existing->sum('disk_limit_mb') + $diskRequested) > $reseller->quota_disk_mb) {
            return back()->withErrors(['disk_limit_mb' => "Disk allocation would exceed your quota of {$reseller->quota_disk_mb} MB."]);
        }

        $bwRequested = $data['bandwidth_limit_mb'] ?? 0;
        if ($reseller->quota_bandwidth_mb !== null && ($existing->sum('bandwidth_limit_mb') + $bwRequested) > $reseller->quota_bandwidth_mb) {
            return back()->withErrors(['bandwidth_limit_mb' => "Bandwidth allocation would exceed your quota of {$reseller->quota_bandwidth_mb} MB."]);
        }

        $domainsRequested = $data['max_domains'] ?? 0;
        if ($reseller->quota_domains !== null && ($existing->sum('max_domains') + $domainsRequested) > $reseller->quota_domains) {
            return back()->withErrors(['max_domains' => "Domain allocation would exceed your quota of {$reseller->quota_domains}."]);
        }

        $emailRequested = $data['max_email_accounts'] ?? 0;
        if ($reseller->quota_email_accounts !== null && ($existing->sum('max_email_accounts') + $emailRequested) > $reseller->quota_email_accounts) {
            return back()->withErrors(['max_email_accounts' => "Email account allocation would exceed your quota of {$reseller->quota_email_accounts}."]);
        }

        $dbRequested = $data['max_databases'] ?? 0;
        if ($reseller->quota_databases !== null && ($existing->sum('max_databases') + $dbRequested) > $reseller->quota_databases) {
            return back()->withErrors(['max_databases' => "Database allocation would exceed your quota of {$reseller->quota_databases}."]);
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
            'username'           => $data['username'],
            'php_version'        => $data['php_version'],
            'status'             => 'active',
            'disk_limit_mb'      => $data['disk_limit_mb'] ?? 0,
            'bandwidth_limit_mb' => $data['bandwidth_limit_mb'] ?? 0,
            'max_domains'        => $data['max_domains'] ?? 0,
            'max_email_accounts' => $data['max_email_accounts'] ?? 0,
            'max_databases'      => $data['max_databases'] ?? 0,
        ]);

        [$success, $error] = app(AccountProvisioner::class)->provision($account);

        AuditLog::record('account.created', $account, [
            'username'    => $data['username'],
            'reseller_id' => $reseller->id,
            'provisioned' => $success,
        ]);

        if (! $success) {
            return back()->with('error', "Account created but server provisioning failed: {$error}");
        }

        return redirect()->route('reseller.accounts.index')
            ->with('success', "Account {$data['username']} created and provisioned.");
    }

    public function suspend(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeAccount($request->user(), $account);

        $account->update(['status' => 'suspended', 'suspended_at' => now()]);
        AuditLog::record('account.suspended', $account);

        return back()->with('success', "Account {$account->username} suspended.");
    }

    public function unsuspend(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeAccount($request->user(), $account);

        $account->update(['status' => 'active', 'suspended_at' => null]);
        AuditLog::record('account.unsuspended', $account);

        return back()->with('success', "Account {$account->username} unsuspended.");
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeAccount($request->user(), $account);

        [$success, $error] = app(AccountProvisioner::class)->deprovision($account);

        AuditLog::record('account.deleted', $account, [
            'username'      => $account->username,
            'deprovisioned' => $success,
        ]);

        $account->user->delete();
        $account->delete();

        if (! $success) {
            return redirect()->route('reseller.accounts.index')
                ->with('error', "Account deleted from panel but server cleanup failed: {$error}");
        }

        return redirect()->route('reseller.accounts.index')
            ->with('success', 'Account deleted.');
    }

    private function authorizeAccount(User $reseller, Account $account): void
    {
        $owns = $reseller->resellerClients()->where('id', $account->user_id)->exists();
        abort_unless($owns, 403);
    }
}
