<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class ResellerController extends Controller
{
    public function index(Request $request): Response
    {
        $resellers = User::role('reseller')
            ->withCount('resellerClients')
            ->when($request->input('search'), fn ($q, $s) =>
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Resellers/Index', [
            'resellers' => $resellers,
            'filters'   => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Resellers/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:100'],
            'email'                => ['required', 'email', 'unique:users,email'],
            'password'             => ['required', 'string', 'min:12'],
            'quota_accounts'       => ['nullable', 'integer', 'min:1'],
            'quota_disk_mb'        => ['nullable', 'integer', 'min:0'],
            'quota_bandwidth_mb'   => ['nullable', 'integer', 'min:0'],
            'quota_domains'        => ['nullable', 'integer', 'min:0'],
            'quota_email_accounts' => ['nullable', 'integer', 'min:0'],
            'quota_databases'      => ['nullable', 'integer', 'min:0'],
        ]);

        $reseller = User::create([
            'name'                 => $data['name'],
            'email'                => $data['email'],
            'password'             => bcrypt($data['password']),
            'quota_accounts'       => $data['quota_accounts'] ?? null,
            'quota_disk_mb'        => $data['quota_disk_mb'] ?? null,
            'quota_bandwidth_mb'   => $data['quota_bandwidth_mb'] ?? null,
            'quota_domains'        => $data['quota_domains'] ?? null,
            'quota_email_accounts' => $data['quota_email_accounts'] ?? null,
            'quota_databases'      => $data['quota_databases'] ?? null,
        ]);

        $reseller->assignRole('reseller');

        AuditLog::record('reseller.created', $reseller, ['email' => $reseller->email]);

        return redirect()->route('admin.resellers.show', $reseller)
            ->with('success', "Reseller {$reseller->name} created.");
    }

    public function show(User $reseller): Response
    {
        abort_unless($reseller->hasRole('reseller'), 404);

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

        return Inertia::render('Admin/Resellers/Show', [
            'reseller' => $reseller,
            'clients'  => $clients,
            'used'     => $used,
        ]);
    }

    public function update(Request $request, User $reseller): RedirectResponse
    {
        abort_unless($reseller->hasRole('reseller'), 404);

        $data = $request->validate([
            'quota_accounts'       => ['nullable', 'integer', 'min:1'],
            'quota_disk_mb'        => ['nullable', 'integer', 'min:0'],
            'quota_bandwidth_mb'   => ['nullable', 'integer', 'min:0'],
            'quota_domains'        => ['nullable', 'integer', 'min:0'],
            'quota_email_accounts' => ['nullable', 'integer', 'min:0'],
            'quota_databases'      => ['nullable', 'integer', 'min:0'],
        ]);

        $reseller->update($data);

        AuditLog::record('reseller.updated', $reseller, ['quotas_updated' => true]);

        return back()->with('success', 'Reseller quotas updated.');
    }

    public function destroy(User $reseller): RedirectResponse
    {
        abort_unless($reseller->hasRole('reseller'), 404);

        // Detach clients (nullify reseller_id) rather than cascade-delete
        $reseller->resellerClients()->update(['reseller_id' => null]);

        AuditLog::record('reseller.deleted', $reseller, ['email' => $reseller->email]);

        $reseller->delete();

        return redirect()->route('admin.resellers.index')
            ->with('success', 'Reseller deleted. Client accounts were detached and remain active.');
    }
}
