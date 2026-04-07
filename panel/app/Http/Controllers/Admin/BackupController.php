<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBackupJobAction;
use App\Models\Account;
use App\Models\BackupJob;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'restore_status' => $j->restore_status,
            'size_human' => $j->size_human,
            'trigger'    => $j->trigger,
            'error'      => $j->error,
            'restore_error' => $j->restore_error,
            'last_restored_at' => $j->last_restored_at?->toDateTimeString(),
            'created_at' => $j->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Admin/Backups/Index', [
            'jobs'    => $jobs,
            'filters' => $request->only('search', 'status'),
            'accounts' => Account::with('node:id,name')
                ->orderBy('username')
                ->get(['id', 'username', 'node_id'])
                ->map(fn ($account) => [
                    'id' => $account->id,
                    'username' => $account->username,
                    'node' => $account->node?->name,
                ]),
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

        ProcessBackupJobAction::dispatch($job->id, 'create');

        return back()->with('success', "Backup for {$account->username} was queued.");
    }

    public function importExisting(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
        ]);

        $account = Account::with('node')->findOrFail($data['account_id']);

        if (! $account->node) {
            return back()->with('error', 'Account has no assigned node.');
        }

        try {
            $response = AgentClient::for($account->node)->backupList($account->username);
        } catch (\Throwable $e) {
            return back()->with('error', 'Backup import failed: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            return back()->with('error', 'Backup import failed: ' . $response->body());
        }

        $imported = 0;
        $skipped = 0;

        foreach ($response->json() ?? [] as $entry) {
            $filename = $entry['filename'] ?? null;
            if (! is_string($filename) || $filename === '') {
                $skipped++;
                continue;
            }

            $exists = BackupJob::where('account_id', $account->id)
                ->where('filename', $filename)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $type = $entry['type'] ?? $this->backupTypeFromFilename($filename);
            if (! in_array($type, ['files', 'databases', 'full'], true)) {
                $type = $this->backupTypeFromFilename($filename);
            }

            $job = new BackupJob([
                'account_id' => $account->id,
                'node_id' => $account->node_id,
                'filename' => $filename,
                'type' => $type,
                'status' => 'complete',
                'size_bytes' => $entry['size_bytes'] ?? null,
                'trigger' => 'imported',
            ]);
            $job->created_at = $this->backupCreatedAt($entry['created_at'] ?? null);
            $job->updated_at = now();
            $job->save();

            $imported++;
        }

        if ($imported === 0) {
            return back()->with('error', "No new backups imported. {$skipped} existing or invalid archive(s) skipped.");
        }

        return back()->with('success', "Imported {$imported} backup archive(s). {$skipped} skipped.");
    }

    public function restore(BackupJob $backup): RedirectResponse
    {
        abort_if(! $backup->filename, 422, 'Backup has no file to restore.');
        abort_if(! $backup->node, 503, 'Backup has no assigned node.');

        $account = $backup->account()->first();
        abort_if(! $account, 404, 'Backup account no longer exists.');

        $backup->update(['restore_status' => 'running', 'restore_error' => null]);
        ProcessBackupJobAction::dispatch($backup->id, 'restore');

        return back()->with('success', "Restore for {$backup->filename} was queued.");
    }

    public function destroy(BackupJob $backup): RedirectResponse
    {
        $error = $this->deleteBackupJob($backup);

        return $error
            ? back()->with('error', $error)
            : back()->with('success', 'Backup deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'backup_ids' => ['required', 'array', 'min:1', 'max:100'],
            'backup_ids.*' => ['integer', 'exists:backup_jobs,id'],
        ]);

        $deleted = 0;
        $errors = [];

        BackupJob::with(['account:id,username', 'node:id,name'])
            ->whereIn('id', $data['backup_ids'])
            ->get()
            ->each(function (BackupJob $backup) use (&$deleted, &$errors) {
                $error = $this->deleteBackupJob($backup);

                if ($error) {
                    $errors[] = "Backup {$backup->id}: {$error}";
                    return;
                }

                $deleted++;
            });

        if ($errors !== []) {
            return back()->with(
                $deleted > 0 ? 'success' : 'error',
                $deleted > 0
                    ? "Deleted {$deleted} backup(s). " . count($errors) . ' failed: ' . implode(' ', array_slice($errors, 0, 3))
                    : 'No backups deleted. ' . implode(' ', array_slice($errors, 0, 3))
            );
        }

        return back()->with('success', "Deleted {$deleted} backup(s).");
    }

    private function deleteBackupJob(BackupJob $backup): ?string
    {
        $backup->loadMissing(['account:id,username', 'node:id,name']);

        if ($backup->filename && $backup->node) {
            if (! $backup->account) {
                return 'Failed to delete backup from node: backup account is missing.';
            }

            try {
                $response = AgentClient::for($backup->node)->backupDelete($backup->account->username, $backup->filename);
                if (! $response->successful()) {
                    return 'Failed to delete backup from node: ' . $response->body();
                }
            } catch (\Throwable $e) {
                return 'Failed to delete backup from node: ' . $e->getMessage();
            }
        }

        $backup->delete();

        return null;
    }

    private function backupTypeFromFilename(string $filename): string
    {
        return match (true) {
            str_contains($filename, '_files_') => 'files',
            str_contains($filename, '_databases_') => 'databases',
            default => 'full',
        };
    }

    private function backupCreatedAt(mixed $createdAt): Carbon
    {
        if (! is_string($createdAt) || $createdAt === '') {
            return now();
        }

        try {
            return Carbon::parse($createdAt);
        } catch (\Throwable) {
            return now();
        }
    }
}
