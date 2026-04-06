<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BackupJob;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BackupController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        $query = BackupJob::with(['account:id,username', 'node:id,name'])->latest();

        if ($search = $request->get('search')) {
            $query->whereHas('account', fn($q) => $q->where('username', 'like', "%{$search}%"));
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $jobs = $query->paginate(50)->through(fn($j) => [
            'id'         => $j->id,
            'account'    => $j->account?->username,
            'node'       => $j->node?->name,
            'filename'   => $j->filename,
            'type'       => $j->type,
            'status'     => $j->status,
            'size_human' => $j->size_human,
            'trigger'    => $j->trigger,
            'error'      => $j->error,
            'created_at' => $j->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Admin/Backups/Index', [
            'jobs'    => $jobs,
            'filters' => $request->only('search', 'status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data    = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'type'       => 'required|in:files,databases,full',
        ]);

        $account = Account::with('node')->findOrFail($data['account_id']);

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

    public function restore(BackupJob $backup): RedirectResponse
    {
        abort_if(! $backup->filename, 422, 'Backup has no file to restore.');

        $account = $backup->account()->with('node')->first();
        abort_if(! $account?->node, 503, 'Account has no assigned node.');

        $response = AgentClient::for($account->node)->backupRestore($account->username, $backup->filename);

        if (! $response->successful()) {
            return back()->with('error', 'Restore failed: ' . $response->body());
        }

        return back()->with('success', "Backup {$backup->filename} restored successfully.");
    }

    public function destroy(BackupJob $backup): RedirectResponse
    {
        if ($backup->filename && $backup->node) {
            try {
                $client = new AgentClient($backup->node);
                $response = $client->backupDelete($backup->account->username, $backup->filename);
                if (! $response->successful()) {
                    return back()->with('error', 'Failed to delete backup from node: ' . $response->body());
                }
            } catch (\Throwable $e) {
                return back()->with('error', 'Failed to delete backup from node: ' . $e->getMessage());
            }
        }

        $backup->delete();
        return back();
    }
}
