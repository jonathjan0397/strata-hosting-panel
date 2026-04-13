<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AccountTrafficMetric;
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

    private function account()
    {
        return auth()->user()->account()->firstOrFail();
    }

    public function index(): Response
    {
        $account = $this->account();
        $domains = Domain::where('account_id', $account->id)->latest()->get();

        return Inertia::render('User/Domains/Index', [
            'account' => $account,
            'domains' => $domains,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('User/Domains/Create', [
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->account();
        $this->purgeTrashedDomain($request->input('domain'));

        if ($account->isSuspended()) {
            return back()->with('error', 'Your account is suspended.');
        }

        if ($account->max_domains > 0) {
            $count = Domain::where('account_id', $account->id)->count();
            if ($count >= $account->max_domains) {
                return back()->with('error', "Domain limit reached ({$account->max_domains}).");
            }
        }

        $data = $request->validate([
            'domain'      => ['required', 'string', 'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', Rule::unique('domains', 'domain')->whereNull('deleted_at')],
            'type'        => ['required', 'in:addon,subdomain,parked'],
            'php_version' => ['nullable', 'in:8.1,8.2,8.3,8.4'],
        ]);

        $slug    = str_replace(['.', '-'], '_', $data['domain']);
        $docRoot = $data['type'] === 'parked'
            ? "/var/www/{$account->username}/public_html"
            : "/var/www/{$account->username}/{$slug}";

        $domain = Domain::create([
            'account_id'    => $account->id,
            'node_id'       => $account->node_id,
            'domain'        => strtolower($data['domain']),
            'type'          => $data['type'],
            'document_root' => $docRoot,
            'web_server'    => $account->node?->web_server ?? 'nginx',
            'php_version'   => $data['php_version'] ?? $account->php_version,
        ]);

        [$success, $error] = app(DomainProvisioner::class)->provision($domain);

        if (! $success) {
            $domain->forceDelete();
            return back()->with('error', "Domain saved but vhost creation failed: {$error}");
        }

        return redirect()->route('my.domains.show', $domain)
            ->with('success', "{$domain->domain} added.");
    }

    public function show(Domain $domain): Response
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $domain->load('node');

        return Inertia::render('User/Domains/Show', [
            'domain'      => $domain,
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4'],
            'canIssueWildcardSsl' => app(DomainProvisioner::class)->supportsWildcardSsl($domain),
            'canManagePrivacy' => $account->hasFeature('directory_privacy'),
            'canManageHotlinkProtection' => $account->hasFeature('hotlink_protection'),
            'canManageModSecurity' => $account->hasFeature('modsecurity'),
            'canManageLeechProtection' => $account->hasFeature('leech_protection'),
            'canViewMetrics' => $account->hasFeature('metrics'),
            'trafficHistory' => $this->trafficInsights->history($domain),
        ]);
    }

    public function traffic(Request $request, Domain $domain): JsonResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $lines = $request->validate([
            'lines' => ['nullable', 'integer', 'min:50', 'max:300'],
        ])['lines'] ?? 300;

        $path = $this->resolveLogPath($domain, 'access');
        $response = AgentClient::for($domain->node)->fileTail($account->username, $path, $lines);

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
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $filename = "{$account->username}-{$domain->domain}-traffic-" . now()->subDays(29)->toDateString() . '-to-' . now()->toDateString() . '.csv';

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
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'type' => ['required', 'in:access,error'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:300'],
        ]);

        $lines = $data['lines'] ?? 120;
        $path = $this->resolveLogPath($domain, $data['type']);
        $response = AgentClient::for($domain->node)->fileTail($account->username, $path, $lines);

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
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'type' => ['required', 'in:access,error'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $lines = $data['lines'] ?? 300;
        $path = $this->resolveLogPath($domain, $data['type']);
        $response = AgentClient::for($domain->node)->fileTail($account->username, $path, $lines);

        if (! $response->successful()) {
            abort($response->status(), $response->body());
        }

        return response($response->json('content') ?? '', 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="recent-' . str_replace(['/', '\\'], '-', $path) . '"',
        ]);
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        [$success, $error] = app(DomainProvisioner::class)->deprovision($domain);

        if (! $success) {
            return back()->with('error', "Domain deletion failed and the domain was kept: {$error}");
        }

        $domainName = $domain->domain;
        $domain->forceDelete();

        return redirect()->route('my.domains.index')
            ->with('success', "{$domainName} and its managed DNS/settings were deleted.");
    }

    public function issueSSL(Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = request()->validate([
            'wildcard' => ['nullable', 'boolean'],
        ]);

        $wildcard = (bool) ($data['wildcard'] ?? false);
        [$success, $error] = app(DomainProvisioner::class)->issueSSL($domain, $wildcard);

        return $success
            ? back()->with('success', $wildcard
                ? "Wildcard SSL certificate issued for {$domain->domain} and *.{$domain->domain}."
                : "SSL certificate issued for {$domain->domain}.")
            : back()->with('error', "SSL issuance failed: {$error}");
    }

    public function uploadCert(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'cert_pem' => ['required', 'string'],
            'key_pem'  => ['required', 'string'],
        ]);

        [$success, $error] = app(DomainProvisioner::class)->storeCustomSSL($domain, $data['cert_pem'], $data['key_pem']);

        return $success
            ? back()->with('success', "Custom SSL certificate installed for {$domain->domain}.")
            : back()->with('error', "Certificate upload failed: {$error}");
    }

    public function updateForceHttps(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'force_https' => ['required', 'boolean'],
        ]);

        if ($data['force_https'] && ! app(DomainProvisioner::class)->hasUsableSsl($domain)) {
            return back()->with('error', 'Force HTTPS requires an installed SSL certificate first.');
        }

        $previous = $domain->force_https;
        $domain->update(['force_https' => $data['force_https']]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['force_https' => $previous]);
            app(DomainProvisioner::class)->reprovision($domain->fresh());
        }

        return $success
            ? back()->with('success', $data['force_https'] ? 'Force HTTPS enabled.' : 'Force HTTPS disabled.')
            : back()->with('error', "Force HTTPS update failed and was rolled back: {$error}");
    }

    public function changePhp(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'php_version' => ['required', 'in:8.1,8.2,8.3,8.4'],
        ]);

        [$success, $error] = app(DomainProvisioner::class)->changePhpVersion($domain, $data['php_version']);

        if (! $success) {
            return back()->with('error', "PHP version change failed: {$error}");
        }

        $domain->update(['php_version' => $data['php_version']]);

        return back()->with('success', "PHP version set to {$data['php_version']}.");
    }

    public function updateDirectives(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'custom_directives' => ['nullable', 'string', 'max:4096'],
        ]);

        $previousDirectives = $domain->custom_directives;
        $domain->update(['custom_directives' => $data['custom_directives'] ?? null]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['custom_directives' => $previousDirectives]);
        }

        return $success
            ? back()->with('success', 'Custom directives saved and vhost updated.')
            : back()->with('error', "Vhost update failed and directives were rolled back: {$error}");
    }

    public function storeRedirect(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'source'      => ['required', 'string', 'regex:/^\//', 'max:255'],
            'destination' => ['required', 'url', 'max:2048'],
            'type'        => ['required', 'in:301,302'],
        ]);

        $redirects   = $domain->redirects ?? [];
        $redirects[] = [
            'source'      => $data['source'],
            'destination' => $data['destination'],
            'type'        => (int) $data['type'],
        ];

        $previousRedirects = $domain->redirects;
        $domain->update(['redirects' => $redirects]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['redirects' => $previousRedirects]);
        }

        return $success
            ? back()->with('success', 'Redirect added.')
            : back()->with('error', "Vhost update failed and the redirect change was rolled back: {$error}");
    }

    public function destroyRedirect(Request $request, Domain $domain, int $index): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $redirects = $domain->redirects ?? [];
        array_splice($redirects, $index, 1);

        $previousRedirects = $domain->redirects;
        $domain->update(['redirects' => $redirects ?: null]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['redirects' => $previousRedirects]);
        }

        return $success
            ? back()->with('success', 'Redirect removed.')
            : back()->with('error', "Vhost update failed and the redirect change was rolled back: {$error}");
    }

    public function storePrivacy(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);
        abort_unless($account->hasFeature('directory_privacy'), 403);

        $data = $request->validate([
            'path' => ['required', 'string', 'regex:/^\/[A-Za-z0-9._\/-]+$/', 'not_in:/', 'max:255'],
            'username' => ['required', 'string', 'alpha_dash', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        if (str_contains($data['path'], '..')) {
            return back()->with('error', 'Protected paths cannot contain parent directory traversal.');
        }

        $rules = $domain->directory_privacy ?? [];
        $normalizedPath = rtrim($data['path'], '/');

        foreach ($rules as $rule) {
            if (($rule['path'] ?? null) === $normalizedPath) {
                return back()->with('error', 'That directory already has privacy enabled.');
            }
        }

        $previousRules = $domain->directory_privacy;
        $rules[] = [
            'path' => $normalizedPath,
            'username' => $data['username'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
        ];

        $domain->update(['directory_privacy' => $rules]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['directory_privacy' => $previousRules]);
            app(DomainProvisioner::class)->reprovision($domain->fresh());
        }

        return $success
            ? back()->with('success', 'Directory privacy enabled.')
            : back()->with('error', "Directory privacy update failed and was rolled back: {$error}");
    }

    public function destroyPrivacy(Domain $domain, int $index): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);
        abort_unless($account->hasFeature('directory_privacy'), 403);

        $rules = $domain->directory_privacy ?? [];
        if (! array_key_exists($index, $rules)) {
            return back()->with('error', 'Protected directory rule not found.');
        }

        $previousRules = $domain->directory_privacy;
        array_splice($rules, $index, 1);
        $domain->update(['directory_privacy' => $rules ?: null]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['directory_privacy' => $previousRules]);
            app(DomainProvisioner::class)->reprovision($domain->fresh());
        }

        return $success
            ? back()->with('success', 'Directory privacy removed.')
            : back()->with('error', "Directory privacy removal failed and was rolled back: {$error}");
    }

    public function updateHotlinkProtection(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);
        abort_unless($account->hasFeature('hotlink_protection'), 403);

        $data = $request->validate([
            'allow_direct' => ['nullable', 'boolean'],
            'allowed_domains' => ['nullable', 'string', 'max:2048'],
            'extensions' => ['nullable', 'string', 'max:512'],
        ]);

        $allowedDomains = $this->parseDomainList($data['allowed_domains'] ?? '');
        $extensions = $this->parseExtensionList($data['extensions'] ?? '');

        if ($extensions === []) {
            $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];
        }

        $previousConfig = $domain->hotlink_protection;
        $domain->update([
            'hotlink_protection' => [
                'enabled' => true,
                'allow_direct' => (bool) ($data['allow_direct'] ?? true),
                'allowed_domains' => $allowedDomains,
                'extensions' => $extensions,
            ],
        ]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['hotlink_protection' => $previousConfig]);
            app(DomainProvisioner::class)->reprovision($domain->fresh());
        }

        return $success
            ? back()->with('success', 'Hotlink protection enabled.')
            : back()->with('error', "Hotlink protection update failed and was rolled back: {$error}");
    }

    public function disableHotlinkProtection(Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);
        abort_unless($account->hasFeature('hotlink_protection'), 403);

        $previousConfig = $domain->hotlink_protection;
        $domain->update(['hotlink_protection' => null]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['hotlink_protection' => $previousConfig]);
            app(DomainProvisioner::class)->reprovision($domain->fresh());
        }

        return $success
            ? back()->with('success', 'Hotlink protection disabled.')
            : back()->with('error', "Hotlink protection removal failed and was rolled back: {$error}");
    }

    public function updateModSecurity(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);
        abort_unless($account->hasFeature('modsecurity'), 403);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'mode' => ['required', 'in:on,detection_only'],
        ]);

        $previousConfig = $domain->modsecurity;
        $domain->update([
            'modsecurity' => $data['enabled']
                ? ['enabled' => true, 'mode' => $data['mode']]
                : null,
        ]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['modsecurity' => $previousConfig]);
            app(DomainProvisioner::class)->reprovision($domain->fresh());
        }

        return $success
            ? back()->with('success', $data['enabled'] ? 'ModSecurity updated.' : 'ModSecurity disabled.')
            : back()->with('error', "ModSecurity update failed and was rolled back: {$error}");
    }

    public function updateLeechProtection(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);
        abort_unless($account->hasFeature('leech_protection'), 403);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'path' => ['required_if:enabled,true', 'nullable', 'string', 'regex:/^\/[A-Za-z0-9._\/-]+$/', 'not_in:/', 'max:255'],
            'requests_per_minute' => ['required_if:enabled,true', 'nullable', 'integer', 'min:1', 'max:120'],
            'redirect_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if (($data['path'] ?? null) && str_contains($data['path'], '..')) {
            return back()->with('error', 'Protected paths cannot contain parent directory traversal.');
        }

        $previousConfig = $domain->leech_protection;
        $domain->update([
            'leech_protection' => $data['enabled']
                ? [
                    'enabled' => true,
                    'path' => rtrim($data['path'], '/'),
                    'requests_per_minute' => (int) $data['requests_per_minute'],
                    'redirect_url' => $data['redirect_url'] ?? null,
                ]
                : null,
        ]);

        [$success, $error] = app(DomainProvisioner::class)->reprovision($domain);

        if (! $success) {
            $domain->update(['leech_protection' => $previousConfig]);
            app(DomainProvisioner::class)->reprovision($domain->fresh());
        }

        return $success
            ? back()->with('success', $data['enabled'] ? 'Leech protection updated.' : 'Leech protection disabled.')
            : back()->with('error', "Leech protection update failed and was rolled back: {$error}");
    }

    private function parseDomainList(string $value): array
    {
        $domains = preg_split('/[\s,]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($domains, fn ($domain) =>
            preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)
        )));
    }

    private function parseExtensionList(string $value): array
    {
        $extensions = preg_split('/[\s,]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn ($extension) => trim($extension, '.'),
            $extensions
        ), fn ($extension) => preg_match('/^[a-z0-9]+$/', $extension))));
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
