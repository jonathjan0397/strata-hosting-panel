<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BackupScheduleController extends Controller
{
    public function index(): Response
    {
        $accounts = Account::with('node:id,name')
            ->select('id', 'username', 'node_id', 'backup_schedule', 'backup_time', 'backup_day')
            ->orderBy('username')
            ->get()
            ->map(fn ($a) => [
                'id'              => $a->id,
                'username'        => $a->username,
                'node'            => $a->node?->name,
                'backup_schedule' => $a->backup_schedule,
                'backup_time'     => $a->backup_time,
                'backup_day'      => $a->backup_day,
            ]);

        return Inertia::render('Admin/Backups/Schedules', [
            'accounts' => $accounts,
        ]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'backup_schedule' => ['required', 'in:disabled,daily,weekly'],
            'backup_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'backup_day'      => ['required_if:backup_schedule,weekly', 'integer', 'min:0', 'max:6'],
        ]);

        $account->update($data);

        return back()->with('success', "Backup schedule updated for {$account->username}.");
    }
}
