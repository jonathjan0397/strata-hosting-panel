<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\EmailAccount;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use App\Services\MailProvisioner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $domains = $this->domainQuery($request)
            ->with(['account.user', 'node', 'dnsZone.records'])
            ->orderBy('domain')
            ->get();

        $domainIds = $domains->pluck('id');

        return Inertia::render('Email/Accounts', [
            'domains' => $domains->map(fn (Domain $domain) => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'mail_enabled' => $domain->mail_enabled,
                'node' => $domain->node?->only(['id', 'name', 'hostname']),
                'account' => [
                    'id' => $domain->account?->id,
                    'username' => $domain->account?->username,
                    'owner' => $domain->account?->user?->email,
                    'max_email_accounts' => $domain->account?->max_email_accounts,
                    'email_accounts_count' => $domain->account?->emailAccounts()->count(),
                ],
                'dkim' => [
                    'enabled' => $domain->dkim_enabled,
                    'selector' => 'default',
                    'host' => 'default._domainkey',
                    'fqdn' => "default._domainkey.{$domain->domain}",
                    'type' => 'TXT',
                    'value' => $domain->dkim_dns_record,
                    'published' => $this->recordContains($domain, 'default._domainkey', 'TXT', $domain->dkim_dns_record),
                ],
                'managed_dns' => $domain->dnsZone !== null,
            ]),
            'mailboxes' => EmailAccount::query()
                ->with(['domain.account.user', 'domain.node'])
                ->whereIn('domain_id', $domainIds)
                ->orderBy('email')
                ->get()
                ->map(fn (EmailAccount $mailbox) => [
                    'id' => $mailbox->id,
                    'email' => $mailbox->email,
                    'quota_mb' => $mailbox->quota_mb,
                    'used_mb' => $mailbox->used_mb,
                    'active' => $mailbox->active,
                    'migration_reset_required' => $mailbox->migration_reset_required,
                    'domain' => [
                        'id' => $mailbox->domain?->id,
                        'domain' => $mailbox->domain?->domain,
                        'node' => $mailbox->domain?->node?->only(['id', 'name', 'hostname']),
                    ],
                    'account' => [
                        'username' => $mailbox->domain?->account?->username,
                        'owner' => $mailbox->domain?->account?->user?->email,
                    ],
                ]),
            'forwarders' => \App\Models\EmailForwarder::query()
                ->with(['domain.account.user'])
                ->whereIn('domain_id', $domainIds)
                ->orderBy('source')
                ->get()
                ->map(fn ($forwarder) => [
                    'id' => $forwarder->id,
                    'source' => $forwarder->source,
                    'destination' => $forwarder->destination,
                    'domain' => $forwarder->domain?->only(['id', 'domain']),
                ]),
            'role' => $request->user()->getRoleNames()->first(),
        ]);
    }

    public function enableDomain(Request $request, Domain $domain): RedirectResponse
    {
        $domain = $this->domainQuery($request)
            ->with('dnsZone')
            ->where('id', $domain->id)
            ->firstOrFail();

        abort_unless($domain->account?->hasFeature('email'), 403);

        if ($domain->mail_enabled) {
            return back()->with('success', "Mail is already enabled for {$domain->domain}.");
        }

        [$success, $error, $dns] = app(MailProvisioner::class)->enableDomain($domain);

        if (! $success) {
            return back()->with('error', "Mail setup failed: {$error}");
        }

        (new DnsProvisioner(AgentClient::for($domain->node)))->addMailRecords($domain->refresh());

        return back()->with('success', "Mail enabled for {$domain->domain}. DKIM, SPF, and DMARC records are ready.");
    }

    public function regenerateDomainKey(Request $request, Domain $domain): RedirectResponse
    {
        $domain = $this->domainQuery($request)
            ->with(['node', 'dnsZone'])
            ->where('id', $domain->id)
            ->firstOrFail();

        abort_unless($domain->account?->hasFeature('email'), 403);

        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

        try {
            $response = AgentClient::for($domain->node)->regenerateDkim($domain->domain);
        } catch (\Throwable $e) {
            return back()->with('error', "DKIM regeneration failed: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return back()->with('error', "DKIM regeneration failed: {$response->body()}");
        }

        $dkimRecord = $response->json('dkim_pubkey');
        if (! $dkimRecord) {
            return back()->with('error', 'DKIM regeneration did not return a public key.');
        }

        $domain->update([
            'dkim_enabled' => true,
            'dkim_public_key' => $dkimRecord,
            'dkim_dns_record' => $dkimRecord,
        ]);

        if (! $domain->dnsZone) {
            return back()->with('success', 'Domain key regenerated. Publish the displayed DKIM TXT record with your DNS provider.');
        }

        [$published, $error] = (new DnsProvisioner(AgentClient::for($domain->node)))
            ->addRecord($domain->dnsZone, 'default._domainkey', 'TXT', 300, [$dkimRecord], true);

        return $published
            ? back()->with('success', 'Domain key regenerated and published to the managed DNS zone.')
            : back()->with('error', "Domain key regenerated, but DNS publishing failed: {$error}");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer'],
            'local_part' => ['required', 'regex:/^[a-zA-Z0-9._%+\-]+$/', 'max:64'],
            'password' => ['required', 'string', 'min:8'],
            'quota_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        $domain = $this->domainQuery($request)
            ->where('id', $data['domain_id'])
            ->firstOrFail();

        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

        $account = $domain->account;
        if ($account?->max_email_accounts > 0) {
            $current = EmailAccount::where('account_id', $account->id)->count();
            if ($current >= $account->max_email_accounts) {
                return back()->with('error', "Mailbox limit reached ({$account->max_email_accounts}).");
            }
        }

        [$success, $error] = app(MailProvisioner::class)->createMailbox(
            $domain,
            $data['local_part'],
            $data['password'],
            $data['quota_mb'] ?? 0
        );

        return $success
            ? back()->with('success', "{$data['local_part']}@{$domain->domain} created.")
            : back()->with('error', "Mailbox creation failed: {$error}");
    }

    public function changePassword(Request $request, EmailAccount $mailbox): RedirectResponse
    {
        $this->authorizeMailbox($request, $mailbox);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        [$success, $error] = app(MailProvisioner::class)->changePassword($mailbox, $data['password']);

        return $success
            ? back()->with('success', "Password updated for {$mailbox->email}.")
            : back()->with('error', "Password change failed: {$error}");
    }

    public function destroy(Request $request, EmailAccount $mailbox): RedirectResponse
    {
        $this->authorizeMailbox($request, $mailbox);

        [$success, $error] = app(MailProvisioner::class)->deleteMailbox($mailbox);

        return $success
            ? back()->with('success', "{$mailbox->email} deleted.")
            : back()->with('error', "Deletion failed: {$error}");
    }

    private function domainQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = Domain::query()->with('account');

        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isReseller()) {
            return $query->whereHas('account', fn (Builder $accountQuery) =>
                $accountQuery->where('reseller_id', $user->id)
            );
        }

        $account = $user->account()->first();
        abort_unless($account?->hasFeature('email'), 403);

        return $query->where('account_id', $account->id);
    }

    private function authorizeMailbox(Request $request, EmailAccount $mailbox): void
    {
        $domain = $this->domainQuery($request)
            ->where('id', $mailbox->domain_id)
            ->first();

        abort_unless($domain, 403);
    }

    private function recordContains(Domain $domain, string $name, string $type, ?string $value): bool
    {
        if (! $domain->dnsZone || ! $value) {
            return false;
        }

        $record = $domain->dnsZone->records
            ->first(fn ($record) => $record->name === $name && $record->type === $type);

        if (! $record) {
            return false;
        }

        $values = preg_split('/\R/', (string) $record->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(trim($value), array_map('trim', $values), true);
    }
}
