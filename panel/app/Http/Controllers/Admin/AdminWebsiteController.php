<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Node;
use App\Services\AccountProvisioner;
use App\Services\DomainProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminWebsiteController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->user()
            ->account()
            ->with(['node', 'domains'])
            ->first();

        $primaryNode = Node::where('status', 'online')
            ->where('is_primary', true)
            ->first()
            ?? Node::where('status', 'online')->first();

        return Inertia::render('Admin/MyWebsite', [
            'account'     => $account ? [
                'id'          => $account->id,
                'username'    => $account->username,
                'php_version' => $account->php_version,
                'status'      => $account->status,
                'node'        => $account->node?->only('id', 'name', 'hostname'),
                'domain'      => $account->domains->first()?->only('id', 'domain', 'ssl_enabled', 'ssl_expires_at'),
            ] : null,
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
            'primaryNode' => $primaryNode?->only('id', 'name', 'hostname'),
        ]);
    }

    public function provision(Request $request): RedirectResponse
    {
        if ($request->user()->account()->exists()) {
            return back()->with('error', 'A website is already provisioned for your account.');
        }

        $this->purgeTrashedDomain($request->input('domain'));

        $data = $request->validate([
            'domain'      => ['required', 'string', 'max:253', Rule::unique('domains', 'domain')->whereNull('deleted_at'), 'regex:/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
            'php_version' => ['required', 'in:8.1,8.2,8.3,8.4'],
        ]);

        $node = Node::where('status', 'online')->where('is_primary', true)->first()
             ?? Node::where('status', 'online')->first();

        if (! $node) {
            return back()->with('error', 'No online server node is available.');
        }

        // Derive a unique system username from the apex domain label
        $base     = strtolower(preg_replace('/[^a-z0-9]/', '', explode('.', $data['domain'])[0]));
        $base     = ltrim($base, '0123456789') ?: 'admin';
        $base     = substr($base, 0, 28);
        $username = $base;
        $suffix   = 1;
        while (Account::where('username', $username)->exists()) {
            $username = $base . $suffix++;
        }

        $account = Account::create([
            'user_id'     => $request->user()->id,
            'node_id'     => $node->id,
            'username'    => $username,
            'php_version' => $data['php_version'],
            'status'      => 'active',
        ]);

        [$ok, $err] = app(AccountProvisioner::class)->provision($account);
        if (! $ok) {
            $account->forceDelete();
            return back()->with('error', "Server account creation failed: {$err}");
        }

        $account->refresh();

        $domain = $account->domains()->create([
            'node_id'       => $node->id,
            'domain'        => $data['domain'],
            'document_root' => "/var/www/{$username}/public_html",
            'php_version'   => $account->php_version,
        ]);

        [$ok, $err] = app(DomainProvisioner::class)->provision($domain);
        if (! $ok) {
            $cleanupErrors = [];

            $domain->forceDelete();

            [$accountRemoved, $accountCleanupError] = app(AccountProvisioner::class)->deprovision($account);
            if (! $accountRemoved && $accountCleanupError) {
                $cleanupErrors[] = "Account cleanup failed: {$accountCleanupError}";
            }

            $account->forceDelete();

            $message = "Web server vhost creation failed: {$err}";
            if ($cleanupErrors !== []) {
                $message .= ' Cleanup warning: ' . implode('; ', $cleanupErrors);
            }

            return back()->with('error', $message);
        }

        return redirect()->route('admin.my-website.index')
            ->with('success', "Website provisioned as {$username}. Use File Manager to upload files.");
    }

    public function deprovision(Request $request): RedirectResponse
    {
        $account = $request->user()->account()->with(['node', 'domains'])->first();

        if (! $account) {
            return back()->with('error', 'No website is currently provisioned.');
        }

        $errors = [];

        foreach ($account->domains as $domain) {
            [$domainRemoved, $domainError] = app(DomainProvisioner::class)->deprovision($domain);
            if (! $domainRemoved) {
                $errors[] = "Domain {$domain->domain}: {$domainError}";
                continue;
            }

            $domain->forceDelete();
        }

        if ($errors !== []) {
            return back()->with('error', 'Website cleanup failed. ' . implode('; ', $errors));
        }

        [$accountRemoved, $accountError] = app(AccountProvisioner::class)->deprovision($account);
        if (! $accountRemoved) {
            return back()->with('error', "Account cleanup failed and was rolled back in the panel: {$accountError}");
        }

        $account->delete();

        return redirect()->route('admin.my-website.index')
            ->with('success', 'Website removed from the server.');
    }

    private function purgeTrashedDomain(mixed $domain): void
    {
        if (! is_string($domain) || trim($domain) === '') {
            return;
        }

        \App\Models\Domain::onlyTrashed()
            ->where('domain', strtolower(trim($domain)))
            ->forceDelete();
    }
}
