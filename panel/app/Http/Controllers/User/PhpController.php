<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PhpController extends Controller
{
    private function account(Request $request): Account
    {
        return Account::where('user_id', $request->user()->id)->with('node')->firstOrFail();
    }

    public function index(Request $request): Response
    {
        $account = $this->account($request);

        return Inertia::render('User/Php/Index', [
            'account' => $account->only([
                'id', 'php_version',
                'php_upload_max', 'php_post_max', 'php_memory_limit', 'php_max_exec_time',
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'php_upload_max'   => ['required', 'regex:/^\d+[KMGkmg]?$/'],
            'php_post_max'     => ['required', 'regex:/^\d+[KMGkmg]?$/'],
            'php_memory_limit' => ['required', 'regex:/^\d+[KMGkmg]?$/'],
            'php_max_exec_time' => ['required', 'integer', 'min:1', 'max:300'],
        ]);

        $account = $this->account($request);

        $response = AgentClient::for($account->node)->updatePhpSettings(
            $account->username,
            $account->php_version,
            [
                'upload_max'   => strtoupper($data['php_upload_max']),
                'post_max'     => strtoupper($data['php_post_max']),
                'memory_limit' => strtoupper($data['php_memory_limit']),
                'max_exec_time' => (int) $data['php_max_exec_time'],
            ]
        );

        if (! $response->successful()) {
            return back()->with('error', 'Failed to update PHP settings: ' . $response->body());
        }

        $account->update([
            'php_upload_max'    => strtoupper($data['php_upload_max']),
            'php_post_max'      => strtoupper($data['php_post_max']),
            'php_memory_limit'  => strtoupper($data['php_memory_limit']),
            'php_max_exec_time' => (int) $data['php_max_exec_time'],
        ]);

        AuditLog::record('php.settings_updated', $account, $data);

        return back()->with('success', 'PHP settings updated.');
    }
}
