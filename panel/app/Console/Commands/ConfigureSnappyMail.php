<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\SnappyMailProvisioner;
use Illuminate\Console\Command;

class ConfigureSnappyMail extends Command
{
    protected $signature = 'strata:webmail-configure
        {--domain= : Limit repair to one mail domain}
        {--force : Force creation of missing data directory}';

    protected $description = 'Repair SnappyMail domain profiles for Strata-managed mail domains.';

    public function handle(SnappyMailProvisioner $snappyMail): int
    {
        [$defaultOk, $defaultError] = $snappyMail->provisionDefault();
        if (! $defaultOk) {
            $this->warn("Default SnappyMail profile was not updated: {$defaultError}");
        }

        [$repairOk, $repairError, $repaired] = $snappyMail->repairStaleLocalProfiles();
        if (! $repairOk) {
            $this->warn("Stale SnappyMail profile repair was skipped: {$repairError}");
        } elseif ($repaired > 0) {
            $this->line("Repaired {$repaired} stale local SnappyMail profile(s).");
        }

        // Use provisionAll to create profiles for all mail-enabled domains
        [$allOk, $allErr, $provisioned, $errors] = $snappyMail->provisionAll();
        if (! $allOk) {
            $this->warn("SnappyMail provisioning encountered issues: {$allErr}");
        }
        $this->line("Provisioned {$provisioned} domain profile(s).");
        if (! empty($errors)) {
            foreach ($errors as $e) {
                $this->warn("  {$e}");
            }
        }

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }
}
