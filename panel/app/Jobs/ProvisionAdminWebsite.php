<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\DomainProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionAdminWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(
        public int $accountId,
        public string $domain,
        public ?int $actorId = null,
        public bool $provisionAccount = true,
        public bool $cleanupAccountOnDomainFailure = false,
    ) {}

    public function handle(AccountProvisioner $accountProvisioner, DomainProvisioner $domainProvisioner): void
    {
        $account = Account::with(['node', 'domains'])->findOrFail($this->accountId);
        $actor = $this->actorId ? User::find($this->actorId) : null;

        $account->update([
            'status' => 'provisioning',
            'provisioning_error' => null,
        ]);

        if ($account->domains()->exists()) {
            $account->update([
                'status' => 'active',
                'provisioning_error' => null,
            ]);

            return;
        }

        if ($this->provisionAccount) {
            [$success, $error] = $accountProvisioner->provision($account);

            if (! $success) {
                $this->markFailed($account, $error ?: 'Unknown provisioning error.');

                AuditLog::record('admin_website.provision_failed', $account, [
                    'username' => $account->username,
                    'node' => $account->node_id,
                    'domain' => $this->domain,
                    'error' => $error,
                    'queued' => true,
                ], $actor);

                return;
            }

            $account->refresh();
        }

        $domain = $account->domains()->firstOrCreate(
            ['domain' => $this->domain],
            [
                'node_id' => $account->node_id,
                'document_root' => "/var/www/{$account->username}/public_html",
                'php_version' => $account->php_version,
            ]
        );

        [$success, $error] = $domainProvisioner->provision($domain);

        if (! $success) {
            $message = $error ?: 'Unknown website provisioning error.';

            if ($domain->wasRecentlyCreated) {
                $domain->forceDelete();
            }

            if ($this->cleanupAccountOnDomainFailure) {
                [$accountRemoved, $accountCleanupError] = $accountProvisioner->deprovision($account);

                if ($accountRemoved) {
                    $account->forceDelete();
                } elseif ($accountCleanupError) {
                    $message .= ' Cleanup warning: ' . $accountCleanupError;
                    $this->markFailed($account, $message);
                }
            } else {
                $this->markFailed($account, $message);
            }

            AuditLog::record('admin_website.provision_failed', $account->fresh() ?? $account, [
                'username' => $account->username,
                'node' => $account->node_id,
                'domain' => $this->domain,
                'error' => $message,
                'queued' => true,
            ], $actor);

            return;
        }

        $account->refresh()->update([
            'status' => 'active',
            'provisioning_error' => null,
        ]);

        AuditLog::record('admin_website.created', $account->fresh(), [
            'username' => $account->username,
            'node' => $account->node_id,
            'domain' => $this->domain,
            'provisioned' => true,
            'queued' => true,
        ], $actor);
    }

    public function failed(?\Throwable $exception): void
    {
        $account = Account::find($this->accountId);

        if ($account) {
            $this->markFailed($account, $exception?->getMessage() ?? 'Website provisioning worker failed.');
        }
    }

    private function markFailed(Account $account, string $error): void
    {
        $account->update([
            'status' => 'failed',
            'provisioning_error' => $error,
        ]);
    }
}
