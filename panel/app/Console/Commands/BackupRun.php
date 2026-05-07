<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Services\AgentClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--account= : Only back up a specific account ID}
        {--type=full : files|databases|full}
        {--scheduled : Only run accounts whose schedule matches the current time window}';

    protected $description = 'Run backups — scheduled (respects per-account schedule) or on-demand';

    public function handle(): int
    {
        $type        = $this->option('type');
        $accountId   = $this->option('account');
        $scheduledRun = $this->option('scheduled');

        $query = Account::with('node')->whereNotNull('node_id');

        if ($accountId) {
            $query->where('id', $accountId);
        } elseif ($scheduledRun) {
            $now = Carbon::now();
            // Current hour:minute window (matches within the same hour slot)
            $currentHour = $now->format('H');
            // Match accounts whose backup_time falls in this hour (HH:*)
            $query->where('backup_schedule', '!=', 'disabled')
                  ->whereRaw("SUBSTRING(backup_time, 1, 2) = ?", [$currentHour]);

            // For weekly schedules, also filter by day of week
            $query->where(function ($q) use ($now) {
                $q->where('backup_schedule', 'daily')
                  ->orWhere(function ($q2) use ($now) {
                      $q2->where('backup_schedule', 'weekly')
                         ->where('backup_day', (int) $now->format('w')); // 0=Sun…6=Sat
                  });
            });
        } else {
            // Manual/force run: back up all accounts with schedule != disabled
            $query->where('backup_schedule', '!=', 'disabled');
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No accounts matched for backup.');
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
                'trigger'    => $scheduledRun ? 'scheduled' : 'manual',
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
