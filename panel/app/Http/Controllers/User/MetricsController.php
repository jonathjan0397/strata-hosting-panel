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
}
