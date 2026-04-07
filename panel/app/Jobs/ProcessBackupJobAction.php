<?php

namespace App\Jobs;

use App\Models\BackupJob;
use App\Models\RemoteBackupDestination;
use App\Services\AgentClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBackupJobAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public int $backupJobId,
        public string $action,
        public array $options = [],
    ) {}

    public function handle(): void
    {
        match ($this->action) {
            'create' => $this->createBackup(),
            'restore' => $this->restoreBackup(),
            'restore_path' => $this->restorePath(),
            default => throw new \InvalidArgumentException("Unknown backup action [{$this->action}]."),
        };
    }

    private function createBackup(): void
    {
        $job = $this->job();
        $job->loadMissing(['account.node', 'node']);

        if (! $job->account || ! $job->node) {
            $job->update(['status' => 'failed', 'error' => 'Backup is missing account or node metadata.']);
            return;
        }

        try {
            $client = AgentClient::for($job->node);
            $response = $client->backupCreate($job->account->username, $job->type);
            if (! $response->successful()) {
                throw new \RuntimeException($response->body());
            }

            $result = $response->json();
            $job->update([
                'status' => 'complete',
                'filename' => $result['filename'] ?? null,
                'size_bytes' => $result['size_bytes'] ?? null,
                'error' => null,
            ]);

            if (($this->options['push_remote'] ?? false) && $job->filename) {
                $pushErrors = $this->pushRemoteCopies($client, $job);
                if ($pushErrors !== []) {
                    $job->update(['error' => implode(' | ', $pushErrors)]);
                }
            }
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    private function restoreBackup(): void
    {
        $job = $this->job();
        $job->loadMissing(['account', 'node']);

        if (! $job->account || ! $job->node || ! $job->filename || $job->status !== 'complete') {
            $job->update(['restore_status' => 'failed', 'restore_error' => 'Backup file is not available for restore.']);
            return;
        }

        try {
            $response = AgentClient::for($job->node)->backupRestore($job->account->username, $job->filename);
            if (! $response->successful()) {
                throw new \RuntimeException($response->body());
            }

            $job->update([
                'restore_status' => 'complete',
                'restore_error' => null,
                'last_restored_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $job->update(['restore_status' => 'failed', 'restore_error' => $e->getMessage()]);
        }
    }

    private function restorePath(): void
    {
        $job = $this->job();
        $job->loadMissing(['account', 'node']);

        if (! $job->account || ! $job->node || ! $job->filename || $job->status !== 'complete') {
            $job->update(['restore_status' => 'failed', 'restore_error' => 'Backup file is not available for path restore.']);
            return;
        }

        try {
            $response = AgentClient::for($job->node)->backupRestorePath(
                $job->account->username,
                $job->filename,
                $this->options['source_path'] ?? '',
                $this->options['target_path'] ?? null,
            );
            if (! $response->successful()) {
                throw new \RuntimeException($response->body());
            }

            $job->update([
                'restore_status' => 'complete',
                'restore_error' => null,
                'last_restored_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $job->update(['restore_status' => 'failed', 'restore_error' => $e->getMessage()]);
        }
    }

    private function pushRemoteCopies(AgentClient $client, BackupJob $job): array
    {
        $errors = [];
        foreach (RemoteBackupDestination::where('active', true)->get() as $destination) {
            try {
                $config = array_merge(['destination_type' => $destination->type], $destination->config);
                $response = $client->backupPush($job->account->username, $job->filename, $config);
                if (! $response->successful()) {
                    $errors[] = "{$destination->name}: {$response->body()}";
                }
            } catch (\Throwable $e) {
                $errors[] = "{$destination->name}: {$e->getMessage()}";
            }
        }

        return $errors;
    }

    private function job(): BackupJob
    {
        return BackupJob::with(['account', 'node'])->findOrFail($this->backupJobId);
    }

    public function failed(\Throwable $exception): void
    {
        $job = BackupJob::find($this->backupJobId);
        if (! $job) {
            return;
        }

        if (str_starts_with($this->action, 'restore')) {
            $job->update(['restore_status' => 'failed', 'restore_error' => $exception->getMessage()]);
            return;
        }

        $job->update(['status' => 'failed', 'error' => $exception->getMessage()]);
    }
}
