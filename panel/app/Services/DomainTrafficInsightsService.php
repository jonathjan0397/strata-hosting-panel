<?php

namespace App\Services;

use App\Models\AccountTrafficMetric;
use App\Models\Domain;

class DomainTrafficInsightsService
{
    public function summarizeAccessLog(string $content): array
    {
        $requests = 0;
        $bandwidthBytes = 0;
        $statusCounts = [];
        $methodCounts = [];
        $pathCounts = [];

        foreach (preg_split('/\R/', trim($content)) as $line) {
            if ($line === '') {
                continue;
            }

            if (! preg_match('/^\S+ \S+ \S+ \[[^\]]+\] "([A-Z]+)\s+([^"]+?)\s+HTTP\/[0-9.]+" (\d{3}) (\d+|-)/', $line, $matches)) {
                continue;
            }

            $requests++;
            $method = $matches[1];
            $path = $this->normalizeRequestPath($matches[2]);
            $status = $matches[3];
            $bytes = $matches[4] === '-' ? 0 : (int) $matches[4];

            $bandwidthBytes += $bytes;
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;
            $pathCounts[$path] = ($pathCounts[$path] ?? 0) + 1;
        }

        ksort($statusCounts);
        ksort($methodCounts);

        return [
            'requests' => $requests,
            'bandwidth_bytes' => $bandwidthBytes,
            'bandwidth_human' => $this->formatBytes($bandwidthBytes),
            'status_counts' => $statusCounts,
            'method_counts' => $methodCounts,
            'top_paths' => $this->topCounts($pathCounts, 8),
        ];
    }

    public function history(Domain $domain, int $days = 30): array
    {
        $days = max(1, min($days, 365));

        $metrics = AccountTrafficMetric::query()
            ->where('account_id', $domain->account_id)
            ->where('domain_id', $domain->id)
            ->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->orderBy('date')
            ->get();

        $byDay = $metrics
            ->groupBy(fn (AccountTrafficMetric $metric) => $metric->date->toDateString())
            ->map(fn ($rows) => [
                'date' => $rows->first()->date->toDateString(),
                'requests' => $rows->sum('requests'),
                'bandwidth_bytes' => $rows->sum('bandwidth_bytes'),
                'bandwidth_human' => $this->formatBytes((int) $rows->sum('bandwidth_bytes')),
                'status_2xx' => $rows->sum('status_2xx'),
                'status_3xx' => $rows->sum('status_3xx'),
                'status_4xx' => $rows->sum('status_4xx'),
                'status_5xx' => $rows->sum('status_5xx'),
            ]);

        $history = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $history[] = $byDay[$date] ?? [
                'date' => $date,
                'requests' => 0,
                'bandwidth_bytes' => 0,
                'bandwidth_human' => '0 B',
                'status_2xx' => 0,
                'status_3xx' => 0,
                'status_4xx' => 0,
                'status_5xx' => 0,
            ];
        }

        return [
            'days' => $history,
            'totals' => [
                'requests' => array_sum(array_column($history, 'requests')),
                'bandwidth_bytes' => array_sum(array_column($history, 'bandwidth_bytes')),
                'bandwidth_human' => $this->formatBytes((int) array_sum(array_column($history, 'bandwidth_bytes'))),
                'errors' => array_sum(array_column($history, 'status_4xx')) + array_sum(array_column($history, 'status_5xx')),
            ],
        ];
    }

    public function formatBytes(int $bytes): string
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

    private function normalizeRequestPath(string $requestPath): string
    {
        $path = parse_url($requestPath, PHP_URL_PATH);

        return $path ?: $requestPath;
    }

    private function topCounts(array $counts, int $limit): array
    {
        arsort($counts);

        return collect($counts)
            ->take($limit)
            ->map(fn (int $count, string $value) => [
                'value' => $value,
                'count' => $count,
            ])
            ->values()
            ->all();
    }
}
