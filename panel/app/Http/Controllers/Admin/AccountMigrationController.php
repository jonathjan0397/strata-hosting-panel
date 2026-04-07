<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMigration;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\DatabaseGrant;
use App\Models\DnsZone;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use App\Models\FtpAccount;
use App\Models\HostingDatabase;
use App\Models\Node;
use App\Services\AgentClient;
use App\Services\DomainProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        try {
            $provision = AgentClient::for($migration->targetNode)->provisionAccount([
                'username' => $migration->account->username,
                'php_version' => $migration->account->php_version,
            ]);

            if (! $provision->successful()) {
                throw new \RuntimeException($provision->json('message') ?? $provision->body());
            }

            $restore = AgentClient::for($migration->targetNode)->backupRestore(
                $migration->account->username,
                $migration->targetBackupJob->filename
            );

            if (! $restore->successful()) {
                throw new \RuntimeException($restore->body());
            }
        } catch (\Throwable $e) {
            $migration->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);

            return back()->with('error', 'Migration restore failed: ' . $e->getMessage());
        }

        $migration->update(['status' => 'restored', 'completed_at' => now()]);

        AuditLog::record('account.migration_restored', $migration->account, [
            'migration_id' => $migration->id,
            'source_node_id' => $migration->source_node_id,
            'target_node_id' => $migration->target_node_id,
            'target_backup_job_id' => $migration->target_backup_job_id,
            'filename' => $migration->targetBackupJob->filename,
            'source_retained' => true,
        ]);

        return back()->with('success', "Migration archive restored on {$migration->targetNode->name}. Source account is still retained for cutover validation.");
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

        $blockers = $this->cutoverBlockers($migration->account);
        if ($blockers !== []) {
            return back()->with('error', 'Automatic cutover is blocked until service re-provisioning is added for: ' . implode(', ', $blockers) . '.');
        }

        if (! $migration->targetNode->isOnline()) {
            return back()->with('error', 'Target node must be online before cutover.');
        }

        $account = $migration->account;
        $sourceNodeId = $migration->source_node_id;
        $targetNodeId = $migration->target_node_id;
        $domainIds = $account->domains->pluck('id')->all();

        $migration->update(['status' => 'cutover_running', 'error' => null]);

        try {
            DB::transaction(function () use ($account, $domainIds, $targetNodeId) {
                $account->update(['node_id' => $targetNodeId]);

                if ($domainIds !== []) {
                    $account->domains()->whereIn('id', $domainIds)->update(['node_id' => $targetNodeId]);
                    DnsZone::whereIn('domain_id', $domainIds)->update(['node_id' => $targetNodeId]);
                }
            });

            foreach ($account->domains()->with('node', 'account')->get() as $domain) {
                [$synced, $error] = app(DomainProvisioner::class)->reprovision($domain);
                if (! $synced) {
                    throw new \RuntimeException("{$domain->domain}: {$error}");
                }
            }
        } catch (\Throwable $e) {
            DB::transaction(function () use ($account, $domainIds, $sourceNodeId) {
                $account->update(['node_id' => $sourceNodeId]);

                if ($domainIds !== []) {
                    $account->domains()->whereIn('id', $domainIds)->update(['node_id' => $sourceNodeId]);
                    DnsZone::whereIn('domain_id', $domainIds)->update(['node_id' => $sourceNodeId]);
                }
            });

            $migration->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);

            return back()->with('error', 'Migration cutover failed and panel ownership was rolled back: ' . $e->getMessage());
        }

        $migration->update(['status' => 'complete', 'completed_at' => now()]);

        AuditLog::record('account.migration_cutover_complete', $account->refresh(), [
            'migration_id' => $migration->id,
            'source_node_id' => $sourceNodeId,
            'target_node_id' => $targetNodeId,
            'domains_reprovisioned' => count($domainIds),
            'source_retained' => true,
        ]);

        return back()->with('success', "Account {$account->username} cut over to {$migration->targetNode->name}. Source node data is retained for manual cleanup.");
    }

    private function cutoverBlockers(Account $account): array
    {
        $checks = [
            'mailboxes' => EmailAccount::where('account_id', $account->id)->count(),
            'forwarders' => EmailForwarder::where('account_id', $account->id)->count(),
            'FTP accounts' => FtpAccount::where('account_id', $account->id)->count(),
            'databases' => HostingDatabase::where('account_id', $account->id)->count(),
            'database grants' => DatabaseGrant::where('account_id', $account->id)->count(),
            'app installs' => AppInstallation::where('account_id', $account->id)->count(),
        ];

        return array_keys(array_filter($checks, fn (int $count) => $count > 0));
    }
}
