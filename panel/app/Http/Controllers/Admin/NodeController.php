<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AppInstallation;
use App\Models\BackupJob;
use App\Models\DatabaseGrant;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\EmailForwarder;
use App\Models\FtpAccount;
use App\Models\HostingDatabase;
use App\Models\Node;
use App\Services\AgentClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class NodeController extends Controller
{
    public function index(): Response
    {
        $nodes = Node::orderBy('is_primary', 'desc')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Nodes/Index', [
            'nodes' => $nodes,
            'panelVersion' => config('strata.version'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Nodes/Create', [
            'webServers'   => ['nginx', 'apache'],
            'accelerators' => ['varnish', 'redis', 'memcached'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'hostname'     => ['required', 'string', 'max:253'],
            'ip_address'   => ['required', 'ip'],
            'port'         => ['nullable', 'integer', 'between:1,65535'],
            'web_server'   => ['required', 'in:nginx,apache'],
            'hosts_dns'    => ['nullable', 'boolean'],
            'accelerators' => ['nullable', 'array'],
            'accelerators.*' => ['in:varnish,redis,memcached'],
        ]);

        $node = Node::create([
            ...$data,
            'port'         => $data['port'] ?? 8743,
            'node_id'      => Str::uuid()->toString(),
            'hmac_secret'  => Str::random(64),
            'status'       => 'unknown',
            'hosts_dns'    => (bool) ($data['hosts_dns'] ?? false),
            'accelerators' => $data['accelerators'] ?? [],
        ]);

        AuditLog::record('node.created', $node);

        return redirect()->route('admin.nodes.show', $node)
            ->with('success', 'Node created. Use the credentials below to install the agent.');
    }

    public function show(Node $node): Response
    {
        $health = null;
        $healthError = null;

        try {
            $response = AgentClient::for($node)->health();
            if ($response->successful()) {
                $health = $response->json();
                $node->update(['status' => 'online', 'last_seen_at' => now()]);
            } else {
                $node->update(['status' => 'offline']);
                $healthError = "Agent returned HTTP {$response->status()}.";
            }
        } catch (\Throwable $e) {
            $node->update(['status' => 'offline']);
            $healthError = $e->getMessage();
        }

        return Inertia::render('Admin/Nodes/Show', [
            'node'   => $node->fresh(),
            'health' => $health,
            'healthError' => $healthError,
            'certificate' => $this->inspectCertificate($node),
            'publicTls' => $this->inspectPublicTls($node),
            'panelVersion' => config('strata.version'),
        ]);
    }

    public function upgradeAgent(Request $request, Node $node): RedirectResponse
    {
        $data = $request->validate([
            'source_type' => ['nullable', 'in:version,branch'],
            'source_value' => ['nullable', 'string', 'max:100'],
        ]);

        if ($node->status !== 'online') {
            return back()->with('error', 'The node must be online before an agent upgrade can be started.');
        }

        $sourceType = $data['source_type'] ?? 'version';
        $sourceValue = trim((string) ($data['source_value'] ?? ''));

        if ($sourceValue === '') {
            $sourceValue = $sourceType === 'branch'
                ? 'main'
                : (string) config('strata.version', '');
        }

        if ($sourceValue === '') {
            return back()->with('error', 'A target version could not be determined for this agent upgrade.');
        }

        $downloadUrl = $sourceType === 'branch'
            ? "https://github.com/jonathjan0397/strata-hosting-panel/archive/refs/heads/{$sourceValue}.tar.gz"
            : "https://github.com/jonathjan0397/strata-hosting-panel/archive/refs/tags/{$sourceValue}.tar.gz";

        $response = AgentClient::for($node)->upgradeAgent($sourceValue, $downloadUrl);

        if (! $response->successful()) {
            return back()->with('error', $response->body() ?: 'The node agent upgrade could not be started.');
        }

        $node->update([
            'status' => 'upgrading',
            'target_agent_version' => $sourceValue,
            'agent_upgrade_started_at' => now(),
        ]);

        $logPath = storage_path('logs/strata-remote-agents-upgrade.log');
        File::ensureDirectoryExists(dirname($logPath));
        File::append(
            $logPath,
            sprintf(
                "[%s] Admin %s started single-node agent upgrade: node=%s source=%s %s\n",
                now()->toDateTimeString(),
                $request->user()->email,
                $node->hostname,
                $sourceType,
                $sourceValue,
            )
        );

        AuditLog::record('node.agent_upgrade.started', $node, [
            'source_type' => $sourceType,
            'source_value' => $sourceValue,
        ]);

        return back()->with('success', "Agent upgrade started for {$node->name}.");
    }

    public function renewCertificate(Node $node): RedirectResponse
    {
        $response = AgentClient::for($node)->renewAgentCertificate($node->hostname);

        if (! $response->successful()) {
            return back()->with('error', $response->body() ?: 'Certificate renewal could not be started.');
        }

        AuditLog::record('node.certificate_renewal_started', $node);

        return back()->with('success', "Certificate renewal started for {$node->hostname}. Refresh this page in a minute to verify the result.");
    }

    public function repairPublicHttps(Node $node): RedirectResponse
    {
        if (! $node->is_primary) {
            return back()->with('error', 'Public HTTPS repair is only available on the primary node.');
        }

        $panelDomain = $this->panelDomain();
        if (! $panelDomain) {
            return back()->with('error', 'Panel domain could not be determined from APP_URL.');
        }

        $panelResponse = AgentClient::for($node)->repairManagedCertificate($panelDomain, 'panel');
        if (! $panelResponse->successful()) {
            return back()->with('error', $panelResponse->body() ?: 'Panel HTTPS repair could not be started.');
        }

        $messages = ["Panel HTTPS repair started for {$panelDomain}."];
        $auditContext = ['panel_domain' => $panelDomain];

        $apexDomain = $this->apexPlaceholderDomain($node);
        if ($apexDomain) {
            $apexResponse = AgentClient::for($node)->repairManagedCertificate($apexDomain, 'apex_placeholder');
            if (! $apexResponse->successful()) {
                return back()->with(
                    'error',
                    "Panel certificate repair started, but apex placeholder repair failed: " . ($apexResponse->body() ?: 'request rejected.')
                );
            }

            $messages[] = "Apex placeholder HTTPS repair started for {$apexDomain}.";
            $auditContext['apex_domain'] = $apexDomain;
        }

        AuditLog::record('node.public_https_repair_started', $node, $auditContext);

        return back()->with('success', implode(' ', $messages) . ' Refresh this page in a minute to verify the result.');
    }

    public function destroy(Node $node): RedirectResponse
    {
        $dependencies = [
            'accounts' => $node->accounts()->count(),
            'domains' => Domain::where('node_id', $node->id)->count(),
            'mailboxes' => EmailAccount::where('node_id', $node->id)->count(),
            'forwarders' => EmailForwarder::where('node_id', $node->id)->count(),
            'dns_zones' => DnsZone::where('node_id', $node->id)->count(),
            'ftp_accounts' => FtpAccount::where('node_id', $node->id)->count(),
            'databases' => HostingDatabase::where('node_id', $node->id)->count(),
            'database_grants' => DatabaseGrant::where('node_id', $node->id)->count(),
            'backups' => BackupJob::where('node_id', $node->id)->count(),
            'app_installations' => AppInstallation::where('node_id', $node->id)->count(),
        ];

        if (array_sum($dependencies) > 0) {
            return back()->with('error', 'Remove or migrate all resources from this node before deleting it.');
        }

        try {
            $node->delete();
        } catch (QueryException) {
            return back()->with('error', 'Node deletion is blocked by existing related records.');
        }

        AuditLog::record('node.deleted', $node);

        return redirect()->route('admin.nodes.index')
            ->with('success', 'Node removed.');
    }

    private function inspectCertificate(Node $node): array
    {
        $host = $node->hostname ?: $node->ip_address;
        $port = $node->port ?: 8743;

        return $this->inspectTlsEndpoint($host, $port, 'Certificate is self-signed. Renew it before relying on remote operations.');
    }

    private function inspectPublicTls(Node $node): array
    {
        $panelDomain = $this->panelDomain();
        $apexDomain = $this->apexPlaceholderDomain($node);

        return [
            'panel_domain' => $panelDomain,
            'panel' => $panelDomain
                ? $this->inspectTlsEndpoint($panelDomain, 443, 'Certificate is self-signed. Public HTTPS will show browser trust warnings until it is repaired.')
                : ['status' => 'unknown', 'message' => 'Panel domain could not be determined from APP_URL.'],
            'apex_domain' => $apexDomain,
            'apex' => $apexDomain
                ? $this->inspectTlsEndpoint($apexDomain, 443, 'Certificate is self-signed. Apex placeholder HTTPS will show browser trust warnings until it is repaired.')
                : null,
        ];
    }

    private function inspectTlsEndpoint(string $host, int $port, string $selfSignedMessage): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errorNumber = 0;
        $errorString = '';
        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errorNumber,
            $errorString,
            8,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! $client) {
            return [
                'status' => 'unreachable',
                'message' => trim($errorString) ?: 'Certificate could not be inspected.',
            ];
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $cert) {
            return [
                'status' => 'unknown',
                'message' => 'No peer certificate was returned.',
            ];
        }

        $parsed = openssl_x509_parse($cert) ?: [];
        $fingerprint = strtoupper(openssl_x509_fingerprint($cert, 'sha256') ?: '');
        $subject = $this->formatCertificateName($parsed['subject'] ?? []);
        $issuer = $this->formatCertificateName($parsed['issuer'] ?? []);
        $expiresAt = isset($parsed['validTo_time_t'])
            ? CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t'])
            : null;
        $dnsNames = $this->certificateDnsNames($parsed);
        $matchesHost = $this->certificateMatchesHost($host, $dnsNames, $parsed['subject']['CN'] ?? null);
        $isSelfSigned = $subject !== '' && $issuer !== '' && $subject === $issuer;
        $expiresSoon = $expiresAt ? $expiresAt->lte(now()->addDays(14)) : true;
        $expired = $expiresAt ? $expiresAt->isPast() : false;

        $status = 'valid';
        $message = 'Certificate is trusted by hostname metadata.';
        if ($expired) {
            $status = 'expired';
            $message = 'Certificate is expired.';
        } elseif ($isSelfSigned) {
            $status = 'self_signed';
            $message = $selfSignedMessage;
        } elseif (! $matchesHost) {
            $status = 'hostname_mismatch';
            $message = 'Certificate does not match the requested hostname.';
        } elseif ($expiresSoon) {
            $status = 'expires_soon';
            $message = 'Certificate expires within 14 days.';
        }

        return [
            'status' => $status,
            'message' => $message,
            'subject' => $subject,
            'issuer' => $issuer,
            'expires_at' => $expiresAt?->toIso8601String(),
            'expires_human' => $expiresAt?->toDayDateTimeString(),
            'fingerprint' => $fingerprint,
            'dns_names' => $dnsNames,
            'matches_host' => $matchesHost,
            'is_self_signed' => $isSelfSigned,
        ];
    }

    private function formatCertificateName(array $name): string
    {
        return collect($name)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode(', ');
    }

    private function certificateDnsNames(array $parsed): array
    {
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if ($san === '') {
            return [];
        }

        return collect(explode(',', $san))
            ->map(fn ($entry) => trim($entry))
            ->filter(fn ($entry) => str_starts_with($entry, 'DNS:'))
            ->map(fn ($entry) => substr($entry, 4))
            ->values()
            ->all();
    }

    private function certificateMatchesHost(string $host, array $dnsNames, ?string $commonName): bool
    {
        $names = $dnsNames ?: array_filter([$commonName]);

        foreach ($names as $name) {
            if (strcasecmp($name, $host) === 0) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                if (str_ends_with($host, $suffix) && substr_count($host, '.') === substr_count($suffix, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function panelDomain(): ?string
    {
        $host = parse_url((string) Config::get('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function apexPlaceholderDomain(Node $node): ?string
    {
        $panelDomain = $this->panelDomain();
        $hostname = strtolower(trim((string) $node->hostname, '. '));
        if ($panelDomain === null || $hostname === '') {
            return null;
        }

        $parent = $this->parentDomain($hostname);

        return $parent !== '' && $parent !== $panelDomain ? $parent : null;
    }

    private function parentDomain(string $hostname): string
    {
        $parts = array_values(array_filter(explode('.', strtolower(trim($hostname, '. ')))));

        if (count($parts) < 2) {
            return '';
        }

        return implode('.', array_slice($parts, -2));
    }
}
