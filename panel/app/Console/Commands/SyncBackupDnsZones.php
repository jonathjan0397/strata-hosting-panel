<?php

namespace App\Console\Commands;

use App\Models\DnsZone;
use App\Models\Node;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use Illuminate\Console\Command;

class SyncBackupDnsZones extends Command
{
    protected $signature = 'dns:sync-backup-zones {--zone= : Sync only one DNS zone name}';
    protected $description = 'Mirror managed DNS zones to online backup DNS nodes.';

    public function handle(): int
    {
        $provisioner = new DnsProvisioner(AgentClient::for(Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->firstOrFail()));
        $nameservers = $provisioner->authoritativeNameservers();

        $zones = DnsZone::with('records')
            ->whereNotNull('node_id')
            ->where('active', true)
            ->when($this->option('zone'), fn ($query, $zone) => $query->where('zone_name', rtrim(strtolower($zone), '.')))
            ->get();

        if ($zones->isEmpty()) {
            $this->line('No managed DNS zones to sync.');
            return Command::SUCCESS;
        }

        $synced = 0;
        $errors = 0;

        foreach ($zones as $zone) {
            $backupNodes = Node::whereNull('deleted_at')
                ->where('status', 'online')
                ->where('id', '!=', $zone->node_id)
                ->orderBy('id')
                ->get();

            foreach ($backupNodes as $node) {
                $client = AgentClient::for($node);
                $zoneResponse = $client->createDnsZone($zone->zone_name, $nameservers);

                if (! DnsProvisioner::zoneProvisionResponseIsUsable($zoneResponse)) {
                    $errors++;
                    $this->warn("Zone {$zone->zone_name} failed on {$node->name}: {$zoneResponse->body()}");
                    continue;
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
                        $errors++;
                        $this->warn("Record {$record->type} {$record->name} failed on {$node->name}: {$recordResponse->body()}");
                    }
                }

                $synced++;
            }
        }

        $this->info("Backup DNS sync complete. Zone-node syncs: {$synced}; errors: {$errors}.");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
