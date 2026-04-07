<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAccountMigrationStep;
use App\Models\Account;
use App\Models\AccountMigration;
use App\Models\Node;
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
            ->through(function (AccountMigration $migration) {
                $cutoverBlockers = $migration->account
                    ? ProcessAccountMigrationStep::cutoverBlockers($migration->account)
                    : [];

                return [
                    'id' => $migration->id,
                    'account' => $migration->account?->username,
                    'source_node' => $migration->sourceNode?->name,
                    'target_node' => $migration->targetNode?->name,
                    'status' => $migration->status,
                    'error' => $migration->error,
                    'cutover_blockers' => $cutoverBlockers,
                    'can_cutover' => $cutoverBlockers === [],
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
                ];
            });

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
            ->whereIn('status', ['pending', 'backup_running', 'backup_ready', 'transfer_running', 'transfer_ready', 'restore_running', 'restored', 'cutover_running'])
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

        ProcessAccountMigrationStep::dispatch($migration->id, 'prepare');

        return back()->with('success', "Migration backup for {$account->username} was queued. Refresh the migration queue for progress.");
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
        ProcessAccountMigrationStep::dispatch($migration->id, 'transfer');

        return back()->with('success', "Migration backup transfer to {$migration->targetNode->name} was queued.");
    }

    public function restore(AccountMigration $migration): RedirectResponse
    {
        $migration->load(['account', 'targetNode', 'targetBackupJob']);

        if ($migration->status !== 'transfer_ready') {
            return back()->with('error', 'Only transfer-ready migrations can be restored.');
        }

        if (! $migration->account || ! $migration->targetNode || ! $migration->targetBackupJob?->filename) {
            return back()->with('error', 'Migration is missing account, target node, or target backup metadata.');
        }

        if (! $migration->targetNode->isOnline()) {
            return back()->with('error', 'Target node must be online before restore.');
        }

        $migration->update(['status' => 'restore_running', 'error' => null]);
        ProcessAccountMigrationStep::dispatch($migration->id, 'restore');

        return back()->with('success', "Migration archive restore on {$migration->targetNode->name} was queued. Source account is still retained for cutover validation.");
    }

    public function cutover(AccountMigration $migration): RedirectResponse
    {
        $migration->load(['account.domains', 'sourceNode', 'targetNode']);

        if ($migration->status !== 'restored') {
            return back()->with('error', 'Only restored migrations can be cut over.');
        }

        if (! $migration->account || ! $migration->sourceNode || ! $migration->targetNode) {
            return back()->with('error', 'Migration is missing account or node metadata.');
        }

        $blockers = ProcessAccountMigrationStep::cutoverBlockers($migration->account);
        if ($blockers !== []) {
            return back()->with('error', 'Automatic cutover is blocked until service re-provisioning is added for: ' . implode(', ', $blockers) . '.');
        }

        if (! $migration->targetNode->isOnline()) {
            return back()->with('error', 'Target node must be online before cutover.');
        }

        $migration->update(['status' => 'cutover_running', 'error' => null]);
        ProcessAccountMigrationStep::dispatch($migration->id, 'cutover');

        return back()->with('success', "Cutover for {$migration->account->username} was queued. Source node data will be retained for manual cleanup.");
    }

    public function cleanupSource(AccountMigration $migration): RedirectResponse
    {
        $migration->load(['account', 'sourceNode']);

        if ($migration->status !== 'complete') {
            return back()->with('error', 'Only completed migrations can clean up source node data.');
        }

        if (! $migration->account || ! $migration->sourceNode) {
            return back()->with('error', 'Migration is missing account or source node metadata.');
        }

        if (! $migration->sourceNode->isOnline()) {
            return back()->with('error', 'Source node must be online before cleanup.');
        }

        $migration->update(['status' => 'source_cleanup_running', 'error' => null]);
        ProcessAccountMigrationStep::dispatch($migration->id, 'cleanup_source');

        return back()->with('success', "Source node cleanup for {$migration->account->username} was queued.");
    }
}
