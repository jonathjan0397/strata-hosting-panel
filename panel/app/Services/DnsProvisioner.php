<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Node;
use Illuminate\Support\Collection;
use Throwable;

class DnsProvisioner
{
    public function __construct(private readonly AgentClient $client) {}

    /**
     * Create a DNS zone for a domain and seed default records.
     * Returns [bool $success, ?string $error].
     */
    public function createZone(Domain $domain): array
    {
        try {
            $response = $this->client->createDnsZone($domain->domain);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $zone = DnsZone::create([
                'domain_id'  => $domain->id,
                'account_id' => $domain->account_id,
                'node_id'    => $domain->node_id,
                'zone_name'  => $domain->domain,
            ]);

            [$recordsCreated, $recordError] = $this->addDefaultRecords($domain, $zone);
            if (! $recordsCreated) {
                $this->deleteZone($domain);
                return [false, 'Default DNS record provisioning failed: ' . $recordError];
            }

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function addDefaultRecords(Domain $domain, DnsZone $zone): array
    {
        $domainName = $domain->domain . '.';
        $mailHost = 'mail.' . $domain->domain . '.';
        $nodeIp = $this->publicAddressFor($domain);
        $records = [
            ['@', 'NS', $this->nameserverHosts(), null],
            ...$this->nameserverAddressRecordsFor($domain),
            ['www', 'CNAME', [$domainName], null],
            ['ftp', 'CNAME', [$domainName], null],
            ['smtp', 'CNAME', [$mailHost], null],
            ['imap', 'CNAME', [$mailHost], null],
            ['pop', 'CNAME', [$mailHost], null],
            ['webmail', 'CNAME', [$mailHost], null],
            ['@', 'MX', [$mailHost], 10],
            ['_dmarc', 'TXT', ["v=DMARC1; p=none; rua=mailto:postmaster@{$domain->domain}"], null],
            ['@', 'CAA', ['0 issue "letsencrypt.org"'], null],
        ];

        if ($nodeIp) {
            $addressType = filter_var($nodeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
            $ipMechanism = $addressType === 'AAAA' ? "ip6:{$nodeIp}" : "ip4:{$nodeIp}";

            array_unshift(
                $records,
                ['@', $addressType, [$nodeIp], null],
                ['mail', $addressType, [$nodeIp], null],
                ['@', 'TXT', ["v=spf1 a mx {$ipMechanism} -all"], null],
            );
        } else {
            array_unshift($records, ['@', 'TXT', ['v=spf1 a mx -all'], null]);
        }

        foreach ($records as [$name, $type, $contents, $priority]) {
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

            DnsZone::where('domain_id', $domain->id)->delete();

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
        $zone = DnsZone::where('domain_id', $domain->id)->first();
        if (! $zone) {
            return;
        }

        $serverIp = $this->publicAddressFor($domain);
        if ($serverIp) {
            $addressType = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
            $this->addRecord($zone, 'mail', $addressType, 300, [$serverIp], true);
        }

        if ($domain->dkim_dns_record) {
            $this->addRecord($zone, 'default._domainkey', 'TXT', 300, [$domain->dkim_dns_record], true);
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
        $zone = DnsZone::where('domain_id', $domain->id)->first();
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

    private function nameserverHosts(): array
    {
        $primary = Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->first();
        $baseDomain = $this->baseDomainFor($primary?->hostname);

        if (! $baseDomain) {
            return [];
        }

        return Node::whereNull('deleted_at')
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

        return Node::whereNull('deleted_at')
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

            $zoneResponse = $client->createDnsZone($zone->zone_name);
            if (! $zoneResponse->successful()) {
                return [false, "Backup DNS zone sync failed on {$node->name}: " . $zoneResponse->body()];
            }

            $recordResponse = $client->upsertDnsRecord($zone->zone_name, $name, $type, $ttl, $contents);
            if (! $recordResponse->successful()) {
                return [false, "Backup DNS record sync failed on {$node->name}: " . $recordResponse->body()];
            }
        }

        return [true, null];
    }

    private function deleteRecordFromBackupNodes(DnsZone $zone, string $name, string $type): array
    {
        foreach ($this->backupDnsNodes($zone) as $node) {
            $response = AgentClient::for($node)->deleteDnsRecord($zone->zone_name, $name, $type);
            if (! $response->successful()) {
                return [false, "Backup DNS record removal failed on {$node->name}: " . $response->body()];
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
            ->when($nodeId, fn ($query) => $query->where('id', '!=', $nodeId))
            ->where('status', 'online')
            ->orderBy('id')
            ->get();
    }
}
