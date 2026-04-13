<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTrafficMetric;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\FtpAccount;
use App\Models\HostingDatabase;
use App\Services\AgentClient;
use App\Services\AccountUsageMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MetricsController extends Controller
{
    public function __construct(private readonly AccountUsageMetricsService $usageMetrics)
    {
    }

    public function index(Request $request): Response
    {
        $account = $this->account($request);
        $usageMetrics = $this->usageMetrics->build($account);

        return Inertia::render('User/Metrics', [
            'account' => [
                'id' => $account->id,
                'username' => $account->username,
                'status' => $account->status,
                'php_version' => $account->php_version,
                'disk_used_mb' => $account->disk_used_mb,
                'disk_limit_mb' => $account->disk_limit_mb,
                'bandwidth_used_mb' => $account->bandwidth_used_mb,
                'bandwidth_limit_mb' => $account->bandwidth_limit_mb,
                'hosting_package' => $account->hostingPackage?->only(['id', 'name', 'slug']),
            ],
            'summary' => [
                'domains' => $account->domains->count(),
                'databases' => HostingDatabase::where('account_id', $account->id)->count(),
                'mailboxes' => EmailAccount::where('account_id', $account->id)->count(),
                'ftp_accounts' => FtpAccount::where('account_id', $account->id)->count(),
            ],
            'usageMetrics' => $usageMetrics,
            'domains' => $account->domains
                ->map(fn (Domain $domain) => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'type' => $domain->type,
                    'ssl_enabled' => $domain->ssl_enabled,
                ])
                ->values(),
            'trafficHistory' => $this->trafficHistory($account),
            'logTypes' => $this->logTypes(),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['nullable', 'exists:domains,id'],
            'type' => ['required', 'in:access,error,php'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:300'],
        ]);

        $account = $this->account($request);
        $client = AgentClient::for($account->node);
        $lines = $data['lines'] ?? 120;
        $path = $this->resolveLogPath($account, $data['type'], $data['domain_id'] ?? null);

        $response = $client->fileTail($account->username, $path, $lines);

        if (! $response->successful()) {
            return response()->json([
                'error' => $response->body(),
            ], $response->status());
        }

        return response()->json([
            'path' => $path,
            'content' => $response->json('content') ?? '',
            'lines' => $lines,
            'type' => $data['type'],
        ]);
    }

    public function download(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate([
            'domain_id' => ['nullable', 'exists:domains,id'],
            'type' => ['required', 'in:access,error,php'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $account = $this->account($request);
        $client = AgentClient::for($account->node);
        $lines = $data['lines'] ?? 300;
        $path = $this->resolveLogPath($account, $data['type'], $data['domain_id'] ?? null);

        $response = $client->fileTail($account->username, $path, $lines);

        if (! $response->successful()) {
            abort($response->status(), $response->body());
        }

        $filename = str_replace(['/', '\\'], '-', $path);

        return response($response->json('content') ?? '', 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"recent-{$filename}\"",
        ]);
    }

    public function traffic(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['required', 'exists:domains,id'],
            'lines' => ['nullable', 'integer', 'min:50', 'max:300'],
        ]);

        $account = $this->account($request);
        $client = AgentClient::for($account->node);
        $lines = $data['lines'] ?? 300;
        $path = $this->resolveLogPath($account, 'access', (int) $data['domain_id']);

        $response = $client->fileTail($account->username, $path, $lines);

        if (! $response->successful()) {
            return response()->json([
                'error' => $response->body(),
            ], $response->status());
        }

        return response()->json([
            'path' => $path,
            'lines' => $lines,
            ...$this->summarizeAccessLog($response->json('content') ?? ''),
        ]);
    }

    public function trafficExport(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'domain_id' => ['nullable', 'exists:domains,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $account = $this->account($request);
        $days = $data['days'] ?? 30;
        $startDate = now()->subDays($days - 1)->toDateString();

        $domainId = $data['domain_id'] ?? null;
        if ($domainId) {
            abort_unless($account->domains->contains('id', (int) $domainId), 404, 'Domain not found for this account.');
        }

        $query = AccountTrafficMetric::with('domain:id,domain')
            ->where('account_id', $account->id)
            ->where('date', '>=', $startDate)
            ->orderBy('date')
            ->orderBy('domain_id');

        if ($domainId) {
            $query->where('domain_id', $domainId);
        }

        $filename = "{$account->username}-traffic-{$startDate}-to-" . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'domain', 'requests', 'bandwidth_bytes', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx']);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->date?->toDateString(),
                        $row->domain?->domain ?? 'unknown',
                        $row->requests,
                        $row->bandwidth_bytes,
                        $row->status_2xx,
                        $row->status_3xx,
                        $row->status_4xx,
                        $row->status_5xx,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function account(Request $request): Account
    {
        return $request->user()
            ->account()
            ->with(['node', 'domains', 'hostingPackage'])
            ->firstOrFail();
    }

    private function resolveLogPath(Account $account, string $type, ?int $domainId): string
    {
        if ($type === 'php') {
            return 'php_errors.log';
        }

        $domain = $account->domains->firstWhere('id', $domainId);
        abort_unless($domain, 404, 'Domain not found for this account.');

        $suffix = $type === 'access' ? 'access' : 'error';

        return $domain->domain . '.' . $suffix . '.log';
    }

    private function logTypes(): array
    {
        return [
            ['value' => 'access', 'label' => 'Access Log'],
            ['value' => 'error', 'label' => 'Error Log'],
            ['value' => 'php', 'label' => 'PHP Error Log'],
        ];
    }

    private function summarizeAccessLog(string $content): array
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

    private function trafficHistory(Account $account): array
    {
        $metrics = AccountTrafficMetric::query()
            ->where('account_id', $account->id)
            ->where('date', '>=', now()->subDays(29)->toDateString())
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
        for ($i = 29; $i >= 0; $i--) {
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
}
