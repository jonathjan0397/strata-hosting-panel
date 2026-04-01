<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FtpAccount;
use App\Services\AgentClient;
use App\Services\FtpProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FtpController extends Controller
{
    /**
     * FTP accounts management page for an account.
     */
    public function index(Account $account): Response
    {
        $account->load('node');
        $ftpAccounts = FtpAccount::where('account_id', $account->id)->latest()->get();

        return Inertia::render('Admin/Ftp/Index', [
            'account'     => $account,
            'ftpAccounts' => $ftpAccounts,
        ]);
    }

    /**
     * Create a new FTP account for the hosting account.
     */
    public function store(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'regex:/^[a-z][a-z0-9_]{1,31}$/', 'unique:ftp_accounts,username'],
            'password' => ['required', 'string', 'min:8'],
            'quota_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        $client = AgentClient::for($account->node);
        $provisioner = new FtpProvisioner($client);

        [$success, $error] = $provisioner->create(
            $account,
            $data['username'],
            $data['password'],
            $data['quota_mb'] ?? 0,
        );

        AuditLog::record('ftp.account_created', $account, ['username' => $data['username']]);

        return $success
            ? back()->with('success', "FTP account {$data['username']} created.")
            : back()->with('error', "FTP creation failed: {$error}");
    }

    /**
     * Delete an FTP account.
     */
    public function destroy(FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $ftpAccount->account;
        $client  = AgentClient::for($ftpAccount->node);
        $provisioner = new FtpProvisioner($client);

        AuditLog::record('ftp.account_deleted', $account, ['username' => $ftpAccount->username]);

        [$success, $error] = $provisioner->delete($ftpAccount);

        return $success
            ? redirect()->route('admin.accounts.ftp', $account->id)
                ->with('success', "{$ftpAccount->username} deleted.")
            : back()->with('error', "Deletion failed: {$error}");
    }

    /**
     * Change an FTP account's password.
     */
    public function changePassword(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $client = AgentClient::for($ftpAccount->node);
        $provisioner = new FtpProvisioner($client);

        [$success, $error] = $provisioner->changePassword($ftpAccount, $data['password']);

        return $success
            ? back()->with('success', "Password updated for {$ftpAccount->username}.")
            : back()->with('error', "Password change failed: {$error}");
    }
}
