<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);
        $domains = Domain::where('account_id', $account->id)
            ->orderBy('domain')
            ->get(['id', 'domain']);
        $mailboxes = EmailAccount::where('account_id', $account->id)
            ->orderBy('email')
            ->get(['id', 'domain_id', 'email']);

        return Inertia::render('User/Email/Delivery', [
            'account' => [
                'id' => $account->id,
                'username' => $account->username,
                'node' => $account->node?->only(['id', 'name']),
            ],
            'domains' => $domains,
            'mailboxes' => $mailboxes,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['nullable', 'exists:domains,id'],
            'mailbox_id' => ['nullable', 'exists:email_accounts,id'],
            'service' => ['nullable', 'in:postfix,dovecot,all'],
            'lines' => ['nullable', 'integer', 'min:20', 'max:300'],
        ]);

        $account = $this->account($request);

        $query = null;
        if (! empty($data['mailbox_id'])) {
            $mailbox = EmailAccount::where('account_id', $account->id)->findOrFail($data['mailbox_id']);
            $query = $mailbox->email;
        } elseif (! empty($data['domain_id'])) {
            $domain = Domain::where('account_id', $account->id)->findOrFail($data['domain_id']);
            $query = '@' . $domain->domain;
        } else {
            return response()->json([
                'error' => 'Select a domain or mailbox to search delivery logs.',
            ], 422);
        }

        $response = AgentClient::for($account->node)->mailDeliveryLog(
            $query,
            $data['service'] ?? 'postfix',
            $data['lines'] ?? 120,
        );

        if (! $response->successful()) {
            return response()->json([
                'error' => $response->body(),
            ], $response->status());
        }

        return response()->json($response->json(), $response->status());
    }

    private function account(Request $request): Account
    {
        return $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();
    }
}
