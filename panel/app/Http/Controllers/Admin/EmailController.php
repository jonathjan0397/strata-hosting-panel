<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use App\Services\MailProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    /**
     * Email management page for a domain.
     */
    public function domainIndex(Domain $domain): Response
    {
        $domain->load(['account.user', 'node', 'dnsZone.records']);

        $mailboxes = EmailAccount::where('domain_id', $domain->id)->get();
        $forwarders = EmailForwarder::where('domain_id', $domain->id)->get();

        return Inertia::render('Admin/Email/DomainEmail', [
            'domain' => $domain,
            'mailboxes' => $mailboxes,
            'forwarders' => $forwarders,
            'emailDns' => $this->emailDnsState($domain),
        ]);
    }

    /**
     * Enable mail for a domain.
     */
    public function enableDomain(Domain $domain): RedirectResponse
    {
        if ($domain->mail_enabled) {
            return back()->with('error', 'Mail is already enabled for this domain.');
        }

        [$success, $error] = app(MailProvisioner::class)->enableDomain($domain);

        if (! $success) {
            return back()->with('error', "Mail provisioning failed: {$error}");
        }

        (new DnsProvisioner(AgentClient::for($domain->node)))->addMailRecords($domain->refresh());

        AuditLog::record('domain.mail_enabled', $domain, ['domain' => $domain->domain, 'success' => true]);

        return redirect()->route('admin.email.domain', $domain)
            ->with('success', "Mail enabled for {$domain->domain}. Add the DNS records shown below.");
    }

    public function regenerateDomainKey(Domain $domain): RedirectResponse
    {
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

        $data = $response->json();
        $dkimRecord = $data['dkim_pubkey'] ?? null;

        if (! $dkimRecord) {
            return back()->with('error', 'DKIM regeneration did not return a public key.');
        }

        $domain->update([
            'dkim_enabled' => true,
            'dkim_public_key' => $dkimRecord,
            'dkim_dns_record' => $dkimRecord,
        ]);

        $zone = $domain->dnsZone()->first();
        if (! $zone) {
            AuditLog::record('email.domain_key_regenerated', $domain, ['published' => false]);

            return back()->with('success', 'Domain key regenerated. Publish the displayed DKIM TXT record with your DNS provider.');
        }

        $dns = new DnsProvisioner(AgentClient::for($domain->node));
        [$published, $error] = $dns->addRecord($zone, 'default._domainkey', 'TXT', 300, [$dkimRecord], true);

        AuditLog::record('email.domain_key_regenerated', $domain, ['published' => $published]);

        return $published
            ? back()->with('success', 'Domain key regenerated and published to the managed DNS zone.')
            : back()->with('error', "Domain key regenerated, but DNS publishing failed: {$error}");
    }

    public function restoreSpfRecord(Domain $domain): RedirectResponse
    {
        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

        $spfRecord = $this->recommendedSpfRecord($domain);
        $previous = $domain->spf_dns_record;

        $domain->update([
            'spf_enabled' => true,
            'spf_dns_record' => $spfRecord,
        ]);

        if (! $domain->dnsZone()->exists()) {
            AuditLog::record('email.spf_restored', $domain, ['published' => false]);

            return back()->with('success', 'Recommended SPF record restored. Publish the displayed TXT record with your DNS provider.');
        }

        $dns = new DnsProvisioner(AgentClient::for($domain->node));
        [$published, $error] = $dns->updateSpfRecord($domain, $spfRecord);

        if (! $published) {
            $domain->update(['spf_dns_record' => $previous]);

            return back()->with('error', "Recommended SPF record was not restored: {$error}");
        }

        AuditLog::record('email.spf_restored', $domain, ['published' => true]);

        return back()->with('success', 'Recommended SPF record restored and published to the managed DNS zone.');
    }

    public function restoreDmarcRecord(Domain $domain): RedirectResponse
    {
        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

        $dmarcRecord = $this->recommendedDmarcRecord($domain);
        $previous = $domain->dmarc_dns_record;

        $domain->update([
            'dmarc_enabled' => true,
            'dmarc_dns_record' => $dmarcRecord,
        ]);

        if (! $domain->dnsZone()->exists()) {
            AuditLog::record('email.dmarc_restored', $domain, ['published' => false]);

            return back()->with('success', 'Recommended DMARC record restored. Publish the displayed TXT record with your DNS provider.');
        }

        $zone = $domain->dnsZone()->firstOrFail();
        $dns = new DnsProvisioner(AgentClient::for($domain->node));
        [$published, $error] = $dns->addRecord($zone, '_dmarc', 'TXT', 300, [$dmarcRecord], true);

        if (! $published) {
            $domain->update(['dmarc_dns_record' => $previous]);

            return back()->with('error', "Recommended DMARC record was not restored: {$error}");
        }

        AuditLog::record('email.dmarc_restored', $domain, ['published' => true]);

        return back()->with('success', 'Recommended DMARC record restored and published to the managed DNS zone.');
    }

    public function createMailbox(Request $request, Domain $domain): RedirectResponse
    {
        if (! $domain->mail_enabled) {
            return back()->with('error', 'Enable mail for this domain first.');
        }

        $account = $domain->account;
        if ($account->max_email_accounts > 0) {
            $current = EmailAccount::where('account_id', $account->id)->count();
            if ($current >= $account->max_email_accounts) {
                return back()->with('error', "Mailbox limit reached ({$account->max_email_accounts}).");
            }
        }

        $data = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'not_regex:/@/', 'regex:/^[a-zA-Z0-9._%+\-]+$/'],
            'password' => ['required', 'string', 'min:8'],
            'quota_mb' => ['nullable', 'integer', 'min:0'],
        ], [
            'local_part.not_regex' => 'Enter only the mailbox name before the @ sign, not the full email address.',
            'local_part.regex' => 'Mailbox names can only contain letters, numbers, dots, underscores, percent signs, plus signs, and hyphens.',
        ]);

        [$success, $error] = app(MailProvisioner::class)->createMailbox(
            $domain,
            $data['local_part'],
            $data['password'],
            $data['quota_mb'] ?? 0
        );

        if (! $success) {
            return back()->with('error', "Mailbox creation failed: {$error}");
        }

        AuditLog::record('email.mailbox_created', $domain, [
            'email' => $data['local_part'] . '@' . $domain->domain,
        ]);

        return back()->with('success', "{$data['local_part']}@{$domain->domain} created.");
    }

    public function deleteMailbox(EmailAccount $mailbox): RedirectResponse
    {
        $domainId = $mailbox->domain_id;
        [$success, $error] = app(MailProvisioner::class)->deleteMailbox($mailbox);

        if ($success) {
            AuditLog::record('email.mailbox_deleted', $mailbox, ['email' => $mailbox->email]);
        }

        $redirect = redirect()->route('admin.email.domain', $domainId);

        return $success
            ? $redirect->with('success', "{$mailbox->email} deleted.")
            : $redirect->with('error', "Deletion failed: {$error}");
    }

    public function changePassword(Request $request, EmailAccount $mailbox): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        [$success, $error] = app(MailProvisioner::class)->changePassword($mailbox, $data['password']);

        return $success
            ? back()->with('success', "Password updated for {$mailbox->email}.")
            : back()->with('error', "Password change failed: {$error}");
    }

    public function createForwarder(Request $request, Domain $domain): RedirectResponse
    {
        if (! $domain->mail_enabled) {
            return back()->with('error', 'Enable mail for this domain first.');
        }

        $data = $request->validate([
            'source' => ['required', 'email'],
            'destination' => ['required', 'email'],
        ]);

        if (! str_ends_with($data['source'], '@' . $domain->domain)) {
            return back()->withErrors(['source' => "Source must be @{$domain->domain}"]);
        }

        [$success, $error] = app(MailProvisioner::class)->createForwarder(
            $domain,
            $data['source'],
            $data['destination']
        );

        if ($success) {
            AuditLog::record('email.forwarder_created', $domain, $data);
        }

        return $success
            ? back()->with('success', "Forwarder created: {$data['source']} -> {$data['destination']}")
            : back()->with('error', "Forwarder failed: {$error}");
    }

    public function deleteForwarder(EmailForwarder $forwarder): RedirectResponse
    {
        $domainId = $forwarder->domain_id;
        [$success, $error] = app(MailProvisioner::class)->deleteForwarder($forwarder);

        if ($success) {
            AuditLog::record('email.forwarder_deleted', $forwarder, [
                'source' => $forwarder->source,
                'destination' => $forwarder->destination,
            ]);
        }

        return $success
            ? redirect()->route('admin.email.domain', $domainId)->with('success', 'Forwarder deleted.')
            : back()->with('error', "Delete failed: {$error}");
    }

    private function emailDnsState(Domain $domain): array
    {
        return [
            'managed_dns' => $domain->dnsZone !== null,
            'dkim' => [
                'host' => 'default._domainkey',
                'fqdn' => "default._domainkey.{$domain->domain}",
                'type' => 'TXT',
                'value' => $domain->dkim_dns_record,
                'published' => $this->recordContains($domain, 'default._domainkey', 'TXT', $domain->dkim_dns_record),
            ],
            'spf' => [
                'host' => '@',
                'fqdn' => $domain->domain,
                'type' => 'TXT',
                'value' => $domain->spf_dns_record,
                'published' => $this->recordContains($domain, '@', 'TXT', $domain->spf_dns_record),
            ],
            'dmarc' => [
                'host' => '_dmarc',
                'fqdn' => "_dmarc.{$domain->domain}",
                'type' => 'TXT',
                'value' => $domain->dmarc_dns_record,
                'published' => $this->recordContains($domain, '_dmarc', 'TXT', $domain->dmarc_dns_record),
            ],
        ];
    }

    private function recordContains(Domain $domain, string $name, string $type, ?string $value): bool
    {
        if (! $domain->dnsZone || ! $value) {
            return false;
        }

        $record = $domain->dnsZone->records
            ->first(fn (DnsRecord $record) => $record->name === $name && $record->type === $type);

        if (! $record) {
            return false;
        }

        $values = preg_split('/\R/', (string) $record->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(trim($value), array_map('trim', $values), true);
    }

    private function recommendedSpfRecord(Domain $domain): string
    {
        $serverIp = $domain->server_ip ?: $domain->node?->ip_address;

        return $serverIp ? "v=spf1 a mx ip4:{$serverIp} -all" : 'v=spf1 a mx -all';
    }

    private function recommendedDmarcRecord(Domain $domain): string
    {
        return "v=DMARC1; p=quarantine; pct=100; rua=mailto:postmaster@{$domain->domain}";
    }
}
