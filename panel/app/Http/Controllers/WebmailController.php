<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebmailController extends Controller
{
    public function sso(Request $request, EmailAccount $mailbox): RedirectResponse
    {
        $user = $request->user();

        $ownsMailbox = $user->isAdmin()
            || ($user->account && $mailbox->account_id === $user->account->id)
            || $user->resellerClients()
                ->whereHas('account', fn ($q) => $q->where('id', $mailbox->account_id))
                ->exists();

        abort_unless($ownsMailbox, 403);

        return redirect('/webmail/')->with(
            'error',
            'Automatic webmail sign-in is disabled. Open Webmail and sign in manually.'
        );
    }
}
