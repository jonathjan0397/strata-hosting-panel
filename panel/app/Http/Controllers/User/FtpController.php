<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\FtpAccount;
use App\Services\AgentClient;
use App\Services\FtpProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FtpController extends Controller
{
    private function account()
    {
        return auth()->user()->account()->with('node')->firstOrFail();
    }

    public function index(): Response
    {
        $account     = $this->account();
        $ftpAccounts = FtpAccount::where('account_id', $account->id)->latest()->get();

        return Inertia::render('User/Ftp/Index', [
            'account'     => $account,
            'ftpAccounts' => $ftpAccounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->account();

        if ($account->isSuspended()) {
            return back()->with('error', 'Your account is suspended.');
        }

        $data = $request->validate([
            'username' => ['required', 'regex:/^[a-z][a-z0-9_]{1,31}$/', 'unique:ftp_accounts,username'],
            'password' => ['required', 'string', 'min:8'],
            'quota_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        $provisioner = new FtpProvisioner(AgentClient::for($account->node));

        [$success, $error] = $provisioner->create(
            $account,
            $data['username'],
            $data['password'],
            $data['quota_mb'] ?? 0,
        );

        return $success
            ? back()->with('success', "FTP account {$data['username']} created.")
            : back()->with('error', "FTP creation failed: {$error}");
    }

    public function destroy(FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->account();
        abort_unless($ftpAccount->account_id === $account->id, 403);

        $provisioner = new FtpProvisioner(AgentClient::for($ftpAccount->node));
        [$success, $error] = $provisioner->delete($ftpAccount);

        return $success
            ? redirect()->route('my.ftp.index')->with('success', "{$ftpAccount->username} deleted.")
            : back()->with('error', "Deletion failed: {$error}");
    }

    public function changePassword(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->account();
        abort_unless($ftpAccount->account_id === $account->id, 403);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $provisioner = new FtpProvisioner(AgentClient::for($ftpAccount->node));
        [$success, $error] = $provisioner->changePassword($ftpAccount, $data['password']);

        return $success
            ? back()->with('success', "Password updated for {$ftpAccount->username}.")
            : back()->with('error', "Password change failed: {$error}");
    }
}
