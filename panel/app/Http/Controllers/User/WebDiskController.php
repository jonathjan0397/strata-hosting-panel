<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\WebDavAccount;
use App\Services\AgentClient;
use App\Services\WebDavProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebDiskController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();

        $webDavAccounts = WebDavAccount::where('account_id', $account->id)
            ->where('active', true)
            ->orderBy('username')
            ->get(['id', 'username', 'home_dir', 'active']);

        $host = $account->node->hostname ?: $account->node->ip_address;

        return Inertia::render('User/WebDisk', [
            'account' => [
                'username' => $account->username,
                'node' => [
                    'name' => $account->node->name,
                    'hostname' => $host,
                ],
            ],
            'connection' => [
                'host' => $host,
                'url' => "https://{$host}:2078/",
                'protocol' => 'WebDAV over HTTPS',
                'port' => 2078,
                'encryption' => 'TLS required',
                'root' => "/var/www/{$account->username}",
            ],
            'webDavAccounts' => $webDavAccounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();

        abort_if($account->isSuspended(), 403);

        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,31}$/', 'unique:web_dav_accounts,username'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        [$ok, $error] = (new WebDavProvisioner(AgentClient::for($account->node)))
            ->create($account, $data['username'], $data['password']);

        return $ok
            ? back()->with('success', 'Web Disk account created.')
            : back()->withErrors(['username' => $error ?: 'Web Disk account creation failed.']);
    }

    public function destroy(Request $request, WebDavAccount $webDavAccount): RedirectResponse
    {
        $account = $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();

        abort_unless($webDavAccount->account_id === $account->id, 403);

        [$ok, $error] = (new WebDavProvisioner(AgentClient::for($account->node)))
            ->delete($webDavAccount);

        return $ok
            ? back()->with('success', 'Web Disk account deleted.')
            : back()->withErrors(['web_disk' => $error ?: 'Web Disk account deletion failed.']);
    }

    public function changePassword(Request $request, WebDavAccount $webDavAccount): RedirectResponse
    {
        $account = $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();

        abort_unless($webDavAccount->account_id === $account->id, 403);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:12'],
        ]);

        [$ok, $error] = (new WebDavProvisioner(AgentClient::for($account->node)))
            ->changePassword($webDavAccount, $data['password']);

        return $ok
            ? back()->with('success', 'Web Disk password changed.')
            : back()->withErrors(['password' => $error ?: 'Web Disk password change failed.']);
    }
}
