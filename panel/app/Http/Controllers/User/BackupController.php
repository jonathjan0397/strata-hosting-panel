<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BackupJob;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BackupController extends Controller
{
    public function index(): \Inertia\Response
    {
        $user    = Auth::user();
        $account = $user->account;

        if (! $account) {
            return Inertia::render('User/NoAccount');
        }

        $jobs = BackupJob::where('account_id', $account->id)
            ->with('node:id,name')
            ->latest()
            ->get()
            ->map(fn($j) => [
                'id'         => $j->id,
                'filename'   => $j->filename,
                'type'       => $j->type,
                'status'     => $j->status,
                'size_human' => $j->size_human,
                'trigger'    => $j->trigger,
                'error'      => $j->error,
                'created_at' => $j->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('User/Backups/Index', [
            'jobs' => $jobs,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data    = $request->validate(['type' => 'required|in:files,databases,full']);
        $account = Auth::user()->account;

        if (! $account) {
            return back()->withErrors(['error' => 'No hosting account found.']);
        }

        $job = BackupJob::create([
            'account_id' => $account->id,
            'node_id'    => $account->node_id,
            'type'       => $data['type'],
            'status'     => 'running',
            'trigger'    => 'manual',
        ]);

        try {
            $client   = new AgentClient($account->node);
            $response = $client->backupCreate($account->username, $data['type']);

            if ($response->successful()) {
                $result = $response->json();
                $job->update([
                    'status'     => 'complete',
                    'filename'   => $result['filename'] ?? null,
                    'size_bytes' => $result['size_bytes'] ?? null,
                ]);
            } else {
                $job->update(['status' => 'failed', 'error' => $response->body()]);
            }
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(BackupJob $backup): RedirectResponse
    {
        $account = Auth::user()->account;

        if (! $account || $backup->account_id !== $account->id) {
            abort(403);
        }

        if ($backup->filename) {
            try {
                $client = new AgentClient($backup->node);
                $client->backupDelete($account->username, $backup->filename);
            } catch (\Throwable) {
                // Best-effort — remove DB record regardless
            }
        }

        $backup->delete();
        return back();
    }

    public function restore(BackupJob $backup): RedirectResponse
    {
        $account = Auth::user()->account;

        if (! $account || $backup->account_id !== $account->id) {
            abort(403);
        }

        if (! $backup->filename || $backup->status !== 'complete') {
            return back()->with('error', 'Backup file not available for restore.');
        }

        $client   = AgentClient::for($backup->node);
        $response = $client->backupRestore($account->username, $backup->filename);

        if (! $response->successful()) {
            return back()->with('error', 'Restore failed: ' . $response->body());
        }

        return back()->with('success', "Backup {$backup->filename} restored successfully.");
    }

    public function download(BackupJob $backup): Response|RedirectResponse
    {
        $account = Auth::user()->account;

        if (! $account || $backup->account_id !== $account->id) {
            abort(403);
        }

        if (! $backup->filename || $backup->status !== 'complete') {
            return back()->withErrors(['error' => 'Backup file not available.']);
        }

        $client = new AgentClient($backup->node);
        $url    = $client->backupDownloadUrl($account->username, $backup->filename);

        return redirect($url);
    }
}
