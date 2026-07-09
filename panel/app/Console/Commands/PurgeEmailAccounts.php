<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use Illuminate\Console\Command;

class PurgeEmailAccounts extends Command
{
    protected $signature = 'strata:purge-email-accounts
        {--days=30 : Purge records soft-deleted more than this many days ago}';

    protected $description = 'Permanently delete email accounts that were soft-deleted past the retention period.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = EmailAccount::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->forceDelete();

        $this->line("Purged {$count} email account(s) soft-deleted before {$cutoff->toDateTimeString()}.");

        return Command::SUCCESS;
    }
}
