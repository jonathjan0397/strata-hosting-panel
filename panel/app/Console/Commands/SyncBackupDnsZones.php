<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DnsZone;
use App\Models\Node;
use App\Models\User;
use App\Notifications\BackupDnsSyncIssueNotification;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncBackupDnsZones extends Command
{
    protected $signature = 'dns:sync-backup-zones {--zone= : Sync only one DNS zone name}';
    protected $description = 'Verify authoritative DNS zones and mirror managed zones to online backup DNS nodes.';

    public function handle(): int
    {
        $provisioner = new DnsProvisioner(AgentClient::for(Node::where('is_primary', true)->orderBy('id')->first() ?? Node::orderBy('id')->firstOrFail()));

        $zones = DnsZone::with(['records', 'node'])
            ->whereNotNull('node_id')
            ->where('active', true)
            ->when($this->option('zone'), fn ($query, $zone) => $query->where('zone_name', rtrim(strtolower($zone), '.')))
            ->get();

        if ($zones->isEmpty()) {
            $this->line('No managed DNS zones to sync.');
            return Command::SUCCESS;
        }

        $synced = 0;
        $healed = 0;
        $errors = 0;
        $issues = [];

        foreach ($zones as $zone) {
            if ($provisioner->backfillStandaloneHostZoneRecords($zone)) {
                AuditLog::record('dns.standalone_host_zone_records_backfilled', $zone, [
                    'zone' => $zone->zone_name,
                ]);
                $this->info("Zone {$zone->zone_name} host records were backfilled into the panel database.");
            }

            $authoritativeNode = $zone->node;
            if ($authoritativeNode && $authoritativeNode->status === 'online') {
                $health = $provisioner->ensureAuthoritativeZoneHealthy($zone, $authoritativeNode);
                if (! $health['success']) {
                    $errors++;
                    $issue = $this->issuePayload($zone, $authoritativeNode, 'authoritative_drift_unresolved', (string) $health['details']);
                    $issues[] = $issue;
                    $this->warn("Zone {$zone->zone_name} authoritative drift remains on {$authoritativeNode->name}: {$health['details']}");
                    continue;
                }

                $this->clearIssueCache($zone, $authoritativeNode);

                if ($health['healed']) {
                    $healed++;
                    AuditLog::record('dns.authoritative_zone_self_healed', $zone, [
                        'zone' => $zone->zone_name,
                        'node' => $authoritativeNode->name,
                        'details' => $health['details'],
                    ]);
                    $this->info("Zone {$zone->zone_name} authoritative self-healed on {$authoritativeNode->name}: {$health['details']}");
                }
            }

            $backupNodes = Node::whereNull('deleted_at')
                ->where('hosts_dns', true)
                ->where('status', 'online')
                ->where('id', '!=', $zone->node_id)
                ->orderBy('id')
                ->get();

            foreach ($backupNodes as $node) {
                [$syncSuccess, $syncError] = $provisioner->syncZoneToNode($zone, $node);
                if (! $syncSuccess) {
                    $errors++;
                    $issue = $this->issuePayload($zone, $node, 'sync_failed', $syncError);
                    $issues[] = $issue;
                    $this->warn("Zone {$zone->zone_name} failed on {$node->name}: {$syncError}");
                    continue;
                }

                $health = $provisioner->ensureBackupZoneHealthy($zone, $node);
                if (! $health['success']) {
                    $errors++;
                    $issue = $this->issuePayload($zone, $node, 'drift_unresolved', (string) $health['details']);
                    $issues[] = $issue;
                    $this->warn("Zone {$zone->zone_name} drift remains on {$node->name}: {$health['details']}");
                    continue;
                }

                $this->clearIssueCache($zone, $node);

                if ($health['healed']) {
                    $healed++;
                    AuditLog::record('dns.backup_zone_self_healed', $zone, [
                        'zone' => $zone->zone_name,
                        'node' => $node->name,
                        'details' => $health['details'],
                    ]);
                    $this->info("Zone {$zone->zone_name} self-healed on {$node->name}: {$health['details']}");
                }

                $synced++;
            }
        }

        if ($issues !== []) {
            $this->notifyAdmins($issues);
        }

        $this->info("Backup DNS sync complete. Zone-node syncs: {$synced}; self-healed: {$healed}; errors: {$errors}.");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function issuePayload(DnsZone $zone, Node $node, string $result, string $details): array
    {
        return [
            'zone' => $zone->zone_name,
            'node' => $node->name,
            'node_id' => $node->id,
            'result' => $result,
            'details' => $details,
        ];
    }

    private function notifyAdmins(array $issues): void
    {
        $freshIssues = [];

        foreach ($issues as $issue) {
            $cacheKey = $this->cacheKey($issue['zone'], (int) $issue['node_id'], $issue['result']);
            if (Cache::has($cacheKey)) {
                continue;
            }

            Cache::put($cacheKey, true, now()->addHour());
            $freshIssues[] = $issue;

            AuditLog::record('dns.backup_sync_issue_detected', null, $issue);
        }

        if ($freshIssues === []) {
            return;
        }

        $admins = User::role('admin')->get();
        foreach ($admins as $admin) {
            try {
                $admin->notify(new BackupDnsSyncIssueNotification($freshIssues));
            } catch (\Throwable) {
                // Notification delivery must never break the sync command.
            }
        }
    }

    private function clearIssueCache(DnsZone $zone, Node $node): void
    {
        Cache::forget("dns-backup-sync:{$zone->zone_name}:{$node->id}:sync_failed");
        Cache::forget("dns-backup-sync:{$zone->zone_name}:{$node->id}:drift_unresolved");
        Cache::forget("dns-backup-sync:{$zone->zone_name}:{$node->id}:authoritative_drift_unresolved");
    }

    private function cacheKey(string $zoneName, int $nodeId, string $result): string
    {
        return 'dns-backup-sync:' . $zoneName . ':' . $nodeId . ':' . $result;
    }
}
