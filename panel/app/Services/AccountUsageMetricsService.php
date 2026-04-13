<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTrafficMetric;
use App\Models\EmailAccount;
use App\Models\HostingDatabase;
use Illuminate\Support\Collection;

class AccountUsageMetricsService
{
    public function build(Account $account, int $trafficDays = 30): array
    {
        $account->loadMissing(['domains', 'emailAccounts', 'databases']);
        $databaseSizes = $this->databaseSizes($account);

        $domains = $account->domains->keyBy('id');
        $emailByDomain = $account->emailAccounts->groupBy('domain_id');
        $trafficByDomain = $this->trafficByDomain($account, $trafficDays);
        $databasesByDomain = $account->databases
            ->whereNotNull('domain_id')
            ->groupBy('domain_id');

        $domainMetrics = $domains
            ->map(function ($domain) use ($emailByDomain, $trafficByDomain, $databasesByDomain, $databaseSizes) {
                /** @var Collection<int, EmailAccount> $mailboxes */
                $mailboxes = $emailByDomain->get($domain->id, collect());
                $traffic = $trafficByDomain->get($domain->id, [
                    'requests' => 0,
                    'bandwidth_bytes' => 0,
                    'bandwidth_human' => '0 B',
                    'errors' => 0,
                ]);
                /** @var Collection<int, HostingDatabase> $domainDatabases */
                $domainDatabases = $databasesByDomain->get($domain->id, collect());
                $databaseBytes = (int) $domainDatabases->sum(
                    fn (HostingDatabase $database) => $databaseSizes[$this->sizeKey($database->db_name, $database->engine ?? 'mysql')] ?? 0
                );

                return [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'type' => $domain->type,
                    'ssl_enabled' => (bool) $domain->ssl_enabled,
                    'mailboxes' => $mailboxes->count(),
                    'email_used_mb' => (int) $mailboxes->sum('used_mb'),
                    'email_quota_mb' => (int) $mailboxes->sum('quota_mb'),
                    'database_count' => $domainDatabases->count(),
                    'database_bytes' => $databaseBytes,
                    'database_human' => $this->formatBytes($databaseBytes),
                    'requests_30d' => (int) $traffic['requests'],
                    'bandwidth_bytes_30d' => (int) $traffic['bandwidth_bytes'],
                    'bandwidth_human_30d' => $traffic['bandwidth_human'],
                    'errors_30d' => (int) $traffic['errors'],
                ];
            })
            ->sortBy('domain')
            ->values();

        $mailboxes = $account->emailAccounts;
        $databases = $account->databases;
        $assignedDatabaseCount = $account->databases->whereNotNull('domain_id')->count();
        $databaseBytes = (int) $databases->sum(
            fn (HostingDatabase $database) => $databaseSizes[$this->sizeKey($database->db_name, $database->engine ?? 'mysql')] ?? 0
        );

        return [
            'overview' => [
                'domains' => $domains->count(),
                'disk_used_mb' => (int) $account->disk_used_mb,
                'disk_limit_mb' => (int) $account->disk_limit_mb,
                'bandwidth_used_mb' => (int) $account->bandwidth_used_mb,
                'bandwidth_limit_mb' => (int) $account->bandwidth_limit_mb,
                'databases' => $databases->count(),
                'database_bytes' => $databaseBytes,
                'database_human' => $this->formatBytes($databaseBytes),
                'mailboxes' => $mailboxes->count(),
                'email_used_mb' => (int) $mailboxes->sum('used_mb'),
                'email_quota_mb' => (int) $mailboxes->sum('quota_mb'),
                'assigned_databases' => $assignedDatabaseCount,
                'unassigned_databases' => max($databases->count() - $assignedDatabaseCount, 0),
            ],
            'traffic_window_days' => $trafficDays,
            'domains' => $domainMetrics->all(),
        ];
    }

    private function databaseSizes(Account $account): array
    {
        if (! $account->node || $account->databases->isEmpty()) {
            return [];
        }

        $payload = $account->databases
            ->map(fn (HostingDatabase $database) => [
                'db_name' => $database->db_name,
                'engine' => $database->engine ?? 'mysql',
            ])
            ->unique(fn (array $database) => $this->sizeKey($database['db_name'], $database['engine']))
            ->values()
            ->all();

        try {
            $response = AgentClient::for($account->node)->databaseStats($payload);
            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('databases') ?? [])
                ->filter(fn (array $row) => isset($row['db_name']))
                ->mapWithKeys(fn (array $row) => [
                    $this->sizeKey($row['db_name'], $row['engine'] ?? 'mysql') => (int) ($row['size_bytes'] ?? 0),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function trafficByDomain(Account $account, int $trafficDays): Collection
    {
        $startDate = now()->subDays(max($trafficDays - 1, 0))->toDateString();

        return AccountTrafficMetric::query()
            ->where('account_id', $account->id)
            ->where('date', '>=', $startDate)
            ->get()
            ->groupBy('domain_id')
            ->map(function (Collection $rows) {
                $bandwidthBytes = (int) $rows->sum('bandwidth_bytes');

                return [
                    'requests' => (int) $rows->sum('requests'),
                    'bandwidth_bytes' => $bandwidthBytes,
                    'bandwidth_human' => $this->formatBytes($bandwidthBytes),
                    'errors' => (int) $rows->sum('status_4xx') + (int) $rows->sum('status_5xx'),
                ];
            });
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, 2) . ' ' . $unit;
            }

            $value /= 1024;
        }

        return $bytes . ' B';
    }

    private function sizeKey(string $dbName, string $engine): string
    {
        return $engine . ':' . $dbName;
    }
}
