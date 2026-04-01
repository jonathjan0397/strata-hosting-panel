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

            EmailAccount::create([
                'domain_id'  => $domain->id,
                'account_id' => $domain->account_id,
                'node_id'    => $domain->node_id,
                'email'      => $email,
                'local_part' => $localPart,
                'quota_mb'   => $quotaMb,
            ]);

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
            AgentClient::for($mailbox->node)->delete("/mail/mailbox/{$mailbox->email}");
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
            $response = AgentClient::for($mailbox->node)->put("/mail/mailbox/{$mailbox->email}/password", [
                'password' => $password,
            ]);

            if (! $response->successful()) {
                return [false, $response->body()];
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
            AgentClient::for($forwarder->node)->delete("/mail/forwarder/{$forwarder->source}");
            $forwarder->delete();
            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
