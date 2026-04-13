<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTrafficMetric;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\AgentClient;
use App\Services\DomainTrafficInsightsService;
use App\Services\DomainProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DomainController extends Controller
{
    public function __construct(private readonly DomainTrafficInsightsService $trafficInsights)
    {
    }

    public function index(Request $request): Response
    {
        $domains = Domain::with(['account.user', 'node'])
            ->when($request->input('search'), fn ($q, $s) =>
                $q->where('domain', 'like', "%{$s}%")
            )
            ->when($request->input('account_id'), fn ($q, $id) =>
                $q->where('account_id', $id)
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Domains/Index', [
            'domains' => $domains,
            'filters' => $request->only('search', 'account_id'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Domains/Create', [
            'accounts'    => Account::with('user')->where('status', 'active')->get()
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->username} ({$a->user->email})", 'node_id' => $a->node_id]),
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
            'preselect'   => $request->input('account_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->purgeTrashedDomain($request->input('domain'));

        $data = $request->validate([
            'account_id'   => ['required', 'exists:accounts,id'],
            'domain'       => ['required', 'string', 'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', Rule::unique('domains', 'domain')->whereNull('deleted_at')],
            'type'         => ['required', 'in:main,addon,subdomain,parked'],
            'php_version'  => ['nullable', 'in:8.1,8.2,8.3,8.4'],
            'web_server'   => ['nullable', 'in:nginx,apache'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        $account->loadMissing('node');
        $docRoot = "/var/www/{$account->username}/public_html";
        if ($data['type'] === 'addon' || $data['type'] === 'subdomain') {
            $slug    = str_replace(['.', '-'], '_', $data['domain']);
            $docRoot = "/var/www/{$account->username}/{$slug}";
        }

        $domain = Domain::create([
            'account_id'    => $account->id,
            'node_id'       => $account->node_id,
            'domain'        => strtolower($data['domain']),
            'type'          => $data['type'],
            'document_root' => $docRoot,
            'web_server'    => $data['web_server'] ?? $account->node?->web_server ?? 'nginx',
            'php_version'   => $data['php_version'],
        ]);

        [$success, $error] = app(DomainProvisioner::class)->provision($domain);

        if (! $success) {
            $domain->forceDelete();
            return back()->with('error', "Domain saved but vhost creation failed: {$error}");
        }

        AuditLog::record('domain.created', $domain, [
            'domain'      => $domain->domain,
            'account'     => $account->username,
            'provisioned' => true,
        ]);

        return redirect()->route('admin.domains.show', $domain)
            ->with('success', "Domain {$domain->domain} created.");
    }

    public function show(Domain $domain): Response
    {
        $domain->load(['account.user', 'node']);

        return Inertia::render('Admin/Domains/Show', [
            'domain' => $domain,
            'canIssueWildcardSsl' => app(DomainProvisioner::class)->supportsWildcardSsl($domain),
            'trafficHistory' => $this->trafficInsights->history($domain),
        ]);
    }

    public function traffic(Request $request, Domain $domain): JsonResponse
    {
        $lines = $request->validate([
            'lines' => ['nullable', 'integer', 'min:50', 'max:300'],
        ])['lines'] ?? 300;

        $path = $this->resolveLogPath($domain, 'access');
        $response = AgentClient::for($domain->node)->fileTail($domain->account->username, $path, $lines);

        if (! $response->successful()) {
            return response()->json([
                'error' => $response->body(),
            ], $response->status());
        }

        return response()->json([
            'path' => $path,
            'lines' => $lines,
            ...$this->trafficInsights->summarizeAccessLog($response->json('content') ?? ''),
        ]);
    }

    public function trafficExport(Domain $domain): SymfonyResponse
    {
        $filename = "{$domain->account->username}-{$domain->domain}-traffic-" . now()->subDays(29)->toDateString() . '-to-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($domain) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'domain', 'requests', 'bandwidth_bytes', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx']);

            AccountTrafficMetric::query()
                ->where('account_id', $domain->account_id)
                ->where('domain_id', $domain->id)
                ->where('date', '>=', now()->subDays(29)->toDateString())
                ->orderBy('date')
                ->chunk(500, function ($rows) use ($out, $domain) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->date?->toDateString(),
                            $domain->domain,
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

    public function logs(Request $request, Domain $domain): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:access,error'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:300'],
        ]);

        $lines = $data['lines'] ?? 120;
        $path = $this->resolveLogPath($domain, $data['type']);
        $response = AgentClient::for($domain->node)->fileTail($domain->account->username, $path, $lines);

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

    public function downloadLog(Request $request, Domain $domain): SymfonyResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:access,error'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $lines = $data['lines'] ?? 300;
        $path = $this->resolveLogPath($domain, $data['type']);
        $response = AgentClient::for($domain->node)->fileTail($domain->account->username, $path, $lines);

        if (! $response->successful()) {
            abort($response->status(), $response->body());
        }

        return response($response->json('content') ?? '', 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="recent-' . str_replace(['/', '\\'], '-', $path) . '"',
        ]);
    }

    public function issueSSL(Domain $domain): RedirectResponse
    {
        $data = request()->validate([
            'wildcard' => ['nullable', 'boolean'],
        ]);

        $wildcard = (bool) ($data['wildcard'] ?? false);
        [$success, $error] = app(DomainProvisioner::class)->issueSSL($domain, $wildcard);

        if (! $success) {
            return back()->with('error', "SSL issuance failed: {$error}");
        }

        AuditLog::record('domain.ssl_issued', $domain, [
            'domain'  => $domain->domain,
            'wildcard' => $wildcard,
            'success' => true,
        ]);

        return back()->with('success', $wildcard
            ? "Wildcard SSL certificate issued for {$domain->domain} and *.{$domain->domain}."
            : "SSL certificate issued for {$domain->domain}.");
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $accountId = $domain->account_id;
        $error = $this->deleteDomain($domain);

        if ($error) {
            return redirect()->route('admin.accounts.show', $accountId)
                ->with('error', $error);
        }

        return redirect()->route('admin.accounts.show', $accountId)
            ->with('success', 'Domain removed.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'domain_ids' => ['required', 'array', 'min:1', 'max:100'],
            'domain_ids.*' => ['integer', 'exists:domains,id'],
        ]);

        $deleted = 0;
        $errors = [];

        Domain::with(['account:id,username'])
            ->whereIn('id', $data['domain_ids'])
            ->get()
            ->each(function (Domain $domain) use (&$deleted, &$errors) {
                $error = $this->deleteDomain($domain);

                if ($error) {
                    $errors[] = "{$domain->domain}: {$error}";
                    return;
                }

                $deleted++;
            });

        if ($errors !== []) {
            return back()->with(
                $deleted > 0 ? 'success' : 'error',
                $deleted > 0
                    ? "Deleted {$deleted} domain(s). " . count($errors) . ' failed: ' . implode(' ', array_slice($errors, 0, 3))
                    : 'No domains deleted. ' . implode(' ', array_slice($errors, 0, 3))
            );
        }

        return back()->with('success', "Deleted {$deleted} domain(s).");
    }

    private function deleteDomain(Domain $domain): ?string
    {
        [$success, $error] = app(DomainProvisioner::class)->deprovision($domain);

        if (! $success) {
            return "Server cleanup failed, domain was kept in the panel: {$error}";
        }

        AuditLog::record('domain.deleted', $domain, [
            'domain'        => $domain->domain,
            'deprovisioned' => true,
        ]);

        $domain->forceDelete();

        return null;
    }

    private function purgeTrashedDomain(mixed $domain): void
    {
        if (! is_string($domain) || trim($domain) === '') {
            return;
        }

        Domain::onlyTrashed()
            ->where('domain', strtolower(trim($domain)))
            ->forceDelete();
    }

    private function resolveLogPath(Domain $domain, string $type): string
    {
        return $domain->domain . '.' . ($type === 'error' ? 'error' : 'access') . '.log';
    }
}
