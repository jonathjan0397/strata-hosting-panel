<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;

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

            return [true, null, $data];
        } catch (\Throwable $e) {
            return [false, $e->getMessage(), []];
        }
    }

    /**
     * Create a mailbox on the node and persist to DB.
     */
    public function createMailbox(Domain $domain, string $localPart, string $password, int $quotaMb = 0): array
    {
        $email = "{$localPart}@{$domain->domain}";

        try {
            $response = AgentClient::for($domain->node)->post('/mail/mailbox', [
                'email'    => $email,
                'password' => $password,
            ]);

            if (! $response->successful()) {
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
                    return [false, $syncError];
                }
            }

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Delete a mailbox from the node and DB.
     */
    public function deleteMailbox(EmailAccount $mailbox): array
    {
        try {
            $response = AgentClient::for($mailbox->node)->delete("/mail/mailbox/{$mailbox->email}");
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $mailbox->delete();
            return [true, null];
        } catch (\Throwable $e) {
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
                $create = $client->createMailbox([
                    'email' => $mailbox->email,
                    'password' => $password,
                ]);

                if (! $create->successful()) {
                    $update = $client->changeMailboxPassword($mailbox->email, $password);
                    if (! $update->successful()) {
                        return [false, $create->body() . ' / ' . $update->body()];
                    }
                }

                [$synced, $syncError] = app(MailSieveProvisioner::class)->sync($mailbox);
                if (! $synced) {
                    return [false, $syncError];
                }

                $mailbox->update(['migration_reset_required' => false, 'active' => true]);
                return [true, null];
            }

            $response = $client->changeMailboxPassword($mailbox->email, $password);

            if (! $response->successful()) {
                return [false, $response->body()];
            }

            if ($mailbox->migration_reset_required) {
                $mailbox->update(['migration_reset_required' => false, 'active' => true]);
            }

            return [true, null];
        } catch (\Throwable $e) {
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
                return [false, $response->body()];
            }

            EmailForwarder::create([
                'domain_id'   => $domain->id,
                'account_id'  => $domain->account_id,
                'node_id'     => $domain->node_id,
                'source'      => $source,
                'destination' => $destination,
            ]);

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Delete a forwarder from node and DB.
     */
    public function deleteForwarder(EmailForwarder $forwarder): array
    {
        try {
            $response = AgentClient::for($forwarder->node)->delete("/mail/forwarder/{$forwarder->source}");
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $forwarder->delete();
            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
