<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Models\FtpAccount;
use App\Models\HostingDatabase;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $account = auth()->user()->account()->with(['node', 'domains'])->first();

        if (! $account) {
            return Inertia::render('User/NoAccount');
        }

        return Inertia::render('User/Dashboard', [
            'account'       => $account,
            'domainCount'   => $account->domains->count(),
            'databaseCount' => HostingDatabase::where('account_id', $account->id)->count(),
            'emailCount'    => EmailAccount::where('account_id', $account->id)->count(),
            'ftpCount'      => FtpAccount::where('account_id', $account->id)->count(),
        ]);
    }
}
