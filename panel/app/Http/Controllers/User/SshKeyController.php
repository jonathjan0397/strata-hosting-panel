<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\SshKey;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SshKeyController extends Controller
{
    private function account()
    {
        return auth()->user()->account()->with('node')->firstOrFail();
    }

    public function index(): Response
    {
        $account = $this->account();
        $keys = SshKey::where('account_id', $account->id)->latest()->get();

        return Inertia::render('User/Security/Index', [
            'keys' => $keys,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->account();

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'public_key' => ['required', 'string'],
        ]);

        $client   = AgentClient::for($account->node);
        $response = $client->sshKeyAdd($account->username, $data['name'], $data['public_key']);

        if (! $response->successful()) {
            return back()->with('error', 'Failed to add key: ' . $response->body());
        }

        $fp = $response->json('fingerprint');

        SshKey::create([
            'account_id'  => $account->id,
            'name'        => $data['name'],
            'public_key'  => $data['public_key'],
            'fingerprint' => $fp,
        ]);

        return back()->with('success', 'SSH key added.');
    }

    public function destroy(SshKey $sshKey): RedirectResponse
    {
        $account = $this->account();
        abort_unless($sshKey->account_id === $account->id, 403);

        $client = AgentClient::for($account->node);
        $client->sshKeyDelete($account->username, $sshKey->fingerprint);

        $sshKey->delete();

        return back()->with('success', 'SSH key removed.');
    }
}
