<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Node;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Throwable;

class DnsProvisioner
{
    public function __construct(private readonly AgentClient $client) {}

    public function zoneForDomain(Domain $domain): ?DnsZone
    {
        return $this->resolveZoneForDomain($domain);
    }

    public function hasZoneForDomain(Domain $domain): bool
    {
        return $this->zoneForDomain($domain) !== null;
    }

    /**
     * Create a DNS zone for a domain and seed default records.
     * Returns [bool $success, ?string $error].
     */
    public function createZone(Domain $domain): array
    {
        try {
            $existingZone = $this->resolveZoneForDomain($domain);
            if ($existingZone) {
                [$recordsCreated, $recordError] = $this->addDefaultRecords($domain, $existingZone);
                if (! $recordsCreated) {
                    return [false, 'Default DNS record provisioning failed: ' . $recordError];
                }

                return [true, null];
            }

            $response = $this->client->createDnsZone($domain->domain, $this->authoritativeNameservers());
            if (! self::zoneProvisionResponseIsUsable($response)) {
                return [false, $response->body()];
            }

            $zone = DnsZone::firstOrCreate(
                ['zone_name' => $domain->domain],
                [
                    'domain_id'  => $domain->id,
                    'account_id' => $domain->account_id,
                    'node_id'    => $domain->node_id,
                ]
            );

            [$recordsCreated, $recordError] = $this->addDefaultRecords($domain, $zone);
            if (! $recordsCreated) {
                if ($zone->domain_id === $domain->id) {
                    $this->deleteZone($domain);
                }
                return [false, 'Default DNS record provisioning failed: ' . $recordError];
            }

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function addDefaultRecords(Domain $domain, DnsZone $zone): array
    {
        foreach ($this->defaultRecordDefinitions($domain) as $record) {
            [$name, $type, $contents, $priority] = [
                $record['name'],
                $record['type'],
                $record['contents'],
                $record['priority'],
            ];

            if ($contents === []) {
                continue;
            }

            [$created, $error] = $this->addRecord($zone, $name, $type, 300, $contents, true, $priority);
            if (! $created) {
                return [false, "{$type} {$name}: {$error}"];
            }
        }

        return [true, null];
    }

    public function defaultRecordDefinitions(Domain $domain): array
    {
        $domainName = $domain->domain . '.';
        $mailHost = 'mail.' . $domain->domain . '.';
        $nodeIp = $this->publicAddressFor($domain);
        $records = [
            ['name' => '@', 'type' => 'NS', 'contents' => $this->authoritativeNameservers(), 'priority' => null],
            ...array_map(
                fn (array $record) => [
                    'name' => $record[0],
                    'type' => $record[1],
                    'contents' => $record[2],
                    'priority' => $record[3],
                ],
                $this->nameserverAddressRecordsFor($domain)
            ),
            ['name' => 'www', 'type' => 'CNAME', 'contents' => [$domainName], 'priority' => null],
            ['name' => 'ftp', 'type' => 'CNAME', 'contents' => [$domainName], 'priority' => null],
            ['name' => 'smtp', 'type' => 'CNAME', 'contents' => [$mailHost], 'priority' => null],
            ['name' => 'imap', 'type' => 'CNAME', 'contents' => [$mailHost], 'priority' => null],
            ['name' => 'pop', 'type' => 'CNAME', 'contents' => [$mailHost], 'priority' => null],
            ['name' => 'webmail', 'type' => 'CNAME', 'contents' => [$mailHost], 'priority' => null],
            ['name' => '@', 'type' => 'MX', 'contents' => [$mailHost], 'priority' => 10],
            ['name' => '_dmarc', 'type' => 'TXT', 'contents' => ["v=DMARC1; p=quarantine; pct=100; rua=mailto:postmaster@{$domain->domain}"], 'priority' => null],
            ['name' => '@', 'type' => 'CAA', 'contents' => ['0 issue "letsencrypt.org"'], 'priority' => null],
        ];

        if ($nodeIp) {
            $addressType = filter_var($nodeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
            $ipMechanism = $addressType === 'AAAA' ? "ip6:{$nodeIp}" : "ip4:{$nodeIp}";

            array_unshift(
                $records,
                ['name' => '@', 'type' => $addressType, 'contents' => [$nodeIp], 'priority' => null],
                ['name' => 'mail', 'type' => $addressType, 'contents' => [$nodeIp], 'priority' => null],
                ['name' => '@', 'type' => 'TXT', 'contents' => ["v=spf1 a mx {$ipMechanism} -all"], 'priority' => null],
            );
        } else {
            array_unshift($records, ['name' => '@', 'type' => 'TXT', 'contents' => ['v=spf1 a mx -all'], 'priority' => null]);
        }

        if ($domain->mail_enabled) {
            if ($domain->dkim_dns_record) {
                $records[] = ['name' => 'default._domainkey', 'type' => 'TXT', 'contents' => [$domain->dkim_dns_record], 'priority' => null];
            }

            if ($domain->spf_dns_record) {
                $records = array_values(array_filter($records, fn (array $record) => ! ($record['name'] === '@' && $record['type'] === 'TXT')));
                $records[] = ['name' => '@', 'type' => 'TXT', 'contents' => [$domain->spf_dns_record], 'priority' => null];
            }

            if ($domain->dmarc_dns_record) {
                $records = array_values(array_filter($records, fn (array $record) => ! ($record['name'] === '_dmarc' && $record['type'] === 'TXT')));
                $records[] = ['name' => '_dmarc', 'type' => 'TXT', 'contents' => [$domain->dmarc_dns_record], 'priority' => null];
            }
        }

        return $records;
    }

    public function defaultDefinitionForRecord(Domain $domain, string $name, string $type): ?array
    {
        foreach ($this->defaultRecordDefinitions($domain) as $record) {
            if ($record['name'] === $name && $record['type'] === $type) {
                return $record;
            }
        }

        return null;
    }

    public function restoreManagedRecord(Domain $domain, DnsRecord $record): array
    {
        $zone = $this->resolveZoneForDomain($domain);
        if (! $zone) {
            return [false, 'DNS zone has not been provisioned for this domain.'];
        }

        $default = $this->defaultDefinitionForRecord($domain, $record->name, $record->type);
        if (! $default) {
            return [false, 'No managed default is defined for this record.'];
        }

        return $this->addRecord(
            $zone,
            $default['name'],
            $default['type'],
            300,
            $default['contents'],
            true,
            $default['priority']
        );
    }

    private function publicAddressFor(Domain $domain): ?string
    {
        $candidates = array_filter([
            $domain->server_ip,
            $domain->node?->ip_address,
            $this->resolveHostname($domain->node?->hostname),
        ]);

        foreach ($candidates as $candidate) {
            if ($this->isPublicIp($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function publicAddressForNode(Node $node): ?string
    {
        $candidates = array_filter([
            $node->ip_address,
            $this->resolveHostname($node->hostname),
        ]);

        foreach ($candidates as $candidate) {
            if ($this->isPublicIp($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveHostname(?string $hostname): ?string
    {
        if (! $hostname || filter_var($hostname, FILTER_VALIDATE_IP)) {
            return null;
        }

        $records = gethostbynamel($hostname);

        return $records[0] ?? null;
    }

    private function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Delete a DNS zone and all its records from the agent and DB.
     */
    public function deleteZone(Domain $domain): array
    {
        try {
            $zone = $this->resolveZoneForDomain($domain);
            if (! $zone) {
                return [true, null];
            }

            if ($zone->domain_id !== $domain->id) {
                return [true, null];
            }

            $response = $this->client->deleteDnsZone($domain->domain);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            foreach ($this->backupDnsNodesForNode($domain->node_id) as $node) {
                $backupResponse = AgentClient::for($node)->deleteDnsZone($domain->domain);
                if (! $backupResponse->successful()) {
                    return [false, "Backup DNS zone removal failed on {$node->name}: " . $backupResponse->body()];
                }
            }

            $zone->delete();

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Add or replace a DNS record in both PowerDNS and the local DB.
     */
    public function addRecord(DnsZone $zone, string $name, string $type, int $ttl, array $contents, bool $managed = false, ?int $priority = null): array
    {
        try {
            $value = implode("\n", $contents);
            $agentContents = $contents;
            if (in_array($type, ['MX', 'SRV'], true) && $priority !== null) {
                $agentContents = array_map(fn ($content) => "{$priority} {$content}", $contents);
            }

            $response = $this->client->upsertDnsRecord($zone->zone_name, $name, $type, $ttl, $agentContents);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $rectifyResponse = $this->client->rectifyDnsZone($zone->zone_name);
            if (! $rectifyResponse->successful()) {
                return [false, 'Primary DNS zone rectify failed: ' . $rectifyResponse->body()];
            }

            [$mirrored, $mirrorError] = $this->mirrorRecordToBackupNodes($zone, $name, $type, $ttl, $agentContents);
            if (! $mirrored) {
                return [false, $mirrorError];
            }

            // Upsert into DB (replace existing same name+type).
            DnsRecord::updateOrCreate(
                ['dns_zone_id' => $zone->id, 'name' => $name, 'type' => $type],
                ['ttl' => $ttl, 'value' => $value, 'priority' => $priority ?? 0, 'managed' => $managed]
            );

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Delete a DNS record from both PowerDNS and the local DB.
     */
    public function deleteRecord(DnsZone $zone, string $name, string $type): array
    {
        try {
            $response = $this->client->deleteDnsRecord($zone->zone_name, $name, $type);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $rectifyResponse = $this->client->rectifyDnsZone($zone->zone_name);
            if (! $rectifyResponse->successful()) {
                return [false, 'Primary DNS zone rectify failed: ' . $rectifyResponse->body()];
            }

            [$mirrored, $mirrorError] = $this->deleteRecordFromBackupNodes($zone, $name, $type);
            if (! $mirrored) {
                return [false, $mirrorError];
            }

            DnsRecord::where('dns_zone_id', $zone->id)
                ->where('name', $name)
                ->where('type', $type)
                ->delete();

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Auto-populate mail DNS records (DKIM/SPF/DMARC/MX) after mail is enabled.
     */
    public function addMailRecords(Domain $domain): void
    {
        $zone = $this->resolveZoneForDomain($domain);
        if (! $zone) {
            return;
        }

        $serverIp = $this->publicAddressFor($domain);
        if ($serverIp) {
            $addressType = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
            $this->addRecord($zone, 'mail', $addressType, 300, [$serverIp], true);
        }

        if ($domain->dkim_dns_record) {
            $this->updateDkimRecord($domain, $domain->dkim_dns_record);
        }
        if ($domain->spf_dns_record) {
            $this->updateSpfRecord($domain, $domain->spf_dns_record);
        }
        if ($domain->dmarc_dns_record) {
            $this->addRecord($zone, '_dmarc', 'TXT', 300, [$domain->dmarc_dns_record], true);
        }
        $this->addRecord($zone, '@', 'MX', 300, ['mail.' . $domain->domain . '.'], true, 10);
        $this->addRecord($zone, 'smtp', 'CNAME', 300, ['mail.' . $domain->domain . '.'], true);
        $this->addRecord($zone, 'imap', 'CNAME', 300, ['mail.' . $domain->domain . '.'], true);
        $this->addRecord($zone, 'pop', 'CNAME', 300, ['mail.' . $domain->domain . '.'], true);
        $this->addRecord($zone, 'webmail', 'CNAME', 300, ['mail.' . $domain->domain . '.'], true);
    }

    /**
     * Replace only the SPF value in the root TXT RRSet so other TXT records are preserved.
     */
    public function updateSpfRecord(Domain $domain, string $spfRecord): array
    {
        $zone = $this->resolveZoneForDomain($domain);
        if (! $zone) {
            return [false, 'DNS zone has not been provisioned for this domain.'];
        }

        $existing = DnsRecord::where('dns_zone_id', $zone->id)
            ->where('name', '@')
            ->where('type', 'TXT')
            ->first();

        $contents = $existing
            ? preg_split('/\R/', (string) $existing->value, -1, PREG_SPLIT_NO_EMPTY)
            : [];

        $contents = array_values(array_filter(
            array_map('trim', $contents),
            fn (string $value) => $value !== '' && ! str_starts_with(strtolower($value), 'v=spf1')
        ));

        $contents[] = $spfRecord;

        return $this->addRecord($zone, '@', 'TXT', 300, $contents, true);
    }

    /**
     * Replace the managed DKIM selector RRset for a domain.
     */
    public function updateDkimRecord(Domain $domain, string $dkimRecord): array
    {
        $zone = $this->resolveZoneForDomain($domain);
        if (! $zone) {
            return [false, 'DNS zone has not been provisioned for this domain.'];
        }

        return $this->addRecord($zone, 'default._domainkey', 'TXT', 300, [$dkimRecord], true);
    }

    public function authoritativeNameservers(): array
    {
        $primary = Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->first();
        $baseDomain = $this->baseDomainFor($primary?->hostname);

        if (! $baseDomain) {
            return [];
        }

        return $this->dnsCapableNodes()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn (Node $node, int $index) => 'ns' . ($index + 1) . '.' . $baseDomain . '.')
            ->unique()
            ->values()
            ->all();
    }

    private function nameserverAddressRecordsFor(Domain $domain): array
    {
        $primary = Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->first();
        $baseDomain = $this->baseDomainFor($primary?->hostname);

        if (! $baseDomain || rtrim($domain->domain, '.') !== $baseDomain) {
            return [];
        }

        return $this->dnsCapableNodes()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(function (Node $node, int $index) {
                $address = $this->publicAddressForNode($node);
                if (! $address) {
                    return null;
                }

                return [
                    'ns' . ($index + 1),
                    filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A',
                    [$address],
                    null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function dnsCapableNodes()
    {
        $online = Node::whereNull('deleted_at')
            ->where('hosts_dns', true)
            ->where('status', 'online');

        if ($online->exists()) {
            return $online;
        }

        $configured = Node::whereNull('deleted_at')
            ->where('hosts_dns', true);

        if ($configured->exists()) {
            return $configured;
        }

        return Node::whereNull('deleted_at')
            ->where('is_primary', true);
    }

    private function baseDomainFor(?string $hostname): ?string
    {
        $hostname = strtolower(trim((string) $hostname, '. '));
        if ($hostname === '' || filter_var($hostname, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = array_values(array_filter(explode('.', $hostname)));
        if (count($parts) < 2) {
            return null;
        }

        return implode('.', array_slice($parts, -2));
    }

    private function mirrorRecordToBackupNodes(DnsZone $zone, string $name, string $type, int $ttl, array $contents): array
    {
        foreach ($this->backupDnsNodes($zone) as $node) {
            $client = AgentClient::for($node);

            $zoneResponse = $client->createDnsZone($zone->zone_name, $this->authoritativeNameservers());
            if (! $this->zoneProvisionResponseIsUsable($zoneResponse)) {
                return [false, "Backup DNS zone sync failed on {$node->name}: " . $zoneResponse->body()];
            }

            $recordResponse = $client->upsertDnsRecord($zone->zone_name, $name, $type, $ttl, $contents);
            if (! $recordResponse->successful()) {
                return [false, "Backup DNS record sync failed on {$node->name}: " . $recordResponse->body()];
            }

            $rectifyResponse = $client->rectifyDnsZone($zone->zone_name);
            if (! $rectifyResponse->successful()) {
                return [false, "Backup DNS zone rectify failed on {$node->name}: " . $rectifyResponse->body()];
            }
        }

        return [true, null];
    }

    private function deleteRecordFromBackupNodes(DnsZone $zone, string $name, string $type): array
    {
        foreach ($this->backupDnsNodes($zone) as $node) {
            $client = AgentClient::for($node);

            $response = $client->deleteDnsRecord($zone->zone_name, $name, $type);
            if (! $response->successful()) {
                return [false, "Backup DNS record removal failed on {$node->name}: " . $response->body()];
            }

            $rectifyResponse = $client->rectifyDnsZone($zone->zone_name);
            if (! $rectifyResponse->successful()) {
                return [false, "Backup DNS zone rectify failed on {$node->name}: " . $rectifyResponse->body()];
            }
        }

        return [true, null];
    }

    private function backupDnsNodes(DnsZone $zone): Collection
    {
        return $this->backupDnsNodesForNode($zone->node_id);
    }

    private function backupDnsNodesForNode(?int $nodeId): Collection
    {
        return Node::whereNull('deleted_at')
            ->where('hosts_dns', true)
            ->when($nodeId, fn ($query) => $query->where('id', '!=', $nodeId))
            ->where('status', 'online')
            ->orderBy('id')
            ->get();
    }

    public function syncZoneToNode(DnsZone $zone, Node $node, bool $rebuild = false): array
    {
        try {
            $client = AgentClient::for($node);

            if ($rebuild) {
                $deleteResponse = $client->deleteDnsZone($zone->zone_name);
                if (! $deleteResponse->successful() && $deleteResponse->status() !== 404) {
                    return [false, "Backup DNS zone reset failed on {$node->name}: " . $deleteResponse->body()];
                }
            }

            $zoneResponse = $client->createDnsZone($zone->zone_name, $this->authoritativeNameservers());
            if (! self::zoneProvisionResponseIsUsable($zoneResponse)) {
                return [false, "Backup DNS zone sync failed on {$node->name}: " . $zoneResponse->body()];
            }

            foreach ($zone->records as $record) {
                $contents = preg_split('/\R/', (string) $record->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($contents === []) {
                    continue;
                }

                if (in_array($record->type, ['MX', 'SRV'], true) && $record->priority !== null) {
                    $contents = array_map(fn ($content) => "{$record->priority} {$content}", $contents);
                }

                $recordResponse = $client->upsertDnsRecord(
                    $zone->zone_name,
                    $record->name,
                    $record->type,
                    $record->ttl,
                    $contents,
                );

                if (! $recordResponse->successful()) {
                    return [false, "Backup DNS record sync failed on {$node->name}: {$record->type} {$record->name}: " . $recordResponse->body()];
                }
            }

            $rectifyResponse = $client->rectifyDnsZone($zone->zone_name);
            if (! $rectifyResponse->successful()) {
                return [false, "DNS zone rectify failed on {$node->name}: " . $rectifyResponse->body()];
            }

            if ($rebuild) {
                $restartResponse = $client->restartService('pdns');
                if (! $restartResponse->successful()) {
                    return [false, "Backup DNS service restart failed on {$node->name}: " . $restartResponse->body()];
                }
            }

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    public function ensureBackupZoneHealthy(DnsZone $zone, Node $node): array
    {
        return $this->ensureNodeZoneHealthy($zone, $node, 'backup');
    }

    public function ensureAuthoritativeZoneHealthy(DnsZone $zone, Node $node): array
    {
        return $this->ensureNodeZoneHealthy($zone, $node, 'authoritative');
    }

    public function backfillStandaloneHostZoneRecords(DnsZone $zone): bool
    {
        if (! $this->isStandaloneHostZone($zone)) {
            return false;
        }

        foreach ($this->standaloneHostZoneRecordDefinitions($zone->zone_name) as $record) {
            DnsRecord::updateOrCreate(
                [
                    'dns_zone_id' => $zone->id,
                    'name' => $record['name'],
                    'type' => $record['type'],
                ],
                [
                    'ttl' => $record['ttl'],
                    'value' => implode("\n", $record['contents']),
                    'priority' => $record['priority'] ?? 0,
                    'managed' => true,
                ],
            );
        }

        $zone->unsetRelation('records');
        $zone->load('records');

        return true;
    }

    private function ensureNodeZoneHealthy(DnsZone $zone, Node $node, string $role): array
    {
        $initialCheck = $this->verifyZoneOnNode($zone, $node);
        if ($initialCheck['healthy']) {
            return ['success' => true, 'healed' => false, 'details' => null];
        }

        [$synced, $syncError] = $this->syncZoneToNode($zone, $node);
        if (! $synced) {
            return ['success' => false, 'healed' => false, 'details' => $syncError];
        }

        $postSyncCheck = $this->verifyZoneOnNode($zone, $node);
        if ($postSyncCheck['healthy']) {
            return ['success' => true, 'healed' => true, 'details' => "resolved by resynchronizing the {$role} zone"];
        }

        [$rebuilt, $rebuildError] = $this->syncZoneToNode($zone, $node, true);
        if (! $rebuilt) {
            return ['success' => false, 'healed' => false, 'details' => $rebuildError];
        }

        $postRebuildCheck = $this->verifyZoneOnNode($zone, $node);
        if ($postRebuildCheck['healthy']) {
            return ['success' => true, 'healed' => true, 'details' => "resolved by rebuilding the {$role} zone and restarting PowerDNS"];
        }

        return [
            'success' => false,
            'healed' => false,
            'details' => "{$role} DNS zone is still drifted after rebuild: " . implode('; ', $postRebuildCheck['differences']),
        ];
    }

    public function verifyZoneOnNode(DnsZone $zone, Node $node): array
    {
        try {
            $response = AgentClient::for($node)->getDnsZone($zone->zone_name);
            if (! $response->successful()) {
                return [
                    'healthy' => false,
                    'differences' => ["zone fetch failed on {$node->name}: " . $response->body()],
                ];
            }

            $expected = $this->expectedRecordMap($zone);
            $live = $this->liveRecordMap($zone->zone_name, $response->json('rrsets', []));
            $differences = [];

            foreach ($expected as $key => $record) {
                if (! array_key_exists($key, $live)) {
                    $differences[] = "missing {$key}";
                    continue;
                }

                if ($record['ttl'] !== $live[$key]['ttl']) {
                    $differences[] = "ttl mismatch for {$key}: expected {$record['ttl']}, got {$live[$key]['ttl']}";
                }

                if ($record['contents'] !== $live[$key]['contents']) {
                    $differences[] = "content mismatch for {$key}";
                }
            }

            foreach (array_diff(array_keys($live), array_keys($expected)) as $key) {
                $differences[] = "unexpected {$key}";
            }

            return [
                'healthy' => $differences === [],
                'differences' => $differences,
            ];
        } catch (Throwable $e) {
            return [
                'healthy' => false,
                'differences' => [$e->getMessage()],
            ];
        }
    }

    public static function zoneProvisionResponseIsUsable(Response $response): bool
    {
        if ($response->successful()) {
            return true;
        }

        $body = strtolower(trim($response->body()));

        return str_contains($body, 'status 409')
            || str_contains($body, 'already exists')
            || str_contains($body, 'conflict');
    }

    private function expectedRecordMap(DnsZone $zone): array
    {
        $map = [];

        $records = $zone->records;
        if ($records->isEmpty() && $this->isStandaloneHostZone($zone)) {
            foreach ($this->standaloneHostZoneRecordDefinitions($zone->zone_name) as $record) {
                $fqdn = $this->absoluteRecordName($zone->zone_name, $record['name']);
                $key = $this->recordKey($fqdn, $record['type']);

                $contents = $record['contents'];
                if (in_array($record['type'], ['MX', 'SRV'], true) && $record['priority'] !== null) {
                    $contents = array_map(fn ($content) => "{$record['priority']} {$content}", $contents);
                }

                $map[$key] = [
                    'ttl' => (int) $record['ttl'],
                    'contents' => $this->normalizeContents($record['type'], $contents),
                ];
            }

            ksort($map);

            return $map;
        }

        foreach ($records as $record) {
            $contents = preg_split('/\R/', (string) $record->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($contents === []) {
                continue;
            }

            if (in_array($record->type, ['MX', 'SRV'], true) && $record->priority !== null) {
                $contents = array_map(fn ($content) => "{$record->priority} {$content}", $contents);
            }

            $fqdn = $this->absoluteRecordName($zone->zone_name, $record->name);
            $key = $this->recordKey($fqdn, $record->type);

            $map[$key] = [
                'ttl' => (int) $record->ttl,
                'contents' => $this->normalizeContents($record->type, $contents),
            ];
        }

        ksort($map);

        return $map;
    }

    private function liveRecordMap(string $zoneName, array $rrsets): array
    {
        $map = [];

        foreach ($rrsets as $rrset) {
            $type = strtoupper((string) ($rrset['type'] ?? ''));
            if ($type === '' || $type === 'SOA') {
                continue;
            }

            $name = $this->absoluteRecordName($zoneName, (string) ($rrset['name'] ?? ''));
            $records = collect($rrset['records'] ?? [])
                ->filter(fn ($record) => ! ($record['disabled'] ?? false))
                ->pluck('content')
                ->filter(fn ($content) => $content !== null && trim((string) $content) !== '')
                ->map(fn ($content) => (string) $content)
                ->values()
                ->all();

            if ($records === []) {
                continue;
            }

            $map[$this->recordKey($name, $type)] = [
                'ttl' => (int) ($rrset['ttl'] ?? 0),
                'contents' => $this->normalizeContents($type, $records),
            ];
        }

        ksort($map);

        return $map;
    }

    private function absoluteRecordName(string $zoneName, string $name): string
    {
        $zoneName = strtolower(rtrim($zoneName, '.')) . '.';
        $name = trim($name);

        if ($name === '' || $name === '@') {
            return $zoneName;
        }

        if (Str::endsWith($name, '.')) {
            return strtolower($name);
        }

        return strtolower($name . '.' . $zoneName);
    }

    private function recordKey(string $name, string $type): string
    {
        return strtolower(rtrim($name, '.')) . '|' . strtoupper($type);
    }

    private function normalizeContents(string $type, array $contents): array
    {
        $normalized = array_map(function (string $content) use ($type) {
            $content = trim($content);

            if ($type === 'TXT') {
                return trim($content, "\"'");
            }

            if (in_array($type, ['MX', 'SRV'], true)) {
                $parts = preg_split('/\s+/', $content) ?: [];
                $priority = array_shift($parts) ?: '0';
                $rest = strtolower(rtrim(implode(' ', $parts), '.'));

                return trim($priority . ' ' . $rest);
            }

            if (in_array($type, ['CNAME', 'NS', 'PTR'], true)) {
                return strtolower(rtrim($content, '.'));
            }

            return strtolower($content);
        }, $contents);

        sort($normalized);

        return array_values($normalized);
    }

    private function isStandaloneHostZone(DnsZone $zone): bool
    {
        if ($zone->domain_id !== null) {
            return false;
        }

        $primary = Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->first();
        $panelHost = parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: $primary?->hostname;
        $baseDomain = $this->baseDomainFor($panelHost ?: $primary?->hostname);

        return $baseDomain !== null
            && rtrim(strtolower($zone->zone_name), '.') === rtrim(strtolower($baseDomain), '.');
    }

    private function standaloneHostZoneRecordDefinitions(string $zoneName): array
    {
        $zoneName = strtolower(rtrim($zoneName, '.'));
        $primary = Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->first();
        $panelHost = strtolower(rtrim((string) (parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: $primary?->hostname), '.'));
        $serverHostname = strtolower(rtrim((string) ($primary?->hostname ?? ''), '.'));
        $primaryIp = $primary ? $this->publicAddressForNode($primary) : null;
        $records = [];

        if ($primaryIp) {
            $addressType = filter_var($primaryIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
            $ipMechanism = $addressType === 'AAAA' ? "ip6:{$primaryIp}" : "ip4:{$primaryIp}";

            $records[] = ['name' => '@', 'type' => $addressType, 'ttl' => 300, 'contents' => [$primaryIp], 'priority' => null];
            $records[] = ['name' => 'mail', 'type' => $addressType, 'ttl' => 300, 'contents' => [$primaryIp], 'priority' => null];
            $records[] = ['name' => '@', 'type' => 'TXT', 'ttl' => 300, 'contents' => ["v=spf1 a mx {$ipMechanism} -all"], 'priority' => null];
        } else {
            $records[] = ['name' => '@', 'type' => 'TXT', 'ttl' => 300, 'contents' => ['v=spf1 a mx -all'], 'priority' => null];
        }

        $mailHost = 'mail.' . $zoneName . '.';
        $records[] = ['name' => '@', 'type' => 'NS', 'ttl' => 3600, 'contents' => $this->authoritativeNameservers(), 'priority' => null];
        $records[] = ['name' => '@', 'type' => 'MX', 'ttl' => 300, 'contents' => [$mailHost], 'priority' => 10];
        $records[] = ['name' => '_dmarc', 'type' => 'TXT', 'ttl' => 300, 'contents' => ["v=DMARC1; p=quarantine; pct=100; rua=mailto:postmaster@{$zoneName}"], 'priority' => null];
        $records[] = ['name' => '@', 'type' => 'CAA', 'ttl' => 300, 'contents' => ['0 issue "letsencrypt.org"'], 'priority' => null];

        foreach (['smtp', 'imap', 'pop', 'webmail'] as $alias) {
            $records[] = ['name' => $alias, 'type' => 'CNAME', 'ttl' => 300, 'contents' => [$mailHost], 'priority' => null];
        }

        $dnsNodes = $this->dnsCapableNodes()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($dnsNodes as $index => $node) {
            $nodeIp = $this->publicAddressForNode($node);
            if (! $nodeIp) {
                continue;
            }

            $addressType = filter_var($nodeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
            $records[] = ['name' => 'ns' . ($index + 1), 'type' => $addressType, 'ttl' => 300, 'contents' => [$nodeIp], 'priority' => null];

            $nodeHostname = strtolower(rtrim((string) $node->hostname, '.'));
            $relativeHostname = $this->relativeNameForZone($nodeHostname, $zoneName);
            if ($relativeHostname && $relativeHostname !== '@') {
                $records[] = ['name' => $relativeHostname, 'type' => $addressType, 'ttl' => 300, 'contents' => [$nodeIp], 'priority' => null];
            }
        }

        $panelRelative = $this->relativeNameForZone($panelHost, $zoneName);
        if ($panelRelative && $panelRelative !== '@' && $primaryIp) {
            $records[] = ['name' => $panelRelative, 'type' => filter_var($primaryIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A', 'ttl' => 300, 'contents' => [$primaryIp], 'priority' => null];
        }

        $hostnameRelative = $this->relativeNameForZone($serverHostname, $zoneName);
        if ($hostnameRelative && $hostnameRelative !== '@' && $hostnameRelative !== $panelRelative && $primaryIp) {
            $records[] = ['name' => $hostnameRelative, 'type' => filter_var($primaryIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A', 'ttl' => 300, 'contents' => [$primaryIp], 'priority' => null];
        }

        $deduped = [];
        foreach ($records as $record) {
            if ($record['contents'] === []) {
                continue;
            }

            $key = implode('|', [
                strtolower($record['name']),
                strtoupper($record['type']),
                implode("\n", $record['contents']),
                (string) ($record['priority'] ?? ''),
            ]);
            $deduped[$key] = $record;
        }

        return array_values($deduped);
    }

    private function resolveZoneForDomain(Domain $domain): ?DnsZone
    {
        $domainName = rtrim(strtolower($domain->domain), '.');

        $zone = DnsZone::with('records')
            ->where('domain_id', $domain->id)
            ->first();
        if ($zone) {
            return $zone;
        }

        $sharedZone = DnsZone::with('records')
            ->where('zone_name', $domainName)
            ->where(function ($query) use ($domain) {
                $query->whereNull('domain_id')
                    ->orWhere('account_id', $domain->account_id);
            })
            ->orderByRaw('domain_id is null desc')
            ->first();

        return $sharedZone;
    }

    private function relativeNameForZone(?string $fqdn, string $zoneName): ?string
    {
        $fqdn = strtolower(rtrim((string) $fqdn, '.'));
        $zoneName = strtolower(rtrim($zoneName, '.'));

        if ($fqdn === '' || $zoneName === '') {
            return null;
        }

        if ($fqdn === $zoneName) {
            return '@';
        }

        $suffix = '.' . $zoneName;
        if (! str_ends_with($fqdn, $suffix)) {
            return null;
        }

        return substr($fqdn, 0, -strlen($suffix));
    }
}
