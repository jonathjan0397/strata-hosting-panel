<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use Illuminate\Support\Facades\Log;

class MailProvisioner
{
    /**
     * Enable mail for a domain: provisions Postfix/Dovecot/DKIM on the node.
     * Returns [bool $success, ?string $error, array $dnsRecords].
     */
    public function enableDomain(Domain $domain): array
    {
        try {
            $response = AgentClient::for($domain->node)->post('/mail/domain', [
                'domain' => $domain->domain,
            ]);

            if (! $response->successful()) {
                Log::error("MailProvisioner: enableDomain failed for {$domain->domain}: {$response->body()}");
                return [false, $response->body(), []];
            }

            $data = $response->json();

            // Store DNS records in domain row
            $domain->update([
                'mail_enabled'    => true,
                'dkim_enabled'    => true,
                'dkim_public_key' => $data['dkim_pubkey'] ?? null,
                'dkim_dns_record' => $data['dkim_pubkey'] ?? null,
                'spf_enabled'     => true,
                'spf_dns_record'  => $data['spf_record'] ?? null,
                'dmarc_enabled'   => true,
                'dmarc_dns_record' => $data['dmarc_record'] ?? null,
                'server_ip'       => $data['server_ip'] ?? null,
            ]);

            app(SnappyMailProvisioner::class)->provisionDomain($domain->refresh());

            Log::info("MailProvisioner: mail enabled for {$domain->domain}");
            return [true, null, $data];
        } catch (\Throwable $e) {
            Log::error("MailProvisioner: enableDomain exception for {$domain->domain}: {$e->getMessage()}");
            return [false, $e->getMessage(), []];
        }
    }

    /**
     * Create a mailbox on the node and persist to DB.
     */
    public function createMailbox(Domain $domain, string $localPart, string $password, int $quotaMb = 0): array
    {
        $localPart = strtolower($localPart);
        $email = "{$localPart}@{$domain->domain}";

        try {
            $this->purgeDeletedMailboxRecord($email);

            $response = AgentClient::for($domain->node)->post('/mail/mailbox', [
                'email'    => $email,
                'password' => $password,
            ]);

            if (! $response->successful()) {
                Log::error("MailProvisioner: createMailbox failed for {$email}: {$response->body()}");
                return [false, $response->body()];
            }

            $mailbox = EmailAccount::create([
                'domain_id'  => $domain->id,
                'account_id' => $domain->account_id,
                'node_id'    => $domain->node_id,
                'email'      => $email,
                'local_part' => $localPart,
                'quota_mb'   => $quotaMb,
                'spam_action' => $domain->mail_spam_action ?? 'inbox',
            ]);

            if ($mailbox->spam_action !== 'inbox') {
                [$synced, $syncError] = app(MailSieveProvisioner::class)->sync($mailbox);

                if (! $synced) {
                    AgentClient::for($domain->node)->delete("/mail/mailbox/{$email}");
                    $mailbox->delete();
                    Log::error("MailProvisioner: createMailbox sieve sync failed for {$email}: {$syncError}");
                    return [false, $syncError];
                }
            }

            // Ensure SnappyMail domain profile exists
            [$snappyOk, $snappyErr] = app(SnappyMailProvisioner::class)->provisionForMailbox($mailbox);
            if (! $snappyOk) {
                Log::warning("MailProvisioner: createMailbox snappy provision warning for {$email}: {$snappyErr}");
            }

            Log::info("MailProvisioner: mailbox created {$email}");
            return [true, null];
        } catch (\Throwable $e) {
            Log::error("MailProvisioner: createMailbox exception for {$email}: {$e->getMessage()}");
            return [false, $e->getMessage()];
        }
    }

    /**
     * Verify a mailbox exists on the node by checking the virtual user file.
     */
    /**
     * Remove a soft-deleted mailbox row so the address can be recreated cleanly.
     */
    private function purgeDeletedMailboxRecord(string $email): void
    {
        EmailAccount::onlyTrashed()
            ->where('email', $email)
            ->get()
            ->each
            ->forceDelete();
    }

