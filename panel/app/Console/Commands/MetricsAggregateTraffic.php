<?php

namespace App\Console\Commands;

use App\Models\AccountTrafficMetric;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Console\Command;

class MetricsAggregateTraffic extends Command
{
    protected $signature = 'metrics:aggregate-traffic {--days=30 : Number of recent days to refresh}';
    protected $description = 'Aggregate per-domain access logs into daily traffic metrics.';

    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $domains = Domain::query()
            ->with(['account.node'])
            ->whereHas('account', fn ($query) => $query->whereNotNull('node_id'))
            ->get();

        if ($domains->isEmpty()) {
            $this->info('No hosted domains found.');
            return Command::SUCCESS;
        }

        foreach ($domains as $domain) {
            $account = $domain->account;
            if (! $account?->node) {
                continue;
            }

            $path = $domain->domain . '.access.log';
            $this->line("Aggregating {$account->username}/{$path}");

            try {
                $response = AgentClient::for($account->node)
                    ->trafficSummary($account->username, $path, $days);

                if (! $response->successful()) {
                    $this->warn("  skipped: {$response->body()}");
                    continue;
                }

                foreach (($response->json('days') ?? []) as $day) {
                    AccountTrafficMetric::updateOrCreate(
                        [
                            'domain_id' => $domain->id,
                            'date' => $day['date'],
                        ],
                        [
                            'account_id' => $account->id,
                            'node_id' => $domain->node_id,
                            'requests' => $day['requests'] ?? 0,
                            'bandwidth_bytes' => $day['bandwidth_bytes'] ?? 0,
                            'status_2xx' => $day['status_2xx'] ?? 0,
                            'status_3xx' => $day['status_3xx'] ?? 0,
                            'status_4xx' => $day['status_4xx'] ?? 0,
                            'status_5xx' => $day['status_5xx'] ?? 0,
                        ],
                    );
                }
            } catch (\Throwable $e) {
                $this->warn("  failed: {$e->getMessage()}");
            }
        }

        $this->info('Traffic aggregation complete.');
        return Command::SUCCESS;
    }
}
