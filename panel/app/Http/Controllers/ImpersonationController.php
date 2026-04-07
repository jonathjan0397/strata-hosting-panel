<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function startFromAdmin(Request $request, Account $account): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $this->start($request, $account, 'admin.accounts.index');
    }

    public function startFromReseller(Request $request, Account $account): RedirectResponse
    {
        $reseller = $request->user();
        abort_unless($reseller?->isReseller(), 403);
        abort_unless((int) $account->reseller_id === (int) $reseller->id, 403);

        return $this->start($request, $account, 'reseller.accounts.index');
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        abort_unless($impersonatorId, 403);

        $client = $request->user();
        $impersonator = User::findOrFail($impersonatorId);
        $returnRoute = $request->session()->get('impersonator_return_route', 'dashboard');

        AuditLog::record('impersonation.stopped', $client?->account, [
            'impersonator_id' => $impersonator->id,
            'client_user_id' => $client?->id,
        ], $impersonator);

        Auth::login($impersonator);
        $request->session()->regenerate();
        $request->session()->forget([
            'impersonator_id',
            'impersonator_name',
            'impersonator_email',
            'impersonator_return_route',
        ]);

        return redirect()->route($returnRoute)->with('success', 'Returned to your panel.');
    }

    private function start(Request $request, Account $account, string $returnRoute): RedirectResponse
    {
        abort_if($request->session()->has('impersonator_id'), 409, 'Already viewing a client panel.');
        abort_if($account->status !== 'active', 403, 'Only active accounts can be accessed.');

        $impersonator = $request->user();
        $client = $account->user;

        abort_unless($client?->hasRole('user'), 403);

        AuditLog::record('impersonation.started', $account, [
            'impersonator_id' => $impersonator->id,
            'client_user_id' => $client->id,
        ], $impersonator);

        Auth::login($client);
        $request->session()->regenerate();
        $request->session()->put([
            'impersonator_id' => $impersonator->id,
            'impersonator_name' => $impersonator->name,
            'impersonator_email' => $impersonator->email,
            'impersonator_return_route' => $returnRoute,
        ]);

        return redirect()->route('my.dashboard')
            ->with('success', "Viewing {$account->username} as a client.");
    }
}