    /**
     * Delete a mailbox from the node and DB.
     */
    public function deleteMailbox(EmailAccount $mailbox): array
    {
        try {
            $response = AgentClient::for($mailbox->node)->delete("/mail/mailbox/{$mailbox->email}");
            if (! $response->successful()) {
                Log::error("MailProvisioner: deleteMailbox failed for {$mailbox->email}: {$response->body()}");
                return [false, $response->body()];
            }

            $mailbox->delete();
            Log::info("MailProvisioner: mailbox deleted {$mailbox->email}");
            return [true, null];
        } catch (\Throwable $e) {
            Log::error("MailProvisioner: deleteMailbox exception for {$mailbox->email}: {$e->getMessage()}");
            return [false, $e->getMessage()];
        }
    }

    /**
     * Change mailbox password on the node.
     */
    public function changePassword(EmailAccount $mailbox, string $password): array
    {
        try {
            $client = AgentClient::for($mailbox->node);

            if ($mailbox->migration_reset_required) {
                $create = $client->post('/mail/mailbox', [
                    'email' => $mailbox->email,
                    'password' => $password,
                ]);

                if (! $create->successful()) {
                    $update = $client->put("/mail/mailbox/{$mailbox->email}/password", [
                        'password' => $password,
                    ]);
                    if (! $update->successful()) {
                        Log::error("MailProvisioner: changePassword failed for {$mailbox->email}: {$create->body()} / {$update->body()}");
                        return [false, $create->body() . ' / ' . $update->body()];
                    }
                }

                [$synced, $syncError] = app(MailSieveProvisioner::class)->sync($mailbox);
                if (! $synced) {
                    return [false, $syncError];
                }

                $mailbox->update(['migration_reset_required' => false, 'active' => true]);
                Log::info("MailProvisioner: password changed (with migration reset) for {$mailbox->email}");
                return [true, null];
            }

            $response = $client->put("/mail/mailbox/{$mailbox->email}/password", [
                'password' => $password,
            ]);

            if (! $response->successful()) {
                Log::error("MailProvisioner: changePassword failed for {$mailbox->email}: {$response->body()}");
                return [false, $response->body()];
            }

            if ($mailbox->migration_reset_required) {
                $mailbox->update(['migration_reset_required' => false, 'active' => true]);
            }

            Log::info("MailProvisioner: password changed for {$mailbox->email}");
            return [true, null];
        } catch (\Throwable $e) {
            Log::error("MailProvisioner: changePassword exception for {$mailbox->email}: {$e->getMessage()}");
            return [false, $e->getMessage()];
        }
    }

    /**
     * Create a forwarder on the node and persist to DB.
     */
    public function createForwarder(Domain $domain, string $source, string $destination): array
    {
        try {
            $response = AgentClient::for($domain->node)->post('/mail/forwarder', [
                'source'      => $source,
                'destination' => $destination,
            ]);

            if (! $response->successful()) {
                Log::error("MailProvisioner: createForwarder failed for {$source}: {$response->body()}");
                return [false, $response->body()];
            }

            EmailForwarder::create([
                'domain_id'   => $domain->id,
                'account_id'  => $domain->account_id,
                'node_id'     => $domain->node_id,
                'source'      => $source,
                'destination' => $destination,
            ]);

            Log::info("MailProvisioner: forwarder created {$source} -> {$destination}");
            return [true, null];
        } catch (\Throwable $e) {
            Log::error("MailProvisioner: createForwarder exception for {$source}: {$e->getMessage()}");
            return [false, $e->getMessage()];
        }
    }

    /**
     * Delete a forwarder from node and DB.
     */
    public function deleteForwarder(EmailForwarder $forwarder): array
    {
        try {
            $node = $forwarder->node ?? $forwarder->domain?->node;
            if (! $node) {
                return [false, 'Forwarder is missing an associated node.'];
            }

            $response = AgentClient::for($node)->delete("/mail/forwarder/{$forwarder->source}");
            if (! $response->successful()) {
                Log::error("MailProvisioner: deleteForwarder failed for {$forwarder->source}: {$response->body()}");
                return [false, $response->body()];
            }

            $forwarder->delete();
            Log::info("MailProvisioner: forwarder deleted {$forwarder->source}");
            return [true, null];
        } catch (\Throwable $e) {
            Log::error("MailProvisioner: deleteForwarder exception for {$forwarder->source}: {$e->getMessage()}");
            return [false, $e->getMessage()];
        }
    }
}
