<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\FtpAccount;
use App\Models\HostingDatabase;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetricsController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);

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
            'domains' => $account->domains
                ->map(fn (Domain $domain) => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'type' => $domain->type,
                    'ssl_enabled' => $domain->ssl_enabled,
                ])
                ->values(),
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
}
