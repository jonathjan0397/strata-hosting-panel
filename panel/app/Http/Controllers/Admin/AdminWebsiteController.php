<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionAdminWebsite;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AccountProvisioner;
use App\Services\DomainProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
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
                'provisioning_error' => $account->provisioning_error,
                'node'        => $account->node?->only('id', 'name', 'hostname'),
                'domain'      => $account->domains->first()?->only('id', 'domain', 'ssl_enabled', 'ssl_expires_at'),
            ] : null,
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
            'primaryNode' => $primaryNode?->only('id', 'name', 'hostname'),
        ]);
    }

    public function provision(Request $request): RedirectResponse
    {
        $this->purgeTrashedDomain($request->input('domain'));
        $this->purgeTrashedWebsiteAccount($request);

        $data = $request->validate([
            'domain'      => ['required', 'string', 'max:253', Rule::unique('domains', 'domain')->whereNull('deleted_at'), 'regex:/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
            'php_version' => ['required', 'in:8.1,8.2,8.3,8.4'],
        ]);

        $existingAccount = $request->user()
            ->account()
            ->with(['node', 'domains'])
            ->first();

        if ($existingAccount && $existingAccount->domains->isNotEmpty()) {
            return back()->with('error', 'A website is already provisioned for your account.');
        }

        if ($existingAccount?->status === 'provisioning') {
            return back()->with('error', 'Website provisioning is already running. Refresh this page in a moment.');
        }

        $node = $existingAccount?->node
            ?? Node::where('status', 'online')->where('is_primary', true)->first()
            ?? Node::where('status', 'online')->first();

        if (! $node) {
            return back()->with('error', 'No online server node is available.');
        }

        if ($existingAccount && $node->status !== 'online') {
            return back()->with('error', 'Your existing website account is assigned to an offline node. Bring it online or remove the partial website before retrying.');
        }

        if ($existingAccount) {
            if ($existingAccount->status !== 'active' && $existingAccount->php_version !== $data['php_version']) {
                $existingAccount->update(['php_version' => $data['php_version']]);
            }

            $existingAccount->update([
                'status' => 'provisioning',
                'provisioning_error' => null,
            ]);

            ProvisionAdminWebsite::dispatch(
                $existingAccount->id,
                strtolower($data['domain']),
                $request->user()->id,
                ! $existingAccount->isActive(),
                false,
            );

            AuditLog::record('admin_website.provisioning_queued', $existingAccount, [
                'username' => $existingAccount->username,
                'node' => $existingAccount->node_id,
                'domain' => strtolower($data['domain']),
                'queued' => true,
                'resume' => true,
            ]);

            return redirect()->route('admin.my-website.index')
                ->with('success', 'Website setup was queued. Refresh this page in a moment.');
        }

        try {
            $account = Account::create([
                'user_id'     => $request->user()->id,
                'node_id'     => $node->id,
                'username'    => $this->uniqueUsernameForDomain($data['domain']),
                'php_version' => $data['php_version'],
                'status'      => 'provisioning',
                'provisioning_error' => null,
            ]);
        } catch (QueryException $exception) {
            return back()->with('error', 'Website setup could not start because the generated system username is already in use. Please retry once; if it happens again, remove any stale website account and try again.');
        }

        ProvisionAdminWebsite::dispatch(
            $account->id,
            strtolower($data['domain']),
            $request->user()->id,
            true,
            false,
        );

        AuditLog::record('admin_website.provisioning_queued', $account, [
            'username' => $account->username,
            'node' => $account->node_id,
            'domain' => strtolower($data['domain']),
            'queued' => true,
            'resume' => false,
        ]);

        return redirect()->route('admin.my-website.index')
            ->with('success', "Website provisioning was queued as {$account->username}. Refresh this page in a moment.");
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

        $account->forceDelete();

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

    private function purgeTrashedWebsiteAccount(Request $request): void
    {
        Account::onlyTrashed()
            ->where('user_id', $request->user()->id)
            ->whereDoesntHave('domains')
            ->get()
            ->each
            ->forceDelete();
    }

    private function uniqueUsernameForDomain(string $domain): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', explode('.', $domain)[0]));
        $base = ltrim($base, '0123456789') ?: 'admin';
        $base = substr($base, 0, 28);
        $username = $base;
        $suffix = 1;

        while (Account::withTrashed()->where('username', $username)->exists()) {
            $username = $base . $suffix++;
        }

        return $username;
    }
}
