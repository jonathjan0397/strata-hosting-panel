<?php

namespace App\Console\Commands;

use App\Services\StrataLicense;
use Illuminate\Console\Command;

class LicenseSync extends Command
{
    protected $signature   = 'strata:license-sync';
    protected $description = 'Ping the Strata license server and refresh the cached license state.';

    public function handle(): int
    {
        $result = StrataLicense::sync();

        $this->line('Status:   <info>' . ($result['status'] ?? 'unknown') . '</info>');
        $this->line('Features: <info>' . (implode(', ', $result['features'] ?? []) ?: 'none') . '</info>');
        $this->line('Messages: <info>' . count($result['messages'] ?? []) . '</info>');

        if (($result['status'] ?? '') === 'inactive') {
            $this->warn('Installation is inactive. The panel continues operating, but admin review is recommended.');
        }

        return Command::SUCCESS;
    }
}
