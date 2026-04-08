<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAccountMigrationStep;
use App\Models\Account;
use App\Models\AccountMigration;
use App\Models\BackupJob;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        ProcessAccountMigrationStep::dispatch($migration->id, 'prepare', 'api.account_migration');

        return response()->json($this->payload($migration->load($this->relationships())), 202);
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

        ProcessAccountMigrationStep::dispatch($migration->id, 'transfer', 'api.account_migration');

        return response()->json($this->payload($migration->load($this->relationships())), 202);
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

        ProcessAccountMigrationStep::dispatch($migration->id, 'restore', 'api.account_migration');

        return response()->json($this->payload($migration->load($this->relationships())), 202);
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

        $blockers = ProcessAccountMigrationStep::cutoverBlockers($migration->account);
        if ($blockers !== []) {
            return response()->json(['error' => 'Automatic cutover is blocked.', 'blockers' => $blockers], 409);
        }

        if (! $migration->targetNode->isOnline()) {
            return response()->json(['error' => 'Target node must be online before cutover.'], 422);
        }

        $migration->update(['status' => 'cutover_running', 'error' => null]);
        ProcessAccountMigrationStep::dispatch($migration->id, 'cutover', 'api.account_migration');

        return response()->json($this->payload($migration->load($this->relationships())), 202);
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

        $resetRequired = ProcessAccountMigrationStep::resetRequiredServices($migration->account);
        if ($resetRequired !== []) {
            return response()->json([
                'error' => 'Source cleanup is blocked until reset-required services are handled.',
                'reset_required' => $resetRequired,
            ], 409);
        }

        $verificationRequired = ProcessAccountMigrationStep::verificationRequiredServices($migration->account);
        if ($verificationRequired !== []) {
            return response()->json([
                'error' => 'Source cleanup is blocked until verification-required services are handled.',
                'verification_required' => $verificationRequired,
            ], 409);
        }

        $migration->update(['status' => 'source_cleanup_running', 'error' => null]);

        ProcessAccountMigrationStep::dispatch($migration->id, 'cleanup_source', 'api.account_migration');

        return response()->json($this->payload($migration->load($this->relationships())), 202);
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
        $blockers = $account ? ProcessAccountMigrationStep::cutoverBlockers($account) : [];
        $resetRequired = $account ? ProcessAccountMigrationStep::resetRequiredServices($account) : [];
        $verificationRequired = $account ? ProcessAccountMigrationStep::verificationRequiredServices($account) : [];

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
            'reset_required' => $resetRequired,
            'verification_required' => $verificationRequired,
            'can_cleanup_source' => $resetRequired === [] && $verificationRequired === [],
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

}
