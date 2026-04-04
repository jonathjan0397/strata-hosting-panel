<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Services\AgentClient;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature   = 'backup:run {--account= : Only back up a specific account ID} {--type=full : files|databases|full}';
    protected $description = 'Run scheduled backups for all accounts (or a single account)';

    public function handle(): int
    {
        $type      = $this->option('type');
        $accountId = $this->option('account');

        $query = Account::with('node')->whereNotNull('node_id');
        if ($accountId) {
            $query->where('id', $accountId);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No accounts to back up.');
            return 0;
        }

        $this->info("Running {$type} backup for {$accounts->count()} account(s)…");

        foreach ($accounts as $account) {
            $this->line("  → {$account->username}");

            $job = BackupJob::create([
                'account_id' => $account->id,
                'node_id'    => $account->node_id,
                'type'       => $type,
                'status'     => 'running',
                'trigger'    => 'scheduled',
            ]);

            try {
                $client   = new AgentClient($account->node);
                $response = $client->backupCreate($account->username, $type);

                if ($response->successful()) {
                    $result = $response->json();
                    $job->update([
                        'status'     => 'complete',
                        'filename'   => $result['filename'] ?? null,
                        'size_bytes' => $result['size_bytes'] ?? null,
                    ]);

                    AuditLog::create([
                        'actor_type' => 'system',
                        'action'     => 'backup.scheduled_complete',
                        'subject'    => $account->username,
                        'payload'    => ['filename' => $result['filename'] ?? null, 'type' => $type],
                    ]);

                    $this->line("     <info>complete</info> — {$result['filename']}");
                } else {
                    $job->update(['status' => 'failed', 'error' => $response->body()]);
                    $this->line('     <error>failed</error> — ' . $response->body());
                }
            } catch (\Throwable $e) {
                $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $this->line('     <error>failed</error> — ' . $e->getMessage());
            }
        }

        $this->info('Done.');
        return 0;
    }
}
