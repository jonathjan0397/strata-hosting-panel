<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use App\Services\MailProvisioner;
use App\Services\MailSieveProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    private function account()
    {
        return auth()->user()->account()->firstOrFail();
    }

    public function index(Domain $domain): Response
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $domain->load('node');

        return Inertia::render('User/Email/Index', [
            'domain' => $domain,
            'mailboxes' => EmailAccount::where('domain_id', $domain->id)->get(),
            'forwarders' => EmailForwarder::where('domain_id', $domain->id)->get(),
            'spamActionOptions' => $this->spamActionOptions(),
            'emailDns' => $this->emailDnsState($domain),
        ]);
    }

    public function updateDomainSpamPolicy(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'spam_action' => ['required', 'in:inbox,junk,discard'],
            'apply_existing' => ['nullable', 'boolean'],
        ]);

        $previousDomainAction = $domain->mail_spam_action ?? 'inbox';
        $domain->update(['mail_spam_action' => $data['spam_action']]);

        if (! ($data['apply_existing'] ?? false)) {
            return back()->with('success', 'Domain spam default updated.');
        }

        $sieve = app(MailSieveProvisioner::class);
        $mailboxes = EmailAccount::where('domain_id', $domain->id)->get();

        foreach ($mailboxes as $mailbox) {
            $previousMailboxAction = $mailbox->spam_action ?? 'inbox';
            $mailbox->update(['spam_action' => $data['spam_action']]);

            [$success, $error] = $sieve->sync($mailbox);

            if (! $success) {
                $mailbox->update(['spam_action' => $previousMailboxAction]);
                $sieve->sync($mailbox);
                $domain->update(['mail_spam_action' => $previousDomainAction]);

                return back()->with('error', "Failed to apply spam policy to {$mailbox->email}: {$error}");
            }
        }

        return back()->with('success', 'Domain spam policy updated and applied to existing mailboxes.');
    }

    public function regenerateDomainKey(Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

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

        $dns = new DnsProvisioner(AgentClient::for($domain->node));
        if (! $dns->hasZoneForDomain($domain)) {
            return back()->with('success', 'Domain key regenerated. Publish the displayed DKIM TXT record with your DNS provider.');
        }

        [$published, $error] = $dns->updateDkimRecord($domain, $dkimRecord);

        return $published
            ? back()->with('success', 'Domain key regenerated and published to the managed DNS zone.')
            : back()->with('error', "Domain key regenerated, but DNS publishing failed: {$error}");
    }

    public function updateSpfRecord(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

        $data = $request->validate([
            'spf_record' => ['required', 'string', 'max:255', 'regex:/^v=spf1(\\s+.+)?$/i'],
        ]);

        $spfRecord = trim(preg_replace('/\s+/', ' ', $data['spf_record']));
        $previous = $domain->spf_dns_record;

        $domain->update([
            'spf_enabled' => true,
            'spf_dns_record' => $spfRecord,
        ]);

        if (! $domain->dnsZone()->exists()) {
            return back()->with('success', 'SPF record updated. Publish the displayed TXT record with your DNS provider.');
        }

        $dns = new DnsProvisioner(AgentClient::for($domain->node));
        [$published, $error] = $dns->updateSpfRecord($domain, $spfRecord);

        if (! $published) {
            $domain->update(['spf_dns_record' => $previous]);

            return back()->with('error', "SPF record was not updated: {$error}");
        }

        return back()->with('success', 'SPF record updated and published to the managed DNS zone.');
    }

    public function restoreSpfRecord(Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

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
            return back()->with('success', 'Recommended SPF record restored. Publish the displayed TXT record with your DNS provider.');
        }

        $dns = new DnsProvisioner(AgentClient::for($domain->node));
        [$published, $error] = $dns->updateSpfRecord($domain, $spfRecord);

        if (! $published) {
            $domain->update(['spf_dns_record' => $previous]);

            return back()->with('error', "Recommended SPF record was not restored: {$error}");
        }

        return back()->with('success', 'Recommended SPF record restored and published to the managed DNS zone.');
    }

    public function restoreDmarcRecord(Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

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
            return back()->with('success', 'Recommended DMARC record restored. Publish the displayed TXT record with your DNS provider.');
        }

        $zone = $domain->dnsZone()->firstOrFail();
        $dns = new DnsProvisioner(AgentClient::for($domain->node));
        [$published, $error] = $dns->addRecord($zone, '_dmarc', 'TXT', 300, [$dmarcRecord], true);

        if (! $published) {
            $domain->update(['dmarc_dns_record' => $previous]);

            return back()->with('error', "Recommended DMARC record was not restored: {$error}");
        }

        return back()->with('success', 'Recommended DMARC record restored and published to the managed DNS zone.');
    }

    public function createMailbox(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

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

        return $success
            ? back()->with('success', "{$data['local_part']}@{$domain->domain} created.")
            : back()->with('error', "Mailbox creation failed: {$error}");
    }

    public function importMailboxes(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

        $data = $request->validate([
            'csv' => ['required', 'string', 'max:20000'],
        ]);

        $created = 0;
        $errors = [];
        $rows = $this->csvRows($data['csv']);

        foreach ($rows as $row) {
            if ($this->isHeaderRow($row['fields'], ['local_part', 'password'])) {
                continue;
            }

            [$localPart, $password, $quota] = array_pad($row['fields'], 3, null);
            $localPart = trim((string) $localPart);
            $password = (string) $password;
            $quotaMb = trim((string) $quota) === '' ? 0 : (int) $quota;
            $email = "{$localPart}@{$domain->domain}";

            if (! preg_match('/^[a-zA-Z0-9._%+\-]+$/', $localPart) || strlen($localPart) > 64) {
                $errors[] = "Line {$row['line']}: invalid local part.";
                continue;
            }

            if (strlen($password) < 8) {
                $errors[] = "Line {$row['line']}: password must be at least 8 characters.";
                continue;
            }

            if ($quotaMb < 0) {
                $errors[] = "Line {$row['line']}: quota must be zero or greater.";
                continue;
            }

            if (EmailAccount::where('email', $email)->exists()) {
                $errors[] = "Line {$row['line']}: {$email} already exists.";
                continue;
            }

            if ($account->max_email_accounts > 0) {
                $current = EmailAccount::where('account_id', $account->id)->count();
                if ($current >= $account->max_email_accounts) {
                    $errors[] = "Line {$row['line']}: mailbox limit reached ({$account->max_email_accounts}).";
                    continue;
                }
            }

            [$success, $error] = app(MailProvisioner::class)->createMailbox(
                $domain,
                $localPart,
                $password,
                $quotaMb
            );

            if ($success) {
                $created++;
            } else {
                $errors[] = "Line {$row['line']}: {$email} failed: {$error}";
            }
        }

        return $this->bulkImportResponse($created, $errors, 'mailbox', 'mailboxes');
    }

    public function deleteMailbox(EmailAccount $mailbox): RedirectResponse
    {
        $account = $this->account();
        abort_unless($mailbox->account_id === $account->id, 403);

        $domainId = $mailbox->domain_id;
        [$success, $error] = app(MailProvisioner::class)->deleteMailbox($mailbox);

        return $success
            ? redirect()->route('my.email.domain', $domainId)->with('success', "{$mailbox->email} deleted.")
            : back()->with('error', "Deletion failed: {$error}");
    }

    public function changePassword(Request $request, EmailAccount $mailbox): RedirectResponse
    {
        $account = $this->account();
        abort_unless($mailbox->account_id === $account->id, 403);

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
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
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

        return $success
            ? back()->with('success', "Forwarder created: {$data['source']} -> {$data['destination']}")
            : back()->with('error', "Forwarder failed: {$error}");
    }

    public function importForwarders(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        if (! $domain->mail_enabled) {
            return back()->with('error', 'Mail is not enabled for this domain.');
        }

        $data = $request->validate([
            'csv' => ['required', 'string', 'max:20000'],
        ]);

        $created = 0;
        $errors = [];
        $rows = $this->csvRows($data['csv']);

        foreach ($rows as $row) {
            if ($this->isHeaderRow($row['fields'], ['source', 'destination'])) {
                continue;
            }

            [$source, $destination] = array_pad($row['fields'], 2, null);
            $source = trim((string) $source);
            $destination = trim((string) $destination);

            if ($source !== '' && ! str_contains($source, '@')) {
                $source = "{$source}@{$domain->domain}";
            }

            if (! filter_var($source, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Line {$row['line']}: invalid source address.";
                continue;
            }

            if (! str_ends_with($source, '@' . $domain->domain)) {
                $errors[] = "Line {$row['line']}: source must be @{$domain->domain}.";
                continue;
            }

            if (! filter_var($destination, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Line {$row['line']}: invalid destination address.";
                continue;
            }

            if (EmailForwarder::where('source', $source)->where('destination', $destination)->exists()) {
                $errors[] = "Line {$row['line']}: {$source} -> {$destination} already exists.";
                continue;
            }

            [$success, $error] = app(MailProvisioner::class)->createForwarder(
                $domain,
                $source,
                $destination
            );

            if ($success) {
                $created++;
            } else {
                $errors[] = "Line {$row['line']}: {$source} failed: {$error}";
            }
        }

        return $this->bulkImportResponse($created, $errors, 'forwarder', 'forwarders');
    }

    public function deleteForwarder(EmailForwarder $forwarder): RedirectResponse
    {
        $account = $this->account();
        abort_unless($forwarder->domain->account_id === $account->id, 403);

        $domainId = $forwarder->domain_id;
        [$success, $error] = app(MailProvisioner::class)->deleteForwarder($forwarder);

        return $success
            ? redirect()->route('my.email.domain', $domainId)->with('success', 'Forwarder deleted.')
            : back()->with('error', "Delete failed: {$error}");
    }

    private function csvRows(string $csv): array
    {
        $rows = [];
        $lines = preg_split('/\R/', $csv);

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = [
                'line' => $index + 1,
                'fields' => array_map('trim', str_getcsv($line)),
            ];
        }

        return $rows;
    }

    private function isHeaderRow(array $fields, array $expected): bool
    {
        $normalized = array_map(fn ($field) => strtolower(trim((string) $field)), $fields);

        foreach ($expected as $index => $header) {
            if (($normalized[$index] ?? null) !== $header) {
                return false;
            }
        }

        return true;
    }

    private function bulkImportResponse(int $created, array $errors, string $singular, string $plural): RedirectResponse
    {
        $response = back();

        if ($created > 0) {
            $label = $created === 1 ? $singular : $plural;
            $response = $response->with('success', "Imported {$created} {$label}.");
        }

        if ($errors !== []) {
            $message = implode(' ', array_slice($errors, 0, 8));
            if (count($errors) > 8) {
                $message .= ' ' . (count($errors) - 8) . ' more rows failed.';
            }

            $response = $response->with('error', $message);
        }

        if ($created === 0 && $errors === []) {
            $response = $response->with('error', 'No importable rows were found.');
        }

        return $response;
    }

    private function spamActionOptions(): array
    {
        return [
            ['value' => 'inbox', 'label' => 'Leave in inbox'],
            ['value' => 'junk', 'label' => 'Move to Junk'],
            ['value' => 'discard', 'label' => 'Discard spam'],
        ];
    }

    private function emailDnsState(Domain $domain): array
    {
        $domain->loadMissing('dnsZone.records', 'node');

        return [
            'managed_dns' => $domain->dnsZone !== null,
            'dkim' => [
                'selector' => 'default',
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
                'recommended' => $this->recommendedSpfRecord($domain),
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

        $values = array_map('trim', preg_split('/\R/', (string) $record->value, -1, PREG_SPLIT_NO_EMPTY));

        return in_array(trim($value), $values, true);
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
