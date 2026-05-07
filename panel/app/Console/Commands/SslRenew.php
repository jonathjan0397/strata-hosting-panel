<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\DomainProvisioner;
use Illuminate\Console\Command;

class SslRenew extends Command
{
    protected $signature = 'ssl:renew {--days=14 : Renew certs expiring within this many days}';
    protected $description = 'Auto-renew SSL certificates that are expiring soon';

    public function handle(DomainProvisioner $provisioner): int
    {
        $days = (int) $this->option('days');

        $domains = Domain::with(['account', 'node'])
            ->where('ssl_enabled', true)
            ->whereNotNull('ssl_expires_at')
            ->where('ssl_expires_at', '<=', now()->addDays($days))
            ->get();

        if ($domains->isEmpty()) {
            $this->info('No certificates due for renewal.');
            return self::SUCCESS;
        }

        $this->info("Found {$domains->count()} certificate(s) to renew.");

        $renewed = 0;
        $failed  = 0;

        foreach ($domains as $domain) {
            $this->line("  Renewing {$domain->domain}…");

            [$ok, $error] = $provisioner->issueSSL($domain, (bool) $domain->ssl_wildcard);

            if ($ok) {
                $renewed++;
                AuditLog::record('ssl.auto_renewed', $domain, [
                    'domain'     => $domain->domain,
                    'expires_at' => $domain->fresh()->ssl_expires_at?->toIso8601String(),
                ]);
                $this->line("    <fg=green>✓ Renewed — expires {$domain->fresh()->ssl_expires_at?->toDateString()}</>");
            } else {
                $failed++;
                AuditLog::record('ssl.auto_renew_failed', $domain, [
                    'domain' => $domain->domain,
                    'error'  => $error,
                ]);
                $this->line("    <fg=red>✗ Failed: {$error}</>");
            }
        }

        $this->info("Done. Renewed: {$renewed}, Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
