<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use App\Services\MailProvisioner;
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
            'domain'     => $domain,
            'mailboxes'  => EmailAccount::where('domain_id', $domain->id)->get(),
            'forwarders' => EmailForwarder::where('domain_id', $domain->id)->get(),
        ]);
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
            'password'   => ['required', 'string', 'min:8'],
            'quota_mb'   => ['nullable', 'integer', 'min:0'],
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
            'source'      => ['required', 'email'],
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
            ? back()->with('success', "Forwarder created: {$data['source']} → {$data['destination']}")
            : back()->with('error', "Forwarder failed: {$error}");
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
}
