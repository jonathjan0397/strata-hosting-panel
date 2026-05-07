<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Domain;
use App\Services\DomainProvisioner;
use Illuminate\Console\Command;

class RepairPhpSockets extends Command
{
    protected $signature = 'strata:repair-php-sockets
        {--account= : Limit repair to one account username}
        {--domain= : Limit repair to the account that owns one domain}';

    protected $description = 'Rebuild account PHP-FPM pool mappings and reprovision domains to repair stale PHP socket references.';

    public function handle(DomainProvisioner $provisioner): int
    {
        $accounts = Account::query()
            ->with(['node', 'domains.node'])
            ->when($this->option('account'), fn ($query, $username) => $query->where('username', trim((string) $username)))
            ->when($this->option('domain'), function ($query, $domainName) {
                $domainName = strtolower(trim((string) $domainName));
                $domain = Domain::where('domain', $domainName)->first();

                if ($domain) {
                    $query->whereKey($domain->account_id);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('username')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No accounts matched.');
            return self::FAILURE;
        }

        $updated = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            if (! $account->node) {
                $this->warn("Skipping {$account->username}: missing node.");
                $failed++;
                continue;
            }

            [$ok, $error] = $provisioner->repairPhpSockets($account);

            if (! $ok) {
                $this->warn("Failed {$account->username}: {$error}");
                $failed++;
                continue;
            }

            $this->info("Repaired {$account->username}");
            $updated++;
        }

        $this->info("PHP socket repair complete. Updated: {$updated}; failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
