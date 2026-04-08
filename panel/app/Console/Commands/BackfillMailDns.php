<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\DomainProvisioner;
use Illuminate\Console\Command;

class BackfillMailDns extends Command
{
    protected $signature = 'strata:backfill-mail-dns {--domain= : Limit to one domain name}';
    protected $description = 'Backfill mail provisioning and DNS records for existing hosted domains.';

    public function handle(DomainProvisioner $provisioner): int
    {
        $domains = Domain::query()
            ->with(['node', 'account'])
            ->when(
                $this->option('domain'),
                fn ($query, $domain) => $query->where('domain', strtolower(trim((string) $domain)))
            )
            ->orderBy('domain')
            ->get();

        if ($domains->isEmpty()) {
            $this->line('No domains matched.');
            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        foreach ($domains as $domain) {
            if (! $domain->node || ! $domain->account) {
                $this->warn("Skipping {$domain->domain}: missing node/account.");
                $failed++;
                continue;
            }

            [$success, $error] = $provisioner->ensureMailProvisioned($domain);

            if (! $success) {
                $this->warn("Failed {$domain->domain}: {$error}");
                $failed++;
                continue;
            }

            $this->info("Updated {$domain->domain}");
            $updated++;
        }

        $this->info("Mail/DNS backfill complete. Updated: {$updated}; failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
