<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\FtpAccount;
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

        $ftpAccounts = FtpAccount::where('account_id', $account->id)
            ->where('active', true)
            ->orderBy('username')
            ->get(['id', 'username', 'home_dir', 'quota_mb']);

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
                'protocol' => 'FTPS',
                'port' => 21,
                'encryption' => 'Require explicit FTP over TLS',
                'root' => "/var/www/{$account->username}",
            ],
            'ftpAccounts' => $ftpAccounts,
        ]);
    }
}
