<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public int $accountId,
        public ?int $actorId = null,
        public string $auditPrefix = 'account',
    ) {}

    public function handle(AccountProvisioner $provisioner): void
    {
        $account = Account::with('node')->findOrFail($this->accountId);
        $actor = $this->actorId ? User::find($this->actorId) : null;

        $account->update([
            'status' => 'provisioning',
            'provisioning_error' => null,
        ]);

        [$success, $error] = $provisioner->provision($account);

        if (! $success) {
            $this->markFailed($account, $error ?: 'Unknown provisioning error.');

            AuditLog::record("{$this->auditPrefix}.provision_failed", $account, [
                'username' => $account->username,
                'node' => $account->node_id,
                'error' => $error,
                'queued' => true,
            ], $actor);

            return;
        }

        $account->refresh()->update([
            'status' => 'active',
            'provisioning_error' => null,
        ]);

        AuditLog::record("{$this->auditPrefix}.created", $account->refresh(), [
            'username' => $account->username,
            'node' => $account->node_id,
            'provisioned' => true,
            'queued' => true,
        ], $actor);
    }

    public function failed(?\Throwable $exception): void
    {
        $account = Account::find($this->accountId);

        if ($account) {
            $this->markFailed($account, $exception?->getMessage() ?? 'Account provisioning worker failed.');
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
