<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBackupJobAction;
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
                'restore_status' => $j->restore_status,
                'size_human' => $j->size_human,
                'trigger'    => $j->trigger,
                'error'      => $j->error,
                'restore_error' => $j->restore_error,
                'last_restored_at' => $j->last_restored_at?->toDateTimeString(),
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

        ProcessBackupJobAction::dispatch($job->id, 'create', ['push_remote' => true]);

        return back()->with('success', 'Backup was queued. Refresh the backups page for progress.');
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
                $response = $client->backupDelete($account->username, $backup->filename);
                if (! $response->successful()) {
                    return back()->with('error', 'Failed to delete backup: ' . $response->body());
                }
            } catch (\Throwable $e) {
                return back()->with('error', 'Failed to delete backup: ' . $e->getMessage());
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

        $backup->update(['restore_status' => 'running', 'restore_error' => null]);
        ProcessBackupJobAction::dispatch($backup->id, 'restore');

        return back()->with('success', "Restore for {$backup->filename} was queued.");
    }

    public function restorePath(Request $request, BackupJob $backup): RedirectResponse
    {
        $account = Auth::user()->account;

        if (! $account || $backup->account_id !== $account->id) {
            abort(403);
        }

        if (! $backup->filename || $backup->status !== 'complete') {
            return back()->with('error', 'Backup file not available for path restore.');
        }

        if ($backup->type === 'databases') {
            return back()->with('error', 'Path restore is only available for file and full backups.');
        }

        $data = $request->validate([
            'source_path' => ['required', 'string', 'max:500', 'not_regex:/^\//', 'not_regex:/(^|[\/\\\\])\.\.([\/\\\\]|$)/'],
            'target_path' => ['nullable', 'string', 'max:500', 'not_regex:/^\//', 'not_regex:/(^|[\/\\\\])\.\.([\/\\\\]|$)/'],
        ]);

        $backup->update(['restore_status' => 'running', 'restore_error' => null]);
        ProcessBackupJobAction::dispatch($backup->id, 'restore_path', [
            'source_path' => $data['source_path'],
            'target_path' => $data['target_path'] ?? null,
        ]);

        return back()->with('success', "Path restore for {$data['source_path']} was queued.");
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
        $response = $client->backupDownload($account->username, $backup->filename);

        if (! $response->successful()) {
            return back()->withErrors(['error' => 'Backup download failed: ' . $response->body()]);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type', 'application/gzip'),
            'Content-Disposition' => $response->header('Content-Disposition', 'attachment; filename="' . $backup->filename . '"'),
        ]);
    }
}
