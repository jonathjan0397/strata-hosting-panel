<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;

class WebmailController extends Controller
{
    /**
     * Generate a one-time SSO token for a mailbox and redirect to the SSO bridge.
     *
     * The SSO bridge (sso.php) validates the token, retrieves the credentials,
     * calls SnappyMail's CreateUserSsoHash, and issues the final redirect.
     */
    public function sso(Request $request, EmailAccount $mailbox): RedirectResponse
    {
        // Verify the authenticated user owns this mailbox
        $user = $request->user();

        $ownsMailbox = $user->isAdmin()
            || ($user->account && $mailbox->account_id === $user->account->id)
            || $user->resellerClients()
                ->whereHas('account', fn ($q) => $q->where('id', $mailbox->account_id))
                ->exists();

        abort_unless($ownsMailbox, 403);

        // Need an encrypted password to SSO — falls back to login page if not set
        if (! $mailbox->password_encrypted) {
            return redirect('/webmail/')->with(
                'error',
                'Open Webmail and log in manually. SSO requires a password change after migration.'
            );
        }

        $password = Crypt::decrypt($mailbox->password_encrypted);

        // Build one-time token payload
        $timestamp = time();
        $hmac = hash_hmac(
            'sha256',
            $mailbox->email . '|' . $timestamp,
            config('services.webmail.sso_secret')
        );

        $token = bin2hex(random_bytes(32)); // 64-char hex token

        Redis::setex('webmail_sso:' . $token, 60, json_encode([
            'email'     => $mailbox->email,
            'password'  => $password,
            'timestamp' => $timestamp,
            'hmac'      => $hmac,
        ]));

        return redirect('/webmail/sso.php?token=' . $token);
    }
}
