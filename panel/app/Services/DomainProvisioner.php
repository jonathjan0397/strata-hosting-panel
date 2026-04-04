<?php

namespace App\Services;

use App\Models\Domain;

class DomainProvisioner
{
    /**
     * Create the Nginx vhost on the node for this domain.
     */
    public function provision(Domain $domain): array
    {
        try {
            $account = $domain->account;
            $phpVersion = $domain->php_version ?? $account->php_version;
            $phpSocket  = "/run/php/php{$phpVersion}-fpm-{$account->username}.sock";

            $response = AgentClient::for($domain->node)->createDomain([
                'web_server'    => $domain->node->web_server ?? 'nginx',
                'domain'        => $domain->domain,
                'username'      => $account->username,
                'document_root' => $domain->document_root,
                'php_version'   => $phpVersion,
                'php_socket'    => $phpSocket,
                'ssl_enabled'   => false,
            ]);

            if ($response->successful()) {
                return [true, null];
            }

            return [false, $response->json('message') ?? $response->body()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Remove the Nginx vhost (and SSL cert if present).
     */
    public function deprovision(Domain $domain): array
    {
        $errors = [];

        try {
            if ($domain->ssl_enabled) {
                AgentClient::for($domain->node)->removeSSL($domain->domain);
            }
        } catch (\Throwable $e) {
            $errors[] = "SSL removal: " . $e->getMessage();
        }

        try {
            $response = AgentClient::for($domain->node)->removeDomain($domain->domain);
            if (! $response->successful()) {
                $errors[] = $response->body();
            }
        } catch (\Throwable $e) {
            $errors[] = "Vhost removal: " . $e->getMessage();
        }

        return [empty($errors), implode('; ', $errors) ?: null];
    }

    /**
     * Change the PHP version for a domain's FPM pool.
     */
    public function changePhpVersion(Domain $domain, string $newVersion): array
    {
        try {
            $account    = $domain->account;
            $oldVersion = $domain->php_version ?? $account->php_version;

            $response = AgentClient::for($domain->node)->setPhpVersion(
                $account->username,
                $oldVersion,
                $newVersion
            );

            return $response->successful() ? [true, null] : [false, $response->body()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Issue an SSL certificate and update the vhost.
     */
    public function issueSSL(Domain $domain): array
    {
        try {
            $response = AgentClient::for($domain->node)->issueSSL($domain->domain);

            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $paths = $response->json();
            $domain->update([
                'ssl_enabled'    => true,
                'ssl_provider'   => 'letsencrypt',
                'ssl_expires_at' => now()->addDays(90),
            ]);

            // Re-provision vhost with SSL enabled
            $account    = $domain->account;
            $phpVersion = $domain->php_version ?? $account->php_version;
            $phpSocket  = "/run/php/php{$phpVersion}-fpm-{$account->username}.sock";

            AgentClient::for($domain->node)->createDomain([
                'web_server'    => $domain->node->web_server ?? 'nginx',
                'domain'        => $domain->domain,
                'username'      => $account->username,
                'document_root' => $domain->document_root,
                'php_version'   => $phpVersion,
                'php_socket'    => $phpSocket,
                'ssl_enabled'   => true,
                'ssl_cert'      => $paths['chain_file'],
                'ssl_key'       => $paths['key_file'],
            ]);

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
