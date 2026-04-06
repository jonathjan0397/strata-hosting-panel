<?php

namespace App\Services;

use App\Models\Domain;

class DomainProvisioner
{
    private const PRIVACY_DIR = '/.strata/directory-privacy';

    /**
     * Create the vhost on the node for this domain.
     */
    public function provision(Domain $domain): array
    {
        try {
            [$filesSynced, $fileError] = $this->syncDirectoryPrivacy($domain);
            if (! $filesSynced) {
                return [false, $fileError];
            }

            $response = AgentClient::for($domain->node)->createDomain(
                $this->buildPayload($domain, ['ssl_enabled' => false])
            );

            return $response->successful() ? [true, null]
                : [false, $response->json('message') ?? $response->body()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Re-provision vhost with current domain config (custom directives, redirects, etc.).
     */
    public function reprovision(Domain $domain): array
    {
        try {
            [$filesSynced, $fileError] = $this->syncDirectoryPrivacy($domain);
            if (! $filesSynced) {
                return [false, $fileError];
            }

            $payload = $this->buildPayload($domain, [
                'ssl_enabled' => $domain->ssl_enabled,
                'ssl_cert'    => $domain->ssl_enabled ? "/etc/strata-panel/certs/{$domain->domain}/cert.pem" : null,
                'ssl_key'     => $domain->ssl_enabled ? "/etc/strata-panel/certs/{$domain->domain}/key.pem" : null,
            ]);

            $response = AgentClient::for($domain->node)->createDomain(array_filter($payload, fn($v) => $v !== null));

            return $response->successful() ? [true, null]
                : [false, $response->json('message') ?? $response->body()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Remove the vhost (and SSL cert if present).
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

        try {
            [$filesRemoved, $fileError] = $this->syncDirectoryPrivacy($domain->forceFill(['directory_privacy' => []]));
            if (! $filesRemoved && $fileError) {
                $errors[] = $fileError;
            }
        } catch (\Throwable $e) {
            $errors[] = "Directory privacy cleanup: " . $e->getMessage();
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
            $vhostResponse = AgentClient::for($domain->node)->createDomain(
                $this->buildPayload($domain, [
                    'ssl_enabled' => true,
                    'ssl_cert'    => $paths['chain_file'],
                    'ssl_key'     => $paths['key_file'],
                ])
            );

            if (! $vhostResponse->successful()) {
                return [false, $vhostResponse->json('message') ?? $vhostResponse->body()];
            }

            $domain->update([
                'ssl_enabled'    => true,
                'ssl_provider'   => 'letsencrypt',
                'ssl_expires_at' => now()->addDays(90),
            ]);

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Store a custom SSL certificate and re-provision the vhost.
     */
    public function storeCustomSSL(Domain $domain, string $certPem, string $keyPem): array
    {
        try {
            $response = AgentClient::for($domain->node)->sslStore($domain->domain, $certPem, $keyPem);

            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $result = $response->json();
            $expires = isset($result['expires']) && $result['expires']
                ? \Carbon\Carbon::parse($result['expires'])
                : now()->addYear();

            $vhostResponse = AgentClient::for($domain->node)->createDomain(
                $this->buildPayload($domain, [
                    'ssl_enabled' => true,
                    'ssl_cert'    => $result['cert_file'],
                    'ssl_key'     => $result['key_file'],
                ])
            );

            if (! $vhostResponse->successful()) {
                return [false, $vhostResponse->json('message') ?? $vhostResponse->body()];
            }

            $domain->update([
                'ssl_enabled'    => true,
                'ssl_provider'   => 'custom',
                'ssl_expires_at' => $expires,
            ]);

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildPayload(Domain $domain, array $override = []): array
    {
        $account    = $domain->account;
        $phpVersion = $domain->php_version ?? $account->php_version;
        $phpSocket  = "/run/php/php{$phpVersion}-fpm-{$account->username}.sock";

        $base = [
            'web_server'       => $domain->node->web_server ?? 'nginx',
            'domain'           => $domain->domain,
            'username'         => $account->username,
            'document_root'    => $domain->document_root,
            'php_version'      => $phpVersion,
            'php_socket'       => $phpSocket,
            'custom_directives'=> $this->buildDirectives($domain),
        ];

        return array_merge($base, $override);
    }

    /**
     * Build the custom_directives string: merge user raw directives with redirect rules.
     */
    private function buildDirectives(Domain $domain): string
    {
        $parts   = [];
        $webServer = $domain->node->web_server ?? 'nginx';

        if ($domain->custom_directives) {
            $parts[] = trim($domain->custom_directives);
        }

        foreach ($domain->redirects ?? [] as $redirect) {
            $src  = $redirect['source'] ?? '';
            $dest = $redirect['destination'] ?? '';
            $code = (int) ($redirect['type'] ?? 301);

            if (! $src || ! $dest) {
                continue;
            }

            if ($webServer === 'apache') {
                $parts[] = "Redirect {$code} {$src} {$dest}";
            } else {
                // Nginx: exact match for plain paths, prefix otherwise
                $modifier = str_ends_with($src, '/') ? '' : '= ';
                $parts[] = "location {$modifier}{$src} {\n    return {$code} {$dest};\n}";
            }
        }

        foreach ($domain->directory_privacy ?? [] as $index => $rule) {
            $directive = $this->buildDirectoryPrivacyDirective($domain, $rule, $index, $webServer);
            if ($directive) {
                $parts[] = $directive;
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    private function buildDirectoryPrivacyDirective(Domain $domain, array $rule, int $index, string $webServer): ?string
    {
        $path = $this->normalizeProtectedPath($rule['path'] ?? null);
        $filePath = $this->privacyFilePath($domain, $path, $index);

        if (! $path) {
            return null;
        }

        if ($webServer === 'apache') {
            $directoryPath = $this->directoryPath($domain, $path);

            return implode("\n", [
                '<Directory "' . $directoryPath . '">',
                '    AuthType Basic',
                '    AuthName "Restricted Area"',
                '    AuthUserFile ' . $filePath,
                '    Require valid-user',
                '</Directory>',
            ]);
        }

        return implode("\n", [
            'location = ' . $path . ' {',
            '    auth_basic "Restricted Area";',
            '    auth_basic_user_file ' . $filePath . ';',
            '}',
            'location ^~ ' . rtrim($path, '/') . '/ {',
            '    auth_basic "Restricted Area";',
            '    auth_basic_user_file ' . $filePath . ';',
            '}',
        ]);
    }

    private function syncDirectoryPrivacy(Domain $domain): array
    {
        $client = AgentClient::for($domain->node);
        $username = $domain->account->username;

        $mkdir = $client->fileMkdir($username, self::PRIVACY_DIR);
        if (! $mkdir->successful()) {
            return [false, 'Directory privacy storage setup failed: ' . $mkdir->body()];
        }

        $desiredFiles = [];
        foreach (($domain->directory_privacy ?? []) as $index => $rule) {
            $path = $this->normalizeProtectedPath($rule['path'] ?? null);
            $login = trim((string) ($rule['username'] ?? ''));
            $hash = trim((string) ($rule['password_hash'] ?? ''));

            if (! $path || $login === '' || $hash === '') {
                continue;
            }

            $relativePath = $this->privacyRelativePath($domain, $path, $index);
            $desiredFiles[$relativePath] = $login . ':' . $hash . "\n";
        }

        $existing = $client->fileList($username, self::PRIVACY_DIR);
        if ($existing->successful()) {
            foreach ($existing->json('entries') ?? [] as $entry) {
                $entryPath = $entry['path'] ?? null;
                $entryName = $entry['name'] ?? '';

                if (! is_string($entryPath) || ! str_starts_with($entryName, $this->privacyFilePrefix($domain))) {
                    continue;
                }

                if (! array_key_exists($entryPath, $desiredFiles)) {
                    $delete = $client->fileDelete($username, $entryPath);
                    if (! $delete->successful()) {
                        return [false, 'Failed to remove obsolete directory privacy file: ' . $delete->body()];
                    }
                }
            }
        }

        foreach ($desiredFiles as $relativePath => $content) {
            $write = $client->fileWrite($username, $relativePath, $content);
            if (! $write->successful()) {
                return [false, 'Failed to write directory privacy credentials: ' . $write->body()];
            }
        }

        return [true, null];
    }

    private function privacyRelativePath(Domain $domain, string $path, int $index): string
    {
        return self::PRIVACY_DIR . '/' . $this->privacyFileName($domain, $path, $index);
    }

    private function privacyFilePath(Domain $domain, string $path, int $index): string
    {
        return '/var/www/' . $domain->account->username . $this->privacyRelativePath($domain, $path, $index);
    }

    private function privacyFileName(Domain $domain, string $path, int $index): string
    {
        return $this->privacyFilePrefix($domain) . '-' . $index . '-' . substr(sha1($path), 0, 12) . '.htpasswd';
    }

    private function privacyFilePrefix(Domain $domain): string
    {
        return 'privacy-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($domain->domain));
    }

    private function directoryPath(Domain $domain, string $path): string
    {
        $documentRoot = rtrim($domain->document_root, '/');

        return $path === '/'
            ? $documentRoot
            : $documentRoot . $path;
    }

    private function normalizeProtectedPath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '' || $path === '/' || str_contains($path, '..') || ! str_starts_with($path, '/')) {
            return null;
        }

        if (! preg_match('#^/[A-Za-z0-9._/\-]+$#', $path)) {
            return null;
        }

        return rtrim($path, '/') ?: '/';
    }
}
