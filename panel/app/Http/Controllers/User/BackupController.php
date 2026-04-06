<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BackupJob;
use App\Models\RemoteBackupDestination;
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
                    'error'      => null,
                ]);

                $pushErrors = [];
                if ($job->status === 'complete' && $job->filename) {
                    $destinations = RemoteBackupDestination::where('active', true)->get();
                    foreach ($destinations as $dest) {
                        try {
                            $config = array_merge(['destination_type' => $dest->type], $dest->config);
                            $pushResponse = $client->backupPush($account->username, $job->filename, $config);
                            if (! $pushResponse->successful()) {
                                $pushErrors[] = "{$dest->name}: {$pushResponse->body()}";
                            }
                        } catch (\Throwable $e) {
                            $pushErrors[] = "{$dest->name}: {$e->getMessage()}";
                        }
                    }
                }

                if ($pushErrors !== []) {
                    $job->update(['error' => implode(' | ', $pushErrors)]);

                    return back()->with('error', 'Backup completed, but remote copy failed for one or more destinations.');
                }
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
