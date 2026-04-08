<?php

namespace App\Jobs;

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
use App\Models\WebDavAccount;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use App\Services\DomainProvisioner;
use App\Services\MailProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessAccountMigrationStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public int $migrationId,
        public string $step,
        public string $auditPrefix = 'account.migration',
    ) {}

    public function handle(): void
    {
        match ($this->step) {
            'prepare' => $this->prepare(),
            'transfer' => $this->transfer(),
            'restore' => $this->restore(),
            'cutover' => $this->cutover(),
            'cleanup_source' => $this->cleanupSource(),
            default => throw new \InvalidArgumentException("Unknown migration step [{$this->step}]."),
        };
    }

    private function prepare(): void
    {
        $migration = $this->migration(['account.node', 'targetNode']);
        $account = $migration->account;

        if (! $account || ! $account->node || ! $migration->targetNode) {
            $this->failMigration($migration, 'Migration is missing account or node metadata.');
            return;
        }

        $backupJob = BackupJob::create([
            'account_id' => $account->id,
            'node_id' => $account->node_id,
            'type' => 'full',
            'status' => 'running',
            'trigger' => 'manual',
        ]);

        $migration->update(['backup_job_id' => $backupJob->id, 'status' => 'backup_running', 'error' => null]);

        try {
            $response = AgentClient::for($account->node)->backupCreate($account->username, 'full');
            if (! $response->successful()) {
                throw new \RuntimeException($response->body());
            }

            $result = $response->json();
            $backupJob->update([
                'status' => 'complete',
                'filename' => $result['filename'] ?? null,
                'size_bytes' => $result['size_bytes'] ?? null,
            ]);
            $migration->update(['status' => 'backup_ready']);

            AuditLog::record("{$this->auditPrefix}_backup_ready", $account, [
                'migration_id' => $migration->id,
                'source_node_id' => $account->node_id,
                'target_node_id' => $migration->target_node_id,
                'backup_job_id' => $backupJob->id,
                'filename' => $backupJob->filename,
                'queued' => true,
            ]);
        } catch (\Throwable $e) {
            $backupJob->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->failMigration($migration, $e->getMessage());
        }
    }

    private function transfer(): void
    {
        $migration = $this->migration(['account', 'sourceNode', 'targetNode', 'backupJob']);

        if (! $migration->account || ! $migration->sourceNode || ! $migration->targetNode || ! $migration->backupJob?->filename) {
            $this->failMigration($migration, 'Migration is missing account, node, or backup metadata.');
            return;
        }

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

            AuditLog::record("{$this->auditPrefix}_transfer_ready", $migration->account, [
                'migration_id' => $migration->id,
                'target_backup_job_id' => $targetBackup->id,
                'filename' => $targetBackup->filename,
                'queued' => true,
            ]);
        } catch (\Throwable $e) {
            $targetBackup->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->failMigration($migration, $e->getMessage());
        }
    }

    private function restore(): void
    {
        $migration = $this->migration(['account', 'targetNode', 'targetBackupJob']);

        if (! $migration->account || ! $migration->targetNode || ! $migration->targetBackupJob?->filename) {
            $this->failMigration($migration, 'Migration is missing account, target node, or target backup metadata.');
            return;
        }

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

            $migration->update(['status' => 'restored', 'completed_at' => now()]);

            AuditLog::record("{$this->auditPrefix}_restored", $migration->account, [
                'migration_id' => $migration->id,
                'target_backup_job_id' => $migration->target_backup_job_id,
                'source_retained' => true,
                'queued' => true,
            ]);
        } catch (\Throwable $e) {
            $this->failMigration($migration, $e->getMessage());
        }
    }

    private function cutover(): void
    {
        $migration = $this->migration(['account.domains', 'sourceNode', 'targetNode']);

        if (! $migration->account || ! $migration->sourceNode || ! $migration->targetNode) {
            $this->failMigration($migration, 'Migration is missing account or node metadata.');
            return;
        }

        $blockers = self::cutoverBlockers($migration->account);
        if ($blockers !== []) {
            $this->failMigration($migration, 'Automatic cutover is blocked until manual remediation is complete for: ' . implode(', ', $blockers) . '.');
            return;
        }

        $account = $migration->account;
        $sourceNodeId = $migration->source_node_id;
        $targetNodeId = $migration->target_node_id;
        $domainIds = $account->domains->pluck('id')->all();
        $domainMailSnapshots = $account->domains
            ->mapWithKeys(fn ($domain) => [$domain->id => $domain->only([
                'mail_enabled',
                'dkim_enabled',
                'dkim_public_key',
                'dkim_dns_record',
                'spf_enabled',
                'spf_dns_record',
                'dmarc_enabled',
                'dmarc_dns_record',
                'server_ip',
            ])])
            ->all();
        $forwarderIds = EmailForwarder::where('account_id', $account->id)->pluck('id')->all();
        $mailboxIds = EmailAccount::where('account_id', $account->id)->pluck('id')->all();
        $ftpIds = FtpAccount::where('account_id', $account->id)->pluck('id')->all();
        $webDavIds = WebDavAccount::where('account_id', $account->id)->pluck('id')->all();
        $appInstallationIds = AppInstallation::where('account_id', $account->id)->pluck('id')->all();
        $databaseIds = HostingDatabase::where('account_id', $account->id)
            ->where(fn ($query) => $query->where('engine', 'mysql')->orWhereNull('engine'))
            ->pluck('id')
            ->all();
        $databaseGrantIds = DatabaseGrant::where('account_id', $account->id)
            ->where(fn ($query) => $query->where('engine', 'mysql')->orWhereNull('engine'))
            ->pluck('id')
            ->all();

        try {
            DB::transaction(function () use ($account, $domainIds, $forwarderIds, $mailboxIds, $ftpIds, $webDavIds, $appInstallationIds, $databaseIds, $databaseGrantIds, $targetNodeId) {
                $account->update(['node_id' => $targetNodeId]);

                if ($domainIds !== []) {
                    $account->domains()->whereIn('id', $domainIds)->update(['node_id' => $targetNodeId]);
                    DnsZone::whereIn('domain_id', $domainIds)->update(['node_id' => $targetNodeId]);
                }

                if ($forwarderIds !== []) {
                    EmailForwarder::whereIn('id', $forwarderIds)->update(['node_id' => $targetNodeId]);
                }

                if ($mailboxIds !== []) {
                    EmailAccount::whereIn('id', $mailboxIds)->update(['node_id' => $targetNodeId, 'migration_reset_required' => true, 'active' => false]);
                }

                if ($ftpIds !== []) {
                    FtpAccount::whereIn('id', $ftpIds)->update(['node_id' => $targetNodeId, 'migration_reset_required' => true, 'active' => false]);
                }

                if ($webDavIds !== []) {
                    WebDavAccount::whereIn('id', $webDavIds)->update(['node_id' => $targetNodeId, 'migration_reset_required' => true, 'active' => false]);
                }

                if ($appInstallationIds !== []) {
                    AppInstallation::whereIn('id', $appInstallationIds)->update(['node_id' => $targetNodeId, 'migration_verification_required' => true]);
                }

                if ($databaseIds !== []) {
                    HostingDatabase::whereIn('id', $databaseIds)->update(['node_id' => $targetNodeId, 'migration_reset_required' => true]);
                }

                if ($databaseGrantIds !== []) {
                    DatabaseGrant::whereIn('id', $databaseGrantIds)->update(['node_id' => $targetNodeId, 'migration_reset_required' => true]);
                }
            });

            foreach ($account->domains()->with('node', 'account')->get() as $domain) {
                [$synced, $error] = app(DomainProvisioner::class)->reprovision($domain);
                if (! $synced) {
                    throw new \RuntimeException("{$domain->domain}: {$error}");
                }

                if ($domain->mail_enabled) {
                    [$mailSynced, $mailError] = app(MailProvisioner::class)->enableDomain($domain);
                    if (! $mailSynced) {
                        throw new \RuntimeException("mail {$domain->domain}: {$mailError}");
                    }

                    (new DnsProvisioner(AgentClient::for($migration->targetNode)))->addMailRecords($domain->refresh());
                }
            }

            foreach (EmailForwarder::whereIn('id', $forwarderIds)->with('node')->get() as $forwarder) {
                $response = AgentClient::for($migration->targetNode)->post('/mail/forwarder', [
                    'source' => $forwarder->source,
                    'destination' => $forwarder->destination,
                ]);

                if (! $response->successful()) {
                    throw new \RuntimeException("forwarder {$forwarder->source}: {$response->body()}");
                }
            }
        } catch (\Throwable $e) {
            DB::transaction(function () use ($account, $domainIds, $domainMailSnapshots, $forwarderIds, $mailboxIds, $ftpIds, $webDavIds, $appInstallationIds, $databaseIds, $databaseGrantIds, $sourceNodeId) {
                $account->update(['node_id' => $sourceNodeId]);

                if ($domainIds !== []) {
                    $account->domains()->whereIn('id', $domainIds)->update(['node_id' => $sourceNodeId]);
                    DnsZone::whereIn('domain_id', $domainIds)->update(['node_id' => $sourceNodeId]);
                    foreach ($domainMailSnapshots as $domainId => $snapshot) {
                        $account->domains()->where('id', $domainId)->update($snapshot);
                    }
                }

                if ($forwarderIds !== []) {
                    EmailForwarder::whereIn('id', $forwarderIds)->update(['node_id' => $sourceNodeId]);
                }

                if ($mailboxIds !== []) {
                    EmailAccount::whereIn('id', $mailboxIds)->update(['node_id' => $sourceNodeId, 'migration_reset_required' => false, 'active' => true]);
                }

                if ($ftpIds !== []) {
                    FtpAccount::whereIn('id', $ftpIds)->update(['node_id' => $sourceNodeId, 'migration_reset_required' => false, 'active' => true]);
                }

                if ($webDavIds !== []) {
                    WebDavAccount::whereIn('id', $webDavIds)->update(['node_id' => $sourceNodeId, 'migration_reset_required' => false, 'active' => true]);
                }

                if ($appInstallationIds !== []) {
                    AppInstallation::whereIn('id', $appInstallationIds)->update(['node_id' => $sourceNodeId, 'migration_verification_required' => false]);
                }

                if ($databaseIds !== []) {
                    HostingDatabase::whereIn('id', $databaseIds)->update(['node_id' => $sourceNodeId, 'migration_reset_required' => false]);
                }

                if ($databaseGrantIds !== []) {
                    DatabaseGrant::whereIn('id', $databaseGrantIds)->update(['node_id' => $sourceNodeId, 'migration_reset_required' => false]);
                }
            });

            $this->failMigration($migration, 'Migration cutover failed and panel ownership was rolled back: ' . $e->getMessage());
            return;
        }

        $migration->update(['status' => 'complete', 'completed_at' => now()]);

        AuditLog::record("{$this->auditPrefix}_cutover_complete", $account->refresh(), [
            'migration_id' => $migration->id,
            'source_node_id' => $sourceNodeId,
            'target_node_id' => $targetNodeId,
            'domains_reprovisioned' => count($domainIds),
            'forwarders_reprovisioned' => count($forwarderIds),
            'reset_required' => [
                'mailboxes' => count($mailboxIds),
                'ftp_accounts' => count($ftpIds),
                'web_disk_accounts' => count($webDavIds),
                'mysql_databases' => count($databaseIds),
                'mysql_database_grants' => count($databaseGrantIds),
                'app_installations' => count($appInstallationIds),
            ],
            'source_retained' => true,
            'queued' => true,
        ]);
    }

    private function cleanupSource(): void
    {
        $migration = $this->migration(['account', 'sourceNode']);

        if (! $migration->account || ! $migration->sourceNode) {
            $this->failMigration($migration, 'Migration is missing account or source node metadata.');
            return;
        }

        $resetRequired = self::resetRequiredServices($migration->account);
        if ($resetRequired !== []) {
            $migration->update([
                'status' => 'complete',
                'error' => 'Source cleanup is blocked until reset-required services are handled: ' . implode(', ', array_keys($resetRequired)) . '.',
            ]);
            return;
        }

        $verificationRequired = self::verificationRequiredServices($migration->account);
        if ($verificationRequired !== []) {
            $migration->update([
                'status' => 'complete',
                'error' => 'Source cleanup is blocked until verification-required services are handled: ' . implode(', ', array_keys($verificationRequired)) . '.',
            ]);
            return;
        }

        try {
            $response = AgentClient::for($migration->sourceNode)->deprovisionAccount($migration->account->username);
            if (! $response->successful()) {
                throw new \RuntimeException($response->json('message') ?? $response->body());
            }
        } catch (\Throwable $e) {
            $migration->update(['status' => 'complete', 'error' => 'Source cleanup failed: ' . $e->getMessage()]);
            return;
        }

        $migration->update(['status' => 'source_cleaned', 'error' => null]);

        AuditLog::record("{$this->auditPrefix}_source_cleaned", $migration->account, [
            'migration_id' => $migration->id,
            'source_node_id' => $migration->source_node_id,
            'target_node_id' => $migration->target_node_id,
            'queued' => true,
        ]);
    }

    public static function cutoverBlockers(Account $account): array
    {
        $checks = [
            'PostgreSQL databases' => HostingDatabase::where('account_id', $account->id)->where('engine', 'postgresql')->count(),
            'PostgreSQL database grants' => DatabaseGrant::where('account_id', $account->id)->where('engine', 'postgresql')->count(),
        ];

        return array_keys(array_filter($checks, fn (int $count) => $count > 0));
    }

    public static function resetRequiredServices(Account $account): array
    {
        $checks = [
            'mailboxes' => EmailAccount::where('account_id', $account->id)->where('migration_reset_required', true)->count(),
            'FTP accounts' => FtpAccount::where('account_id', $account->id)->where('migration_reset_required', true)->count(),
            'Web Disk accounts' => WebDavAccount::where('account_id', $account->id)->where('migration_reset_required', true)->count(),
            'MySQL databases' => HostingDatabase::where('account_id', $account->id)->where('migration_reset_required', true)->count(),
            'MySQL database grants' => DatabaseGrant::where('account_id', $account->id)->where('migration_reset_required', true)->count(),
        ];

        return array_filter($checks, fn (int $count) => $count > 0);
    }

    public static function verificationRequiredServices(Account $account): array
    {
        $checks = [
            'app installs' => AppInstallation::where('account_id', $account->id)->where('migration_verification_required', true)->count(),
        ];

        return array_filter($checks, fn (int $count) => $count > 0);
    }

    private function migration(array $relations): AccountMigration
    {
        return AccountMigration::with($relations)->findOrFail($this->migrationId);
    }

    private function failMigration(AccountMigration $migration, string $error): void
    {
        $migration->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
