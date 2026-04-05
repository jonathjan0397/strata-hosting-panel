<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\DomainProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    private function account()
    {
        return auth()->user()->account()->firstOrFail();
    }

    public function index(): Response
    {
        $account = $this->account();
        $domains = Domain::where('account_id', $account->id)->latest()->get();

        return Inertia::render('User/Domains/Index', [
            'account' => $account,
            'domains' => $domains,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('User/Domains/Create', [
            'phpVersions' => ['8.1', '8.2', '8.3'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->account();

        if ($account->isSuspended()) {
            return back()->with('error', 'Your account is suspended.');
        }

        if ($account->max_domains > 0) {
            $count = Domain::where('account_id', $account->id)->count();
            if ($count >= $account->max_domains) {
                return back()->with('error', "Domain limit reached ({$account->max_domains}).");
            }
        }

        $data = $request->validate([
            'domain'      => ['required', 'string', 'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', 'unique:domains,domain'],
            'type'        => ['required', 'in:addon,subdomain,parked'],
            'php_version' => ['nullable', 'in:8.1,8.2,8.3'],
        ]);

        $slug    = str_replace(['.', '-'], '_', $data['domain']);
        $docRoot = $data['type'] === 'parked'
            ? "/var/www/{$account->username}/public_html"
            : "/var/www/{$account->username}/{$slug}";

        $domain = Domain::create([
            'account_id'    => $account->id,
            'node_id'       => $account->node_id,
            'domain'        => strtolower($data['domain']),
            'type'          => $data['type'],
            'document_root' => $docRoot,
            'web_server'    => 'nginx',
            'php_version'   => $data['php_version'] ?? $account->php_version,
        ]);

        [$success, $error] = app(DomainProvisioner::class)->provision($domain);

        if (! $success) {
            return back()->with('error', "Domain saved but vhost creation failed: {$error}");
        }

        return redirect()->route('my.domains.show', $domain)
            ->with('success', "{$domain->domain} added.");
    }

    public function show(Domain $domain): Response
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $domain->load('node');

        return Inertia::render('User/Domains/Show', [
            'domain'      => $domain,
            'phpVersions' => ['8.1', '8.2', '8.3'],
        ]);
    }

    public function issueSSL(Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        [$success, $error] = app(DomainProvisioner::class)->issueSSL($domain);

        return $success
            ? back()->with('success', "SSL certificate issued for {$domain->domain}.")
            : back()->with('error', "SSL issuance failed: {$error}");
    }

    public function uploadCert(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'cert_pem' => ['required', 'string'],
            'key_pem'  => ['required', 'string'],
        ]);

        [$success, $error] = app(DomainProvisioner::class)->storeCustomSSL($domain, $data['cert_pem'], $data['key_pem']);

        return $success
            ? back()->with('success', "Custom SSL certificate installed for {$domain->domain}.")
            : back()->with('error', "Certificate upload failed: {$error}");
    }

    public function changePhp(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'php_version' => ['required', 'in:8.1,8.2,8.3'],
        ]);

        [$success, $error] = app(DomainProvisioner::class)->changePhpVersion($domain, $data['php_version']);

        if (! $success) {
            return back()->with('error', "PHP version change failed: {$error}");
        }

        $domain->update(['php_version' => $data['php_version']]);

        return back()->with('success', "PHP version set to {$data['php_version']}.");
    }

    public function updateDirectives(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'custom_directives' => ['nullable', 'string', 'max:4096'],
        ]);

        $domain->update(['custom_directives' => $data['custom_directives'] ?? null]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        return $success
            ? back()->with('success', 'Custom directives saved and vhost updated.')
            : back()->with('error', "Directives saved but vhost update failed: {$error}");
    }

    public function storeRedirect(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'source'      => ['required', 'string', 'regex:/^\//', 'max:255'],
            'destination' => ['required', 'url', 'max:2048'],
            'type'        => ['required', 'in:301,302'],
        ]);

        $redirects   = $domain->redirects ?? [];
        $redirects[] = [
            'source'      => $data['source'],
            'destination' => $data['destination'],
            'type'        => (int) $data['type'],
        ];

        $domain->update(['redirects' => $redirects]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        return $success
            ? back()->with('success', 'Redirect added.')
            : back()->with('error', "Redirect saved but vhost update failed: {$error}");
    }

    public function destroyRedirect(Request $request, Domain $domain, int $index): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $redirects = $domain->redirects ?? [];
        array_splice($redirects, $index, 1);

        $domain->update(['redirects' => $redirects ?: null]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        return $success
            ? back()->with('success', 'Redirect removed.')
            : back()->with('error', "Redirect removed but vhost update failed: {$error}");
    }
}
