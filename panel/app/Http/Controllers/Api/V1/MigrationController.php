<?php

namespace App\Http\Controllers\Api\V1;

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MigrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $migrations = AccountMigration::with($this->relationships())
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['account_id'] ?? null, fn ($query, int $accountId) => $query->where('account_id', $accountId))
            ->latest()
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => $migrations->getCollection()->map(fn (AccountMigration $migration) => $this->payload($migration))->values(),
            'meta' => [
                'current_page' => $migrations->currentPage(),
                'last_page' => $migrations->lastPage(),
                'per_page' => $migrations->perPage(),
                'total' => $migrations->total(),
            ],
        ]);
    }

    public function show(AccountMigration $migration): JsonResponse
    {
        return response()->json($this->payload($migration->load($this->relationships())));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'target_node_id' => ['required', 'exists:nodes,id'],
        ]);

        $account = Account::with('node')->findOrFail($data['account_id']);
        $targetNode = Node::findOrFail($data['target_node_id']);

        if ((int) $account->node_id === (int) $targetNode->id) {
            return response()->json(['error' => 'Target node must be different from the account source node.'], 422);
        }

        if (! $account->node || ! $account->node->isOnline() || ! $targetNode->isOnline()) {
            return response()->json(['error' => 'Both source and target nodes must be online before preparing a migration.'], 422);
        }

        $activeMigration = AccountMigration::where('account_id', $account->id)
            ->whereIn('status', ['pending', 'backup_running', 'backup_ready', 'transfer_running', 'transfer_ready', 'restore_running', 'restored', 'cutover_running'])
            ->exists();

        if ($activeMigration) {
            return response()->json(['error' => 'This account already has an active migration workflow.'], 409);
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
            if (! $response->successful()) {
                throw new \RuntimeException($response->body());
            }
        } catch (\Throwable $e) {
            $backupJob->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $migration->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);

            return response()->json(['error' => 'Migration backup failed: ' . $e->getMessage()], 502);
        }

        $result = $response->json();
        $backupJob->update([
            'status' => 'complete',
            'filename' => $result['filename'] ?? null,
            'size_bytes' => $result['size_bytes'] ?? null,
        ]);
        $migration->update(['status' => 'backup_ready']);

        AuditLog::record('api.account_migration_backup_ready', $account, [
            'migration_id' => $migration->id,
            'source_node_id' => $account->node_id,
            'target_node_id' => $targetNode->id,
            'backup_job_id' => $backupJob->id,
            'filename' => $backupJob->filename,
        ]);

        return response()->json($this->payload($migration->load($this->relationships())), 201);
    }

    public function transfer(AccountMigration $migration): JsonResponse
    {
        $migration->load(['account', 'sourceNode', 'targetNode', 'backupJob']);

        if ($migration->status !== 'backup_ready') {
            return response()->json(['error' => 'Only backup-ready migrations can be transferred.'], 409);
        }

        if (! $migration->account || ! $migration->sourceNode || ! $migration->targetNode || ! $migration->backupJob?->filename) {
            return response()->json(['error' => 'Migration is missing account, node, or backup metadata.'], 422);
        }

        if (! $migration->sourceNode->isOnline() || ! $migration->targetNode->isOnline()) {
            return response()->json(['error' => 'Both source and target nodes must be online before transfer.'], 422);
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
            $download = AgentClient::for($migration->sourceNode)->backupDownload($migration->account->username, $migration->backupJob->filename);
            if (! $download->successful()) {
                throw new \RuntimeException($download->body());
            }

            $upload = AgentClient::for($migration->targetNode)->backupUpload($migration->account->username, $migration->backupJob->filename, $download->body());
            if (! $upload->successful()) {
                throw new \RuntimeException($upload->body());
            }
        } catch (\Throwable $e) {
            $targetBackup->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $migration->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);

            return response()->json(['error' => 'Migration transfer failed: ' . $e->getMessage()], 502);
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

        AuditLog::record('api.account_migration_transfer_ready', $migration->account, [
            'migration_id' => $migration->id,
            'target_backup_job_id' => $targetBackup->id,
            'filename' => $targetBackup->filename,
        ]);

        return response()->json($this->payload($migration->load($this->relationships())));
    }

    public function restore(AccountMigration $migration): JsonResponse
    {
        $migration->load(['account', 'targetNode', 'targetBackupJob']);

        if ($migration->status !== 'transfer_ready') {
            return response()->json(['error' => 'Only transfer-ready migrations can be restored.'], 409);
        }

        if (! $migration->account || ! $migration->targetNode || ! $migration->targetBackupJob?->filename) {
            return response()->json(['error' => 'Migration is missing account, target node, or target backup metadata.'], 422);
        }

        if (! $migration->targetNode->isOnline()) {
            return response()->json(['error' => 'Target node must be online before restore.'], 422);
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

            $restore = AgentClient::for($migration->targetNode)->backupRestore($migration->account->username, $migration->targetBackupJob->filename);
            if (! $restore->successful()) {
                throw new \RuntimeException($restore->body());
            }
        } catch (\Throwable $e) {
            $migration->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);

            return response()->json(['error' => 'Migration restore failed: ' . $e->getMessage()], 502);
        }

        $migration->update(['status' => 'restored', 'completed_at' => now()]);

        AuditLog::record('api.account_migration_restored', $migration->account, [
            'migration_id' => $migration->id,
            'target_backup_job_id' => $migration->target_backup_job_id,
            'source_retained' => true,
        ]);

        return response()->json($this->payload($migration->load($this->relationships())));
    }

    public function cutover(AccountMigration $migration): JsonResponse
    {
        $migration->load(['account.domains', 'sourceNode', 'targetNode']);

        if ($migration->status !== 'restored') {
            return response()->json(['error' => 'Only restored migrations can be cut over.'], 409);
        }

        if (! $migration->account || ! $migration->sourceNode || ! $migration->targetNode) {
            return response()->json(['error' => 'Migration is missing account or node metadata.'], 422);
        }

        $blockers = $this->cutoverBlockers($migration->account);
        if ($blockers !== []) {
            return response()->json(['error' => 'Automatic cutover is blocked.', 'blockers' => $blockers], 409);
        }

        if (! $migration->targetNode->isOnline()) {
            return response()->json(['error' => 'Target node must be online before cutover.'], 422);
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

            return response()->json(['error' => 'Migration cutover failed and panel ownership was rolled back: ' . $e->getMessage()], 502);
        }

        $migration->update(['status' => 'complete', 'completed_at' => now()]);

        AuditLog::record('api.account_migration_cutover_complete', $account->refresh(), [
            'migration_id' => $migration->id,
            'source_node_id' => $sourceNodeId,
            'target_node_id' => $targetNodeId,
            'domains_reprovisioned' => count($domainIds),
            'source_retained' => true,
        ]);

        return response()->json($this->payload($migration->load($this->relationships())));
    }

    public function cleanupSource(AccountMigration $migration): JsonResponse
    {
        $migration->load(['account', 'sourceNode']);

        if ($migration->status !== 'complete') {
            return response()->json(['error' => 'Only completed migrations can clean up source node data.'], 409);
        }

        if (! $migration->account || ! $migration->sourceNode) {
            return response()->json(['error' => 'Migration is missing account or source node metadata.'], 422);
        }

        if (! $migration->sourceNode->isOnline()) {
            return response()->json(['error' => 'Source node must be online before cleanup.'], 422);
        }

        $migration->update(['status' => 'source_cleanup_running', 'error' => null]);

        try {
            $response = AgentClient::for($migration->sourceNode)->deprovisionAccount($migration->account->username);
            if (! $response->successful()) {
                throw new \RuntimeException($response->json('message') ?? $response->body());
            }
        } catch (\Throwable $e) {
            $migration->update(['status' => 'complete', 'error' => 'Source cleanup failed: ' . $e->getMessage()]);

            return response()->json(['error' => 'Source cleanup failed; cutover remains complete: ' . $e->getMessage()], 502);
        }

        $migration->update(['status' => 'source_cleaned', 'error' => null]);

        AuditLog::record('api.account_migration_source_cleaned', $migration->account, [
            'migration_id' => $migration->id,
            'source_node_id' => $migration->source_node_id,
            'target_node_id' => $migration->target_node_id,
        ]);

        return response()->json($this->payload($migration->load($this->relationships())));
    }

    private function relationships(): array
    {
        return [
            'account:id,username,node_id',
            'sourceNode:id,name,hostname,status',
            'targetNode:id,name,hostname,status',
            'backupJob:id,filename,size_bytes,status,error',
            'targetBackupJob:id,filename,size_bytes,status,error',
            'startedBy:id,name',
        ];
    }

    private function payload(AccountMigration $migration): array
    {
        $account = $migration->account;
        $blockers = $account ? $this->cutoverBlockers($account) : [];

        return [
            'id' => $migration->id,
            'status' => $migration->status,
            'error' => $migration->error,
            'account' => $account ? [
                'id' => $account->id,
                'username' => $account->username,
            ] : null,
            'source_node' => $this->nodePayload($migration->sourceNode),
            'target_node' => $this->nodePayload($migration->targetNode),
            'backup' => $this->backupPayload($migration->backupJob),
            'target_backup' => $this->backupPayload($migration->targetBackupJob),
            'cutover_blockers' => $blockers,
            'can_cutover' => $blockers === [],
            'started_by' => $migration->startedBy ? [
                'id' => $migration->startedBy->id,
                'name' => $migration->startedBy->name,
            ] : null,
            'created_at' => $migration->created_at?->toISOString(),
            'updated_at' => $migration->updated_at?->toISOString(),
            'completed_at' => $migration->completed_at?->toISOString(),
        ];
    }

    private function nodePayload(?Node $node): ?array
    {
        return $node ? [
            'id' => $node->id,
            'name' => $node->name,
            'hostname' => $node->hostname,
            'status' => $node->status,
        ] : null;
    }

    private function backupPayload(?BackupJob $backup): ?array
    {
        return $backup ? [
            'id' => $backup->id,
            'filename' => $backup->filename,
            'status' => $backup->status,
            'size_bytes' => $backup->size_bytes,
            'size_human' => $backup->size_human,
            'error' => $backup->error,
        ] : null;
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
