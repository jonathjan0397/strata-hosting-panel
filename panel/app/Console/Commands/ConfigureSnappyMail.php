<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\SnappyMailProvisioner;
use Illuminate\Console\Command;

class ConfigureSnappyMail extends Command
{
    protected $signature = 'strata:webmail-configure {--domain= : Limit repair to one mail domain}';

    protected $description = 'Repair SnappyMail domain profiles for Strata-managed mail domains.';

    public function handle(SnappyMailProvisioner $snappyMail): int
    {
        [$defaultOk, $defaultError] = $snappyMail->provisionDefault();
        if (! $defaultOk) {
            $this->warn("Default SnappyMail profile was not updated: {$defaultError}");
        }

        $query = Domain::query()
            ->with('node')
            ->where('mail_enabled', true);

        if ($domain = $this->option('domain')) {
            $query->where('domain', $domain);
        }

        $failed = 0;
        $query->orderBy('domain')->each(function (Domain $domain) use ($snappyMail, &$failed): void {
            [$ok, $error] = $snappyMail->provisionDomain($domain);

            if ($ok) {
                $this->line("✓ {$domain->domain}");
                return;
            }

            $failed++;
            $this->error("✗ {$domain->domain}: {$error}");
        });

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
