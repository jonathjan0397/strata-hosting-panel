<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\DomainProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(Request $request): Response
    {
        $domains = Domain::with(['account.user', 'node'])
            ->when($request->input('search'), fn ($q, $s) =>
                $q->where('domain', 'like', "%{$s}%")
            )
            ->when($request->input('account_id'), fn ($q, $id) =>
                $q->where('account_id', $id)
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Domains/Index', [
            'domains' => $domains,
            'filters' => $request->only('search', 'account_id'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Domains/Create', [
            'accounts'    => Account::with('user')->where('status', 'active')->get()
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->username} ({$a->user->email})", 'node_id' => $a->node_id]),
            'phpVersions' => ['8.1', '8.2', '8.3'],
            'preselect'   => $request->input('account_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id'   => ['required', 'exists:accounts,id'],
            'domain'       => ['required', 'string', 'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', 'unique:domains,domain'],
            'type'         => ['required', 'in:main,addon,subdomain,parked'],
            'php_version'  => ['nullable', 'in:8.1,8.2,8.3'],
            'web_server'   => ['nullable', 'in:nginx,apache'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        $docRoot = "/var/www/{$account->username}/public_html";
        if ($data['type'] === 'addon' || $data['type'] === 'subdomain') {
            $slug    = str_replace(['.', '-'], '_', $data['domain']);
            $docRoot = "/var/www/{$account->username}/{$slug}";
        }

        $domain = Domain::create([
            'account_id'    => $account->id,
            'node_id'       => $account->node_id,
            'domain'        => strtolower($data['domain']),
            'type'          => $data['type'],
            'document_root' => $docRoot,
            'web_server'    => $data['web_server'] ?? 'nginx',
            'php_version'   => $data['php_version'],
        ]);

        [$success, $error] = app(DomainProvisioner::class)->provision($domain);

        AuditLog::record('domain.created', $domain, [
            'domain'      => $domain->domain,
            'account'     => $account->username,
            'provisioned' => $success,
        ]);

        if (! $success) {
            return back()->with('error', "Domain saved but vhost creation failed: {$error}");
        }

        return redirect()->route('admin.domains.show', $domain)
            ->with('success', "Domain {$domain->domain} created.");
    }

    public function show(Domain $domain): Response
    {
        $domain->load(['account.user', 'node']);

        return Inertia::render('Admin/Domains/Show', [
            'domain' => $domain,
        ]);
    }

    public function issueSSL(Domain $domain): RedirectResponse
    {
        [$success, $error] = app(DomainProvisioner::class)->issueSSL($domain);

        AuditLog::record('domain.ssl_issued', $domain, [
            'domain'  => $domain->domain,
            'success' => $success,
        ]);

        if (! $success) {
            return back()->with('error', "SSL issuance failed: {$error}");
        }

        return back()->with('success', "SSL certificate issued for {$domain->domain}.");
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $accountId = $domain->account_id;
        [$success, $error] = app(DomainProvisioner::class)->deprovision($domain);

        AuditLog::record('domain.deleted', $domain, [
            'domain'        => $domain->domain,
            'deprovisioned' => $success,
        ]);

        $domain->delete();

        $redirect = redirect()->route('admin.accounts.show', $accountId);

        if (! $success) {
            return $redirect->with('error', "Domain removed from panel but server cleanup had errors: {$error}");
        }

        return $redirect->with('success', 'Domain removed.');
    }
}
