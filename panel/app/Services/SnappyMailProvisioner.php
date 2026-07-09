<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SnappyMailProvisioner
{
    public function provisionDomain(Domain $domain): array
    {
        $dataPath = $this->dataPath();
        if (! $dataPath) {
            Log::warning('SnappyMailProvisioner: data directory not found, attempting to create it');
            $dataPath = $this->createDataPath();
            if (! $dataPath) {
                return [false, 'SnappyMail data directory could not be found or created.'];
            }
        }

        $domain = $domain->loadMissing('node');
        $domainsDir = $this->ensureDomainsDir($dataPath);

        $profile = $this->profileFor($domain);
        $encoded = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return [false, 'Failed to encode SnappyMail domain profile.'];
        }

        $path = $domainsDir.'/'.strtolower($domain->domain).'.json';
        File::put($path, $encoded.PHP_EOL);
        @chmod($path, 0600);
        @chown($path, 'www-data');
        @chgrp($path, 'www-data');

        Log::info("SnappyMailProvisioner: provisioned domain profile for {$domain->domain}");
        return [true, null];
    }

    /**
     * Ensure the SnappyMail domain profile exists for the given mailbox's domain.
     * Called during mailbox creation to catch missing profiles.
     */
    public function provisionForMailbox(EmailAccount $mailbox): array
    {
        $domain = $mailbox->domain;
        if (! $domain) {
            return [false, 'Mailbox has no associated domain.'];
        }

        $dataPath = $this->dataPath();
        if (! $dataPath) {
            $dataPath = $this->createDataPath();
            if (! $dataPath) {
                return [false, 'SnappyMail data directory could not be found or created.'];
            }
        }

        $domainsDir = $this->ensureDomainsDir($dataPath);
        $profilePath = $domainsDir.'/'.strtolower($domain->domain).'.json';

        if (File::exists($profilePath)) {
            return [true, null];
        }

        return $this->provisionDomain($domain);
    }

    public function provisionAll(): array
    {
        $dataPath = $this->dataPath();
        if (! $dataPath) {
            $dataPath = $this->createDataPath();
            if (! $dataPath) {
                return [false, 'SnappyMail data directory could not be found or created.', 0];
            }
        }

        $domainsDir = $this->ensureDomainsDir($dataPath);
        $provisioned = 0;
        $errors = [];

        Domain::where('mail_enabled', true)->chunk(50, function ($domains) use (&$provisioned, &$errors) {
            foreach ($domains as $domain) {
                [$ok, $err] = $this->provisionDomain($domain);
                if ($ok) {
                    $provisioned++;
                } else {
                    $errors[] = "{$domain->domain}: {$err}";
                }
            }
        });

        return [true, null, $provisioned, $errors];
    }

    public function provisionDefault(?string $host = null): array
    {
        $dataPath = $this->dataPath();
        if (! $dataPath) {
            $dataPath = $this->createDataPath();
            if (! $dataPath) {
                return [false, 'SnappyMail data directory could not be found or created.'];
            }
        }

        $domainsDir = $this->ensureDomainsDir($dataPath);

        $profile = $this->baseProfile($host ?: '127.0.0.1');
        $encoded = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return [false, 'Failed to encode SnappyMail default profile.'];
        }

        $path = $domainsDir.'/default.json';
        File::put($path, $encoded.PHP_EOL);
        @chmod($path, 0600);
        @chown($path, 'www-data');
        @chgrp($path, 'www-data');

        return [true, null];
    }

    public function repairStaleLocalProfiles(?string $host = null): array
    {
        $dataPath = $this->dataPath();
        if (! $dataPath) {
            $dataPath = $this->createDataPath();
            if (! $dataPath) {
                return [false, 'SnappyMail data directory could not be found or created.', 0];
            }
        }

        $domainsDir = $this->ensureDomainsDir($dataPath);
        $repaired = 0;

        foreach (glob($domainsDir.'/*.json') ?: [] as $path) {
            $profile = json_decode((string) file_get_contents($path), true);
            if (! is_array($profile)) {
                continue;
            }

            $imap = $profile['IMAP'] ?? [];
            $isStaleLocal = in_array($imap['host'] ?? null, ['localhost', '127.0.0.1'], true)
                && (int) ($imap['port'] ?? 0) === 143;

            if (! $isStaleLocal) {
                continue;
            }

            $profile = $this->baseProfile($host ?: '127.0.0.1');
            $encoded = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                continue;
            }

            File::put($path, $encoded.PHP_EOL);
            @chmod($path, 0600);
            @chown($path, 'www-data');
            @chgrp($path, 'www-data');
            $repaired++;
        }

        return [true, null, $repaired];
    }

    private function profileFor(Domain $domain): array
    {
        $host = $domain->node?->is_primary
            ? '127.0.0.1'
            : ($domain->node?->hostname ?: $domain->node?->ip_address ?: '127.0.0.1');

        return $this->baseProfile($host);
    }

    private function baseProfile(string $host): array
    {
        $sasl = [
            'SCRAM-SHA3-512',
            'SCRAM-SHA-512',
            'SCRAM-SHA-256',
            'SCRAM-SHA-1',
            'PLAIN',
            'LOGIN',
        ];

        $ssl = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'security_level' => 1,
        ];

        return [
            'IMAP' => [
                'host' => $host,
                'port' => 993,
                'type' => 1,
                'timeout' => 300,
                'shortLogin' => false,
                'lowerLogin' => true,
                'sasl' => $sasl,
                'ssl' => $ssl,
                'disabled_capabilities' => ['METADATA', 'OBJECTID', 'PREVIEW', 'STATUS=SIZE'],
                'use_expunge_all_on_delete' => false,
                'fast_simple_search' => true,
                'force_select' => false,
                'message_all_headers' => false,
                'message_list_limit' => 10000,
                'search_filter' => '',
                'spam_headers' => '',
                'virus_headers' => '',
            ],
            'SMTP' => [
                'host' => $host,
                'port' => 587,
                'type' => 2,
                'timeout' => 60,
                'shortLogin' => false,
                'lowerLogin' => true,
                'sasl' => $sasl,
                'ssl' => $ssl,
                'useAuth' => true,
                'setSender' => false,
                'usePhpMail' => false,
            ],
            'Sieve' => [
                'host' => '',
                'port' => 4190,
                'type' => 0,
                'timeout' => 10,
                'shortLogin' => false,
                'lowerLogin' => true,
                'sasl' => $sasl,
                'ssl' => $ssl,
                'enabled' => false,
            ],
            'whiteList' => '',
        ];
    }

    private function dataPath(): ?string
    {
        $candidates = array_filter([
            env('STRATA_WEBMAIL_DATA_PATH'),
            '/var/lib/snappymail',
            '/var/www/webmail/data',
        ]);

        foreach ($candidates as $candidate) {
            $path = rtrim((string) $candidate, '/');
            if (is_dir($path) && is_writable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function createDataPath(): ?string
    {
        $candidates = array_filter([
            env('STRATA_WEBMAIL_DATA_PATH'),
            '/var/lib/snappymail',
            '/var/www/webmail/data',
        ]);

        foreach ($candidates as $candidate) {
            $path = rtrim((string) $candidate, '/');
            if (! is_dir($path)) {
                try {
                    File::ensureDirectoryExists($path, 0755, true);
                    @chmod($path, 0755);
                    @chown($path, 'www-data');
                    @chgrp($path, 'www-data');
                } catch (\Throwable $e) {
                    Log::warning("SnappyMailProvisioner: failed to create data dir {$path}: {$e->getMessage()}");
                    continue;
                }
            }
            if (is_writable($path)) {
                Log::info("SnappyMailProvisioner: created data directory at {$path}");
                return $path;
            }
        }

        return null;
    }

    private function ensureDomainsDir(string $dataPath): string
    {
        $domainsDir = $dataPath.'/_data_/_default_/domains';
        File::ensureDirectoryExists($domainsDir, 0700, true);

        foreach ([$dataPath.'/_data_', $dataPath.'/_data_/_default_', $domainsDir] as $path) {
            @chmod($path, 0700);
            @chown($path, 'www-data');
            @chgrp($path, 'www-data');
        }

        return $domainsDir;
    }
}
