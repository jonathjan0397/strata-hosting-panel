<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\DnsZone;
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

            // Seed default records: A record pointing to node IP.
            $nodeIp = $domain->node?->ip_address;
            if ($nodeIp) {
                $this->addRecord($zone, '@', 'A', 300, [$nodeIp]);
                $this->addRecord($zone, 'www', 'CNAME', 300, [$domain->domain . '.']);
            }

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
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

        if ($domain->dkim_dns_record) {
            $this->addRecord($zone, 'default._domainkey', 'TXT', 300, [$domain->dkim_dns_record], true);
        }
        if ($domain->spf_dns_record) {
            $this->addRecord($zone, '@', 'TXT', 300, [$domain->spf_dns_record], true);
        }
        if ($domain->dmarc_dns_record) {
            $this->addRecord($zone, '_dmarc', 'TXT', 300, [$domain->dmarc_dns_record], true);
        }
        if ($nodeHostname = $domain->node?->hostname) {
            $this->addRecord($zone, '@', 'MX', 300, [$nodeHostname . '.'], true, 10);
        }
    }
}
