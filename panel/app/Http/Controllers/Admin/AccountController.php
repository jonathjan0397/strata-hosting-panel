<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Node;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = Account::with(['user', 'node'])
            ->when($request->input('search'), fn ($q, $s) =>
                $q->where('username', 'like', "%{$s}%")
                  ->orWhereHas('user', fn ($q) => $q->where('email', 'like', "%{$s}%"))
            )
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Accounts/Index', [
            'accounts' => $accounts,
            'filters'  => $request->only('search', 'status'),
            'nodes'    => Node::select('id', 'name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Accounts/Create', [
            'nodes'        => Node::where('status', 'online')->select('id', 'name', 'hostname')->get(),
            'phpVersions'  => ['8.1', '8.2', '8.3'],
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
            'php_version'          => ['required', 'in:8.1,8.2,8.3'],
            'disk_limit_mb'        => ['nullable', 'integer', 'min:0'],
            'bandwidth_limit_mb'   => ['nullable', 'integer', 'min:0'],
            'max_domains'          => ['nullable', 'integer', 'min:0'],
            'max_email_accounts'   => ['nullable', 'integer', 'min:0'],
            'max_databases'        => ['nullable', 'integer', 'min:0'],
        ]);

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
            'username'           => $data['username'],
            'php_version'        => $data['php_version'],
            'status'             => 'active',
            'disk_limit_mb'      => $data['disk_limit_mb'] ?? 0,
            'bandwidth_limit_mb' => $data['bandwidth_limit_mb'] ?? 0,
            'max_domains'        => $data['max_domains'] ?? 0,
            'max_email_accounts' => $data['max_email_accounts'] ?? 0,
            'max_databases'      => $data['max_databases'] ?? 0,
        ]);

        // Provision on server
        [$success, $error] = app(AccountProvisioner::class)->provision($account);

        AuditLog::record('account.created', $account, [
            'username' => $data['username'],
            'node'     => $data['node_id'],
            'provisioned' => $success,
        ]);

        if (! $success) {
            return back()->with('error', "Account created but server provisioning failed: {$error}");
        }

        return redirect()->route('admin.accounts.show', $account)
            ->with('success', "Account {$data['username']} created and provisioned.");
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
        $account->update(['status' => 'suspended', 'suspended_at' => now()]);
        AuditLog::record('account.suspended', $account);

        return back()->with('success', "Account {$account->username} suspended.");
    }

    public function unsuspend(Account $account): RedirectResponse
    {
        $account->update(['status' => 'active', 'suspended_at' => null]);
        AuditLog::record('account.unsuspended', $account);

        return back()->with('success', "Account {$account->username} unsuspended.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        [$success, $error] = app(AccountProvisioner::class)->deprovision($account);

        AuditLog::record('account.deleted', $account, [
            'username'      => $account->username,
            'deprovisioned' => $success,
        ]);

        $account->user->delete();
        $account->delete();

        if (! $success) {
            return redirect()->route('admin.accounts.index')
                ->with('error', "Account deleted from panel but server cleanup failed: {$error}");
        }

        return redirect()->route('admin.accounts.index')
            ->with('success', "Account deleted.");
    }
}
