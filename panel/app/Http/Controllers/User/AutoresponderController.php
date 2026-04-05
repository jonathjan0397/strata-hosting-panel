<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Autoresponder;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutoresponderController extends Controller
{
    private function account()
    {
        return auth()->user()->account()->with('node')->firstOrFail();
    }

    public function index(Domain $domain): Response
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $mailboxes = EmailAccount::where('domain_id', $domain->id)
            ->with('autoresponder')
            ->get();

        return Inertia::render('User/Email/Autoresponders', [
            'domain'    => $domain,
            'mailboxes' => $mailboxes,
        ]);
    }

    public function store(Request $request, EmailAccount $emailAccount): RedirectResponse
    {
        $account = $this->account();
        abort_unless($emailAccount->account_id === $account->id, 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body'    => ['required', 'string', 'max:4096'],
            'active'  => ['boolean'],
        ]);

        $data['active'] = $request->boolean('active', true);

        $autoresponder = Autoresponder::updateOrCreate(
            ['email_account_id' => $emailAccount->id],
            $data,
        );

        $client = AgentClient::for($account->node);
        $response = $client->autoresponderSet(
            $emailAccount->email,
            $data['subject'],
            $data['body'],
            $data['active'],
        );

        if (! $response->successful()) {
            $autoresponder->delete();
            return back()->with('error', 'Failed to set autoresponder: ' . $response->body());
        }

        return back()->with('success', 'Autoresponder saved.');
    }

    public function destroy(EmailAccount $emailAccount): RedirectResponse
    {
        $account = $this->account();
        abort_unless($emailAccount->account_id === $account->id, 403);

        Autoresponder::where('email_account_id', $emailAccount->id)->delete();

        $client = AgentClient::for($account->node);
        $client->autoresponderDelete($emailAccount->email);

        return back()->with('success', 'Autoresponder removed.');
    }
}
