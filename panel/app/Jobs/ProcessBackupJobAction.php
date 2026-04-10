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
use Illuminate\Support\Facades\File;

class ProcessBackupJobAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BACKUP_LOG = 'strata-backup-jobs.log';

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
        $this->appendBackupLog(sprintf(
            '[%s] Processing backup job #%d action=create account=%s node=%s type=%s',
            now()->toDateTimeString(),
            $job->id,
            $job->account?->username ?? 'unknown',
            $job->node?->name ?? 'unknown',
            $job->type
        ));

        if (! $job->account || ! $job->node) {
            $job->update(['status' => 'failed', 'error' => 'Backup is missing account or node metadata.']);
            $this->appendBackupLog(sprintf('[%s] Backup job failed #%d: missing account or node metadata', now()->toDateTimeString(), $job->id));
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
            $this->appendBackupLog(sprintf(
                '[%s] Backup job completed #%d file=%s size_bytes=%s',
                now()->toDateTimeString(),
                $job->id,
                $job->filename ?? 'unknown',
                (string) ($job->size_bytes ?? 'unknown')
            ));

            if (($this->options['push_remote'] ?? false) && $job->filename) {
                $pushErrors = $this->pushRemoteCopies($client, $job);
                if ($pushErrors !== []) {
                    $job->update(['error' => implode(' | ', $pushErrors)]);
                    $this->appendBackupLog(sprintf('[%s] Backup remote push warnings for job #%d: %s', now()->toDateTimeString(), $job->id, implode(' | ', $pushErrors)));
                }
            }
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->appendBackupLog(sprintf('[%s] Backup job failed #%d: %s', now()->toDateTimeString(), $job->id, $e->getMessage()));
        }
    }

    private function restoreBackup(): void
    {
        $job = $this->job();
        $job->loadMissing(['account', 'node']);
        $this->appendBackupLog(sprintf(
            '[%s] Processing backup job #%d action=restore account=%s node=%s file=%s',
            now()->toDateTimeString(),
            $job->id,
            $job->account?->username ?? 'unknown',
            $job->node?->name ?? 'unknown',
            $job->filename ?? 'unknown'
        ));

        if (! $job->account || ! $job->node || ! $job->filename || $job->status !== 'complete') {
            $job->update(['restore_status' => 'failed', 'restore_error' => 'Backup file is not available for restore.']);
            $this->appendBackupLog(sprintf('[%s] Backup restore failed #%d: backup file is not available for restore', now()->toDateTimeString(), $job->id));
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
            $this->appendBackupLog(sprintf('[%s] Backup restore completed #%d file=%s', now()->toDateTimeString(), $job->id, $job->filename));
        } catch (\Throwable $e) {
            $job->update(['restore_status' => 'failed', 'restore_error' => $e->getMessage()]);
            $this->appendBackupLog(sprintf('[%s] Backup restore failed #%d: %s', now()->toDateTimeString(), $job->id, $e->getMessage()));
        }
    }

    private function restorePath(): void
    {
        $job = $this->job();
        $job->loadMissing(['account', 'node']);
        $this->appendBackupLog(sprintf(
            '[%s] Processing backup job #%d action=restore_path account=%s node=%s file=%s source_path=%s',
            now()->toDateTimeString(),
            $job->id,
            $job->account?->username ?? 'unknown',
            $job->node?->name ?? 'unknown',
            $job->filename ?? 'unknown',
            (string) ($this->options['source_path'] ?? '')
        ));

        if (! $job->account || ! $job->node || ! $job->filename || $job->status !== 'complete') {
            $job->update(['restore_status' => 'failed', 'restore_error' => 'Backup file is not available for path restore.']);
            $this->appendBackupLog(sprintf('[%s] Backup path restore failed #%d: backup file is not available for path restore', now()->toDateTimeString(), $job->id));
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
            $this->appendBackupLog(sprintf('[%s] Backup path restore completed #%d file=%s', now()->toDateTimeString(), $job->id, $job->filename));
        } catch (\Throwable $e) {
            $job->update(['restore_status' => 'failed', 'restore_error' => $e->getMessage()]);
            $this->appendBackupLog(sprintf('[%s] Backup path restore failed #%d: %s', now()->toDateTimeString(), $job->id, $e->getMessage()));
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
            $this->appendBackupLog(sprintf('[%s] Backup restore job crashed #%d: %s', now()->toDateTimeString(), $job->id, $exception->getMessage()));
            return;
        }

        $job->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        $this->appendBackupLog(sprintf('[%s] Backup job crashed #%d: %s', now()->toDateTimeString(), $job->id, $exception->getMessage()));
    }

    private function appendBackupLog(string $line): void
    {
        $path = storage_path('logs/' . self::BACKUP_LOG);
        File::ensureDirectoryExists(dirname($path));
        File::append($path, $line . PHP_EOL);
    }
}
