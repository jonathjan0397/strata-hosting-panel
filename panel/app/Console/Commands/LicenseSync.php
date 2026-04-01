<?php

namespace App\Console\Commands;

use App\Services\StrataLicense;
use Illuminate\Console\Command;

class LicenseSync extends Command
{
    protected $signature   = 'strata:license-sync';
    protected $description = 'Ping the Strata license server and refresh the cached feature list.';

    public function handle(): int
    {
        $result = StrataLicense::sync();

        $this->line('Status:   <info>' . ($result['status'] ?? 'unknown') . '</info>');
        $this->line('Features: <info>' . (implode(', ', $result['features'] ?? []) ?: 'none') . '</info>');

        if (($result['status'] ?? '') === 'suspended') {
            $this->warn('Installation is suspended. Contact support.');
        }

        return Command::SUCCESS;
    }
}
