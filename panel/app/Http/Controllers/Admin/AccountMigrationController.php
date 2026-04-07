<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMigration;
use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountMigrationController extends Controller
{
    public function index(Request $request): Response
    {
        $migrations = AccountMigration::with([
            'account:id,username,node_id',
            'sourceNode:id,name,hostname',
            'targetNode:id,name,hostname',
            'backupJob:id,filename,size_bytes,status',
            'targetBackupJob:id,filename,size_bytes,status',
            'startedBy:id,name',
        ])
            ->latest()
            ->paginate(25)
            ->through(fn (AccountMigration $migration) => [
                'id' => $migration->id,
                'account' => $migration->account?->username,
                'source_node' => $migration->sourceNode?->name,
                'target_node' => $migration->targetNode?->name,
                'status' => $migration->status,
                'error' => $migration->error,
                'backup' => $migration->backupJob ? [
                    'filename' => $migration->backupJob->filename,
                    'status' => $migration->backupJob->status,
                    'size_human' => $migration->backupJob->size_human,
                ] : null,
                'target_backup' => $migration->targetBackupJob ? [
                    'filename' => $migration->targetBackupJob->filename,
                    'status' => $migration->targetBackupJob->status,
                    'size_human' => $migration->targetBackupJob->size_human,
                ] : null,
                'started_by' => $migration->startedBy?->name,
                'created_at' => $migration->created_at?->toDateTimeString(),
                'completed_at' => $migration->completed_at?->toDateTimeString(),
            ]);

        return Inertia::render('Admin/Migrations/Index', [
            'migrations' => $migrations,
            'accounts' => Account::with('node:id,name')
                ->orderBy('username')
                ->get(['id', 'username', 'node_id'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'username' => $account->username,
                    'node_id' => $account->node_id,
                    'node' => $account->node?->name,
                ]),
            'nodes' => Node::where('status', 'online')
                ->orderBy('name')
                ->get(['id', 'name', 'hostname']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'target_node_id' => ['required', 'exists:nodes,id'],
        ]);

        $account = Account::with('node')->findOrFail($data['account_id']);
        $targetNode = Node::findOrFail($data['target_node_id']);

        if ((int) $account->node_id === (int) $targetNode->id) {
            return back()->with('error', 'Target node must be different from the account source node.');
        }

        if (! $account->node) {
            return back()->with('error', 'Account has no source node assigned.');
        }

        if (! $account->node->isOnline() || ! $targetNode->isOnline()) {
            return back()->with('error', 'Both source and target nodes must be online before preparing a migration.');
        }

        $activeMigration = AccountMigration::where('account_id', $account->id)
            ->whereIn('status', ['pending', 'backup_running', 'backup_ready', 'transfer_running', 'transfer_ready'])
            ->exists();

        if ($activeMigration) {
            return back()->with('error', 'This account already has an active migration workflow.');
        }

        $migration = AccountMigration::create([
            'account_id' => $account->id,
            'source_node_id' => $account->node_id,
            'target_node_id' => $targetNode->id,
            'started_by' => $request->user()?->id,
            'status' => 'backup_running',
        ]);

        $backupJob = BackupJob::create([
            'account_id' => $account->id,
            'node_id' => $account->node_id,
            'type' => 'full',
            'status' => 'running',
            'trigger' => 'manual',
        ]);

        $migration->update(['backup_job_id' => $backupJob->id]);

        try {
            $response = AgentClient::for($account->node)->backupCreate($account->username, 'full');
        } catch (\Throwable $e) {
            $backupJob->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $migration->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);

            return back()->with('error', 'Migration backup failed: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            $error = $response->body();
            $backupJob->update(['status' => 'failed', 'error' => $error]);
            $migration->update(['status' => 'failed', 'error' => $error, 'completed_at' => now()]);

            return back()->with('error', 'Migration backup failed: ' . $error);
        }

        $result = $response->json();
        $backupJob->update([
            'status' => 'complete',
            'filename' => $result['filename'] ?? null,
            'size_bytes' => $result['size_bytes'] ?? null,
        ]);
        $migration->update(['status' => 'backup_ready']);

        AuditLog::record('account.migration_backup_ready', $account, [
            'migration_id' => $migration->id,
            'source_node_id' => $account->node_id,
            'target_node_id' => $targetNode->id,
            'backup_job_id' => $backupJob->id,
            'filename' => $backupJob->filename,
        ]);

        return back()->with('success', "Migration backup is ready for {$account->username}. Transfer/restore can proceed from the migration queue.");
    }

    public function transfer(AccountMigration $migration): RedirectResponse
    {
        $migration->load(['account', 'sourceNode', 'targetNode', 'backupJob']);

        if ($migration->status !== 'backup_ready') {
            return back()->with('error', 'Only backup-ready migrations can be transferred.');
        }

        if (! $migration->account || ! $migration->sourceNode || ! $migration->targetNode || ! $migration->backupJob?->filename) {
            return back()->with('error', 'Migration is missing account, node, or backup metadata.');
        }

        if (! $migration->sourceNode->isOnline() || ! $migration->targetNode->isOnline()) {
            return back()->with('error', 'Both source and target nodes must be online before transfer.');
        }

        $migration->update(['status' => 'transfer_running', 'error' => null]);

        $targetBackup = BackupJob::create([
            'account_id' => $migration->account_id,
            'node_id' => $migration->target_node_id,
            'filename' => $migration->backupJob->filename,
            'type' => $migration->backupJob->type,
            'status' => 'running',
            'trigger' => 'manual',
        ]);

        try {
            $download = AgentClient::for($migration->sourceNode)->backupDownload(
                $migration->account->username,
                $migration->backupJob->filename
            );

            if (! $download->successful()) {
                throw new \RuntimeException($download->body());
            }

            $upload = AgentClient::for($migration->targetNode)->backupUpload(
                $migration->account->username,
                $migration->backupJob->filename,
                $download->body()
            );

            if (! $upload->successful()) {
                throw new \RuntimeException($upload->body());
            }
        } catch (\Throwable $e) {
            $targetBackup->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $migration->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);

            return back()->with('error', 'Migration transfer failed: ' . $e->getMessage());
        }

        $result = $upload->json();
        $targetBackup->update([
            'status' => 'complete',
            'filename' => $result['filename'] ?? $migration->backupJob->filename,
            'size_bytes' => $result['size_bytes'] ?? $migration->backupJob->size_bytes,
        ]);

        $migration->update([
            'status' => 'transfer_ready',
            'target_backup_job_id' => $targetBackup->id,
        ]);

        AuditLog::record('account.migration_transfer_ready', $migration->account, [
            'migration_id' => $migration->id,
            'source_node_id' => $migration->source_node_id,
            'target_node_id' => $migration->target_node_id,
            'source_backup_job_id' => $migration->backup_job_id,
            'target_backup_job_id' => $targetBackup->id,
            'filename' => $targetBackup->filename,
        ]);

        return back()->with('success', "Migration backup transferred to {$migration->targetNode->name}.");
    }
}
