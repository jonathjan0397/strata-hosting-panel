<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Models\Domain;
use App\Services\AgentClient;
use App\Services\MailProvisioner;
use App\Services\SnappyMailProvisioner;
use Illuminate\Console\Command;

class RepairMailboxes extends Command
{
    protected $signature = 'strata:mail-repair
        {--dry-run : Report issues without making changes}
        {--domain= : Limit repair to one mail domain}
        {--email= : Repair a specific email address}
        {--snappy-only : Only repair SnappyMail domain profiles, skip mailbox provisioning}';

    protected $description = 'Verify and repair mailboxes: ensure Postfix/Dovecot entries exist and SnappyMail profiles are present.';

    public function handle(MailProvisioner $mailProvisioner, SnappyMailProvisioner $snappyMail): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $domainFilter = $this->option('domain');
        $emailFilter = $this->option('email');
        $snappyOnly = (bool) $this->option('snappy-only');

        // Phase 1: Repair SnappyMail domain profiles for all mail-enabled domains
        $this->line('=== Phase 1: SnappyMail Domain Profiles ===');
        [$snappyOk, $snappyErr, $provisioned, $errors] = $snappyMail->provisionAll();
        if (! $snappyOk) {
            $this->warn("SnappyMail provisioning encountered issues: {$snappyErr}");
        }
        $this->line("Provisioned {$provisioned} SnappyMail domain profile(s).");
        if (! empty($errors)) {
            foreach ($errors as $e) {
                $this->warn("  {$e}");
            }
        }

        if ($snappyOnly) {
            return Command::SUCCESS;
        }

        // Phase 2: Verify and repair mailbox entries
        $this->line('=== Phase 2: Mailbox Verification ===');

        $query = EmailAccount::query()
            ->with(['domain.node'])
            ->whereNull('deleted_at');

        if ($domainFilter) {
            $query->whereHas('domain', fn ($q) => $q->where('domain', $domainFilter));
        }

        if ($emailFilter) {
            $query->where('email', $emailFilter);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->line('No mailboxes to verify.');
            return Command::SUCCESS;
        }

        $this->line("Checking {$total} mailbox(es)...");
        $repaired = 0;
        $failed = 0;
        $skipped = 0;

        $query->orderBy('email')->each(function (EmailAccount $mailbox) use (
            $mailProvisioner, $dryRun, &$repaired, &$failed, &$skipped
        ) {
            $this->output->write("  {$mailbox->email} ... ");

            $domain = $mailbox->domain;
            if (! $domain || ! $domain->node) {
                $this->error('SKIPPED (missing domain or node)');
                $skipped++;
                return;
            }

            // Verify via agent
            [$verified, $verifyError] = $mailProvisioner->verifyMailbox($domain, $mailbox->email);
            if ($verified) {
                $this->line('OK');
                return;
            }

            // Mailbox not found on node — re-provision
            if ($dryRun) {
                $this->warn('MISSING (dry-run, would repair)');
                $repaired++;
                return;
            }

            $this->output->write("re-provisioning... ");

            // Re-create via agent
            if ($mailbox->migration_reset_required) {
                [$ok, $err] = $mailProvisioner->changePassword($mailbox, '');
                if (! $ok) {
                    $this->error("FAILED (password reset: {$err})");
                    $failed++;
                    return;
                }
            } else {
                $response = AgentClient::for($domain->node)->post('/mail/mailbox', [
                    'email' => $mailbox->email,
                    'password' => str()->random(32),
                ]);
                if (! $response->successful()) {
                    $this->error("FAILED (agent: {$response->body()})");
                    $failed++;
                    return;
                }
            }

            $this->line('REPAIRED');
            $repaired++;
        });

        $this->line('');
        $this->line("Results: {$total} checked, {$repaired} repaired, {$failed} failed, {$skipped} skipped");

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
