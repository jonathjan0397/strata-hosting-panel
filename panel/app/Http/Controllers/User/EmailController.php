<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
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
            'local_part' => ['required', 'regex:/^[a-zA-Z0-9._%+\-]+$/', 'max:64'],
            'password' => ['required', 'string', 'min:8'],
            'quota_mb' => ['nullable', 'integer', 'min:0'],
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
}
