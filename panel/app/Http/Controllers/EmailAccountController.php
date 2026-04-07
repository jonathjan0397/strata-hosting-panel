<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\EmailAccount;
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
            ->with(['account.user', 'node'])
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
                ],
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
}
