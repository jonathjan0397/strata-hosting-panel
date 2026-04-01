<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use App\Services\MailProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    // ── Domain email management ───────────────────────────────────────────────

    /**
     * Email management page for a domain.
     */
    public function domainIndex(Domain $domain): Response
    {
        $domain->load(['account.user', 'node']);

        $mailboxes  = EmailAccount::where('domain_id', $domain->id)->get();
        $forwarders = EmailForwarder::where('domain_id', $domain->id)->get();

        return Inertia::render('Admin/Email/DomainEmail', [
            'domain'     => $domain,
            'mailboxes'  => $mailboxes,
            'forwarders' => $forwarders,
        ]);
    }

    /**
     * Enable mail for a domain — provisions Postfix/Dovecot/DKIM on the node.
     */
    public function enableDomain(Domain $domain): RedirectResponse
    {
        if ($domain->mail_enabled) {
            return back()->with('error', 'Mail is already enabled for this domain.');
        }

        [$success, $error] = app(MailProvisioner::class)->enableDomain($domain);

        AuditLog::record('domain.mail_enabled', $domain, ['domain' => $domain->domain, 'success' => $success]);

        if (! $success) {
            return back()->with('error', "Mail provisioning failed: {$error}");
        }

        return redirect()->route('admin.email.domain', $domain)
            ->with('success', "Mail enabled for {$domain->domain}. Add the DNS records shown below.");
    }

    // ── Mailboxes ─────────────────────────────────────────────────────────────

    public function createMailbox(Request $request, Domain $domain): RedirectResponse
    {
        if (! $domain->mail_enabled) {
            return back()->with('error', 'Enable mail for this domain first.');
        }

        // Check account mailbox limit
        $account = $domain->account;
        if ($account->max_email_accounts > 0) {
            $current = EmailAccount::where('account_id', $account->id)->count();
            if ($current >= $account->max_email_accounts) {
                return back()->with('error', "Mailbox limit reached ({$account->max_email_accounts}).");
            }
        }

        $data = $request->validate([
            'local_part' => ['required', 'regex:/^[a-zA-Z0-9._%+\-]+$/', 'max:64'],
            'password'   => ['required', 'string', 'min:8'],
            'quota_mb'   => ['nullable', 'integer', 'min:0'],
        ]);

        [$success, $error] = app(MailProvisioner::class)->createMailbox(
            $domain,
            $data['local_part'],
            $data['password'],
            $data['quota_mb'] ?? 0
        );

        AuditLog::record('email.mailbox_created', $domain, [
            'email' => $data['local_part'] . '@' . $domain->domain,
        ]);

        if (! $success) {
            return back()->with('error', "Mailbox creation failed: {$error}");
        }

        return back()->with('success', "{$data['local_part']}@{$domain->domain} created.");
    }

    public function deleteMailbox(EmailAccount $mailbox): RedirectResponse
    {
        $domainId = $mailbox->domain_id;
        AuditLog::record('email.mailbox_deleted', $mailbox, ['email' => $mailbox->email]);

        [$success, $error] = app(MailProvisioner::class)->deleteMailbox($mailbox);

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

    // ── Forwarders ────────────────────────────────────────────────────────────

    public function createForwarder(Request $request, Domain $domain): RedirectResponse
    {
        if (! $domain->mail_enabled) {
            return back()->with('error', 'Enable mail for this domain first.');
        }

        $data = $request->validate([
            'source'      => ['required', 'email'],
            'destination' => ['required', 'email'],
        ]);

        // Validate source belongs to this domain
        if (! str_ends_with($data['source'], '@' . $domain->domain)) {
            return back()->withErrors(['source' => "Source must be @{$domain->domain}"]);
        }

        [$success, $error] = app(MailProvisioner::class)->createForwarder(
            $domain,
            $data['source'],
            $data['destination']
        );

        AuditLog::record('email.forwarder_created', $domain, $data);

        return $success
            ? back()->with('success', "Forwarder created: {$data['source']} → {$data['destination']}")
            : back()->with('error', "Forwarder failed: {$error}");
    }

    public function deleteForwarder(EmailForwarder $forwarder): RedirectResponse
    {
        $domainId = $forwarder->domain_id;
        AuditLog::record('email.forwarder_deleted', $forwarder, [
            'source' => $forwarder->source,
            'destination' => $forwarder->destination,
        ]);

        [$success, $error] = app(MailProvisioner::class)->deleteForwarder($forwarder);

        return ($success
            ? redirect()->route('admin.email.domain', $domainId)->with('success', 'Forwarder deleted.')
            : back()->with('error', "Delete failed: {$error}"));
    }
}
