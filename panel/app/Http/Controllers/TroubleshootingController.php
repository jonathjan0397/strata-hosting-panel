<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TroubleshootingController extends Controller
{
    public function adminIndex(Request $request): Response
    {
        return $this->renderIndex($request, 'admin', 'Admin');
    }

    public function resellerIndex(Request $request): Response
    {
        return $this->renderIndex($request, 'reseller', 'Reseller');
    }

    public function userIndex(Request $request): Response
    {
        return $this->renderIndex($request, 'user', 'Account');
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer'],
        ]);
        $scope = $this->scopeKey($request);

        $domain = $this->scopedDomains($request)
            ->with(['node', 'dnsZone.records', 'account.user'])
            ->whereKey($data['domain_id'])
            ->firstOrFail();

        $mailHost = 'mail.' . $domain->domain;
        $serverIp = $domain->server_ip ?: $domain->node?->ip_address ?: '';

        return response()->json([
            'domain' => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'account_id' => $domain->account_id,
                'account_username' => $domain->account?->username,
                'mail_enabled' => (bool) $domain->mail_enabled,
                'managed_dns' => $domain->dnsZone !== null,
                'ssl_enabled' => (bool) $domain->ssl_enabled,
                'ssl_provider' => $domain->ssl_provider,
                'ssl_expires_at' => $domain->ssl_expires_at,
            ],
            'sections' => [
                'dns' => $this->dnsChecks($domain, $mailHost, $serverIp, $scope),
                'mail' => $this->mailChecks($domain, $mailHost, $serverIp, $scope),
                'certificates' => $this->certificateChecks($domain, $mailHost, $scope),
            ],
            'email_dns' => $this->emailDnsState($domain),
        ]);
    }

    private function renderIndex(Request $request, string $scope, string $scopeLabel): Response
    {
        $domains = $this->scopedDomains($request)
            ->with(['account.user'])
            ->orderBy('domain')
            ->get()
            ->map(fn (Domain $domain) => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'mail_enabled' => (bool) $domain->mail_enabled,
                'ssl_enabled' => (bool) $domain->ssl_enabled,
                'account' => [
                    'username' => $domain->account?->username,
                    'owner' => $domain->account?->user?->email,
                ],
            ]);

        return Inertia::render('Troubleshooting/Index', [
            'domains' => $domains,
            'scope' => $scope,
            'scopeLabel' => $scopeLabel,
        ]);
    }

    private function scopedDomains(Request $request): Builder
    {
        $user = $request->user();
        $query = Domain::query();

        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isReseller()) {
            return $query->whereHas('account', fn (Builder $accountQuery) => $accountQuery->where('reseller_id', $user->id));
        }

        $account = $user->account()->firstOrFail();

        return $query->where('account_id', $account->id);
    }

    private function scopeKey(Request $request): string
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return 'admin';
        }

        if ($user->isReseller()) {
            return 'reseller';
        }

        return 'user';
    }

    private function dnsChecks(Domain $domain, string $mailHost, string $serverIp, string $scope): array
    {
        $checks = [];
        $apexA = @dns_get_record($domain->domain, DNS_A | DNS_AAAA) ?: [];
        $ns = @dns_get_record($domain->domain, DNS_NS) ?: [];
        $mx = @dns_get_record($domain->domain, DNS_MX) ?: [];
        $spf = $this->findTxtRecord($domain->domain, 'v=spf1');
        $dmarc = $this->findTxtRecord('_dmarc.' . $domain->domain, 'v=DMARC1');
        $dkim = $this->findTxtRecord('default._domainkey.' . $domain->domain, 'v=DKIM1');

        $checks[] = $this->resultCheck(
            'apex-address',
            'DNS Issues',
            'Apex web address',
            empty($apexA) ? 'fail' : 'pass',
            empty($apexA)
                ? 'No A or AAAA record was found for the website domain.'
                : 'The website domain resolves publicly.',
            empty($apexA)
                ? "Add an A record for {$domain->domain} pointing to the web server IP."
                : null,
            array_map(fn (array $record) => ($record['type'] ?? 'A') . ': ' . ($record['ipv6'] ?? $record['ip'] ?? ''), $apexA),
            empty($apexA) ? $this->dnsZoneAction($domain, $scope) : null
        );

        $checks[] = $this->resultCheck(
            'nameservers',
            'DNS Issues',
            'Nameserver delegation',
            count($ns) >= 2 ? 'pass' : 'warning',
            count($ns) >= 2
                ? count($ns) . ' authoritative nameserver records found.'
                : 'Less than two NS records were found for this domain.',
            count($ns) >= 2
                ? null
                : 'Publish at least two authoritative nameserver records and ensure the registrar delegation matches them.',
            array_map(fn (array $record) => $record['target'] ?? '', $ns),
            count($ns) >= 2 ? null : $this->dnsZoneAction($domain, $scope)
        );

        if ($domain->mail_enabled) {
            $checks[] = $this->resultCheck(
                'mx',
                'DNS Issues',
                'Mail exchanger',
                empty($mx) ? 'fail' : 'pass',
                empty($mx)
                    ? 'No MX record was found for this mail-enabled domain.'
                    : count($mx) . ' MX record(s) found.',
                empty($mx)
                    ? "Add an MX record for {$domain->domain} pointing to {$mailHost} with priority 10."
                    : null,
                array_map(fn (array $record) => 'Priority ' . ($record['pri'] ?? 0) . ': ' . ($record['target'] ?? ''), $mx),
                empty($mx) ? $this->mailDomainAction($domain, $scope) : null
            );

            $checks[] = $this->resultCheck(
                'spf',
                'DNS Issues',
                'SPF record',
                $spf ? 'pass' : 'fail',
                $spf ? 'An SPF record is published.' : 'No SPF record was found.',
                $spf ? null : "Publish a TXT record on the root domain, for example: v=spf1 a mx ip4:{$serverIp} -all",
                $spf ? [$spf] : [],
                $spf ? null : $this->restoreSpfAction($domain, $scope)
            );

            $checks[] = $this->resultCheck(
                'dkim',
                'DNS Issues',
                'DKIM record',
                $dkim ? 'pass' : 'fail',
                $dkim ? 'The default DKIM selector is published.' : 'The default DKIM selector is missing.',
                $dkim ? null : 'Open the domain email settings and publish the default._domainkey TXT record shown there.',
                $dkim ? [$dkim] : [],
                $dkim ? null : $this->regenerateDkimAction($domain, $scope)
            );

            $checks[] = $this->resultCheck(
                'dmarc',
                'DNS Issues',
                'DMARC policy',
                $dmarc ? (str_contains(strtolower($dmarc), 'p=none') ? 'warning' : 'pass') : 'fail',
                ! $dmarc
                    ? 'No DMARC record was found.'
                    : (str_contains(strtolower($dmarc), 'p=none')
                        ? 'DMARC exists, but the policy is p=none.'
                        : 'DMARC is published with an enforcement policy.'),
                ! $dmarc
                    ? "Publish a TXT record at _dmarc.{$domain->domain}, for example: v=DMARC1; p=quarantine; pct=100; rua=mailto:postmaster@{$domain->domain}"
                    : (str_contains(strtolower($dmarc), 'p=none')
                        ? 'Raise the DMARC policy to p=quarantine or p=reject once SPF and DKIM are working cleanly.'
                        : null),
                $dmarc ? [$dmarc] : [],
                (! $dmarc || str_contains(strtolower((string) $dmarc), 'p=none')) ? $this->restoreDmarcAction($domain, $scope) : null
            );
        } else {
            $checks[] = $this->resultCheck(
                'mail-disabled',
                'DNS Issues',
                'Mail DNS defaults',
                'warning',
                'Mail is not enabled for this domain, so MX, SPF, DKIM, and DMARC are not being enforced yet.',
                'Enable mail for this domain if it should receive or send mail through Strata.',
                [],
                $this->enableMailAction($domain, $scope)
            );
        }

        return $checks;
    }

    private function mailChecks(Domain $domain, string $mailHost, string $serverIp, string $scope): array
    {
        $checks = [];
        $mailRecords = @dns_get_record($mailHost, DNS_A | DNS_AAAA) ?: [];

        if (! $domain->mail_enabled) {
            return [
                $this->resultCheck(
                    'mail-disabled',
                    'Mail Server Issues',
                    'Mail service',
                    'warning',
                    'Mail is disabled for this domain, so SMTP connectivity checks are informational only.',
                    'Enable mail for this domain before troubleshooting inbound or outbound email.',
                    [],
                    $this->enableMailAction($domain, $scope)
                ),
            ];
        }

        $checks[] = $this->resultCheck(
            'mail-host',
            'Mail Server Issues',
            'Mail hostname resolution',
            empty($mailRecords) ? 'fail' : 'pass',
            empty($mailRecords)
                ? "The mail host {$mailHost} does not resolve publicly."
                : "{$mailHost} resolves publicly.",
            empty($mailRecords)
                ? "Publish an A record for {$mailHost} pointing to the mail server IP."
                : null,
            array_map(fn (array $record) => ($record['type'] ?? 'A') . ': ' . ($record['ipv6'] ?? $record['ip'] ?? ''), $mailRecords),
            empty($mailRecords) ? $this->dnsZoneAction($domain, $scope) : null
        );

        foreach ([25 => 'SMTP 25', 465 => 'SMTPS 465', 587 => 'Submission 587'] as $port => $label) {
            $portCheck = $this->canConnect($mailHost, $port);
            $checks[] = $this->resultCheck(
                'port-' . $port,
                'Mail Server Issues',
                $label,
                $portCheck['ok'] ? 'pass' : 'fail',
                $portCheck['ok']
                    ? "{$mailHost}:{$port} accepted a TCP connection."
                    : "{$mailHost}:{$port} could not be reached: {$portCheck['message']}",
                $portCheck['ok']
                    ? null
                    : "Confirm that Postfix is listening on {$port}, the firewall allows it, and the provider path is not filtering it.",
                $portCheck['ok'] ? [$label . ' reachable'] : [$portCheck['message']],
                $portCheck['ok'] ? null : $this->mailDomainAction($domain, $scope)
            );
        }

        $ptr = $serverIp !== '' ? @gethostbyaddr($serverIp) : false;

        $checks[] = $this->resultCheck(
            'ptr',
            'Mail Server Issues',
            'Reverse DNS',
            ($ptr && $ptr !== $serverIp) ? 'pass' : 'warning',
            ($ptr && $ptr !== $serverIp)
                ? "PTR resolves to {$ptr}."
                : 'No usable PTR record was found for the public mail IP.',
            ($ptr && $ptr !== $serverIp)
                ? null
                : "Ask the server provider to point the reverse DNS for {$serverIp} at {$mailHost}.",
            ($ptr && $ptr !== $serverIp) ? [$ptr] : [],
            ($ptr && $ptr !== $serverIp) ? null : $this->mailDomainAction($domain, $scope)
        );

        return $checks;
    }

    private function certificateChecks(Domain $domain, string $mailHost, string $scope): array
    {
        $checks = [];
        $websiteCertificate = $this->inspectTlsEndpoint($domain->domain, 443);
        $mailCertificate = $domain->mail_enabled ? $this->inspectTlsEndpoint($mailHost, 465) : null;

        $websiteStatus = ! $domain->ssl_enabled
            ? 'fail'
            : ($this->expiresSoon($domain->ssl_expires_at) ? 'warning' : 'pass');

        $websiteDetail = ! $domain->ssl_enabled
            ? 'No managed website certificate is recorded for this domain.'
            : 'Website certificate is enabled' . ($domain->ssl_provider ? " via {$domain->ssl_provider}" : '') . '.';

        if ($domain->ssl_enabled && $domain->ssl_provider === 'letsencrypt') {
            $websiteDetail .= $this->expiresSoon($domain->ssl_expires_at)
                ? ' Auto-renew should happen before expiry, but this certificate is inside the warning window.'
                : ' Auto-renew should happen before expiry while DNS continues pointing to this server.';
        }

        $checks[] = $this->resultCheck(
            'web-cert',
            'Certificate Issues',
            'Website TLS certificate',
            $websiteCertificate['status'] === 'fail' ? 'fail' : $websiteStatus,
            $websiteCertificate['status'] === 'fail'
                ? $websiteCertificate['detail']
                : $websiteDetail,
            $websiteCertificate['status'] === 'fail'
                ? 'Verify that the domain resolves to the correct server and reissue the website certificate.'
                : ($websiteStatus === 'warning'
                    ? 'Review automatic renewal, confirm the domain still resolves to this server, and renew if needed.'
                    : null),
            array_filter([
                $domain->ssl_expires_at ? 'Expires: ' . $domain->ssl_expires_at : null,
                $websiteCertificate['issuer'] ? 'Issuer: ' . $websiteCertificate['issuer'] : null,
                $websiteCertificate['subject'] ? 'Subject: ' . $websiteCertificate['subject'] : null,
            ]),
            ($websiteCertificate['status'] === 'fail' || $websiteStatus === 'warning') ? $this->issueSslAction($domain, $scope) : null
        );

        if (! $domain->mail_enabled) {
            $checks[] = $this->resultCheck(
                'mail-cert-disabled',
                'Certificate Issues',
                'Mail TLS certificate',
                'warning',
                'Mail is disabled for this domain, so no mail-host TLS certificate check was performed.',
                'Enable mail if this domain should serve IMAP, POP3, or SMTP through Strata.',
                [],
                $this->enableMailAction($domain, $scope)
            );

            return $checks;
        }

        $mailStatus = $mailCertificate['status'];
        $mailDetail = $mailCertificate['detail'];
        if ($mailStatus === 'pass' && $mailCertificate['expires_at']) {
            $mailDetail = $this->expiresSoon($mailCertificate['expires_at'])
                ? 'Mail TLS is valid, but the certificate expires soon. Auto-renew should happen before expiry.'
                : 'Mail TLS is valid and should auto-renew before expiry while the mail hostname remains publicly reachable.';
            $mailStatus = $this->expiresSoon($mailCertificate['expires_at']) ? 'warning' : 'pass';
        }

        $checks[] = $this->resultCheck(
            'mail-cert',
            'Certificate Issues',
            'Mail TLS certificate',
            $mailStatus,
            $mailDetail,
            $mailStatus === 'fail'
                ? "Verify that {$mailHost} resolves publicly and reissue the mail certificate."
                : ($mailStatus === 'warning'
                    ? 'Confirm HTTP validation for the mail host still works so the certificate can renew before expiry.'
                    : null),
            array_filter([
                $mailCertificate['expires_at'] ? 'Expires: ' . $mailCertificate['expires_at'] : null,
                $mailCertificate['issuer'] ? 'Issuer: ' . $mailCertificate['issuer'] : null,
                $mailCertificate['subject'] ? 'Subject: ' . $mailCertificate['subject'] : null,
            ]),
            ($mailStatus === 'fail' || $mailStatus === 'warning') ? $this->mailDomainAction($domain, $scope) : null
        );

        return $checks;
    }

    private function emailDnsState(Domain $domain): array
    {
        $domain->loadMissing('dnsZone.records', 'node');

        return [
            'managed_dns' => $domain->dnsZone !== null,
            'dkim' => [
                'selector' => 'default',
                'host' => 'default._domainkey',
                'fqdn' => "default._domainkey.{$domain->domain}",
                'type' => 'TXT',
                'value' => $domain->dkim_dns_record,
                'published' => $this->managedRecordContains($domain, 'default._domainkey', 'TXT', $domain->dkim_dns_record),
            ],
            'spf' => [
                'host' => '@',
                'fqdn' => $domain->domain,
                'type' => 'TXT',
                'value' => $domain->spf_dns_record,
                'published' => $this->managedRecordContains($domain, '@', 'TXT', $domain->spf_dns_record),
            ],
            'dmarc' => [
                'host' => '_dmarc',
                'fqdn' => "_dmarc.{$domain->domain}",
                'type' => 'TXT',
                'value' => $domain->dmarc_dns_record,
                'published' => $this->managedRecordContains($domain, '_dmarc', 'TXT', $domain->dmarc_dns_record),
            ],
        ];
    }

    private function managedRecordContains(Domain $domain, string $name, string $type, ?string $value): bool
    {
        if (! $domain->dnsZone || ! $value) {
            return false;
        }

        $record = $domain->dnsZone->records
            ->first(fn ($record) => $record->name === $name && $record->type === $type);

        if (! $record) {
            return false;
        }

        $values = preg_split('/\R/', (string) $record->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(trim($value), array_map('trim', $values), true);
    }

    private function findTxtRecord(string $host, string $prefix): ?string
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        foreach ($records as $record) {
            $value = $record['txt'] ?? '';
            if (str_starts_with(strtolower($value), strtolower($prefix))) {
                return $value;
            }
        }

        return null;
    }

    private function canConnect(string $host, int $port): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 6);

        if (is_resource($socket)) {
            fclose($socket);

            return ['ok' => true, 'message' => 'connected'];
        }

        return [
            'ok' => false,
            'message' => trim($errstr) !== '' ? trim($errstr) : 'connection failed',
        ];
    }

    private function inspectTlsEndpoint(string $host, int $port): array
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

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errorCode,
            $errorString,
            8,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! is_resource($client)) {
            return [
                'status' => 'fail',
                'detail' => trim($errorString) !== '' ? trim($errorString) : 'TLS endpoint could not be reached.',
                'subject' => null,
                'issuer' => null,
                'expires_at' => null,
            ];
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $certificate) {
            return [
                'status' => 'fail',
                'detail' => 'No certificate was presented by the endpoint.',
                'subject' => null,
                'issuer' => null,
                'expires_at' => null,
            ];
        }

        $parsed = @openssl_x509_parse($certificate) ?: [];
        $subject = $parsed['subject']['CN'] ?? null;
        $issuer = $parsed['issuer']['CN'] ?? null;
        $expiresAt = isset($parsed['validTo_time_t'])
            ? Carbon::createFromTimestampUTC((int) $parsed['validTo_time_t'])->toDateTimeString()
            : null;

        $status = 'pass';
        $detail = 'Certificate is reachable and readable.';

        if ($subject && ! $this->certificateMatchesHostname($parsed, $host)) {
            $status = 'fail';
            $detail = 'Certificate hostname does not match the requested host.';
        } elseif ($expiresAt && $this->expiresSoon($expiresAt)) {
            $status = 'warning';
            $detail = 'Certificate is reachable but expires within 14 days.';
        }

        return [
            'status' => $status,
            'detail' => $detail,
            'subject' => $subject,
            'issuer' => $issuer,
            'expires_at' => $expiresAt,
        ];
    }

    private function certificateMatchesHostname(array $parsed, string $host): bool
    {
        $names = [];

        if (isset($parsed['subject']['CN'])) {
            $names[] = strtolower((string) $parsed['subject']['CN']);
        }

        $extensions = $parsed['extensions']['subjectAltName'] ?? '';
        foreach (explode(',', (string) $extensions) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        $host = strtolower($host);

        foreach (array_unique($names) as $name) {
            if ($name === $host) {
                return true;
            }

            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                if (str_ends_with($host, $suffix) && substr_count($host, '.') >= substr_count($suffix, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function expiresSoon(null|string $date): bool
    {
        if (! $date) {
            return false;
        }

        return Carbon::parse($date)->lte(now()->addDays(14));
    }

    private function resultCheck(
        string $key,
        string $section,
        string $label,
        string $status,
        string $detail,
        ?string $fix = null,
        array $data = [],
        ?array $action = null,
    ): array {
        return compact('key', 'section', 'label', 'status', 'detail', 'fix', 'data', 'action');
    }

    private function dnsZoneAction(Domain $domain, string $scope): ?array
    {
        return match ($scope) {
            'admin' => $domain->dnsZone
                ? $this->action('Open DNS Zone', route('admin.dns.show', $domain), 'get')
                : $this->action('Provision DNS Zone', route('admin.dns.provision', $domain), 'post'),
            'user' => $domain->dnsZone
                ? $this->action('Open DNS Zone', route('my.dns.show', $domain), 'get')
                : null,
            'reseller' => $this->resellerClientAction($domain, 'Open Client Details'),
            default => null,
        };
    }

    private function mailDomainAction(Domain $domain, string $scope): ?array
    {
        return match ($scope) {
            'admin' => $this->action('Open Email Settings', route('admin.email.domain', $domain), 'get'),
            'user' => $this->action('Open Email Settings', route('my.email.domain', $domain), 'get'),
            'reseller' => $this->resellerImpersonateAction($domain),
            default => null,
        };
    }

    private function enableMailAction(Domain $domain, string $scope): ?array
    {
        return match ($scope) {
            'admin' => $this->action('Enable Mail', route('admin.email.enable', $domain), 'post'),
            default => $this->mailDomainAction($domain, $scope),
        };
    }

    private function regenerateDkimAction(Domain $domain, string $scope): ?array
    {
        return match ($scope) {
            'admin' => $this->action('Regenerate DKIM', route('admin.email.domain-key.regenerate', $domain), 'post'),
            'user' => $this->action('Regenerate DKIM', route('my.email.domain-key.regenerate', $domain), 'post'),
            default => $this->mailDomainAction($domain, $scope),
        };
    }

    private function restoreSpfAction(Domain $domain, string $scope): ?array
    {
        return match ($scope) {
            'admin' => $this->action('Restore SPF', route('admin.email.spf.restore', $domain), 'post'),
            'user' => $this->action('Restore SPF', route('my.email.spf.restore', $domain), 'post'),
            default => $this->mailDomainAction($domain, $scope),
        };
    }

    private function restoreDmarcAction(Domain $domain, string $scope): ?array
    {
        return match ($scope) {
            'admin' => $this->action('Restore DMARC', route('admin.email.dmarc.restore', $domain), 'post'),
            'user' => $this->action('Restore DMARC', route('my.email.dmarc.restore', $domain), 'post'),
            default => $this->mailDomainAction($domain, $scope),
        };
    }

    private function issueSslAction(Domain $domain, string $scope): ?array
    {
        return match ($scope) {
            'admin' => $this->action('Reissue SSL', route('admin.domains.ssl', $domain), 'post'),
            'user' => $this->action('Reissue SSL', route('my.domains.ssl', $domain), 'post'),
            'reseller' => $this->resellerImpersonateAction($domain),
            default => null,
        };
    }

    private function resellerClientAction(Domain $domain, string $label = 'Open Client Details'): ?array
    {
        if (! $domain->account_id) {
            return null;
        }

        return $this->action($label, route('reseller.clients.show', $domain->account_id), 'get');
    }

    private function resellerImpersonateAction(Domain $domain): ?array
    {
        if (! $domain->account_id) {
            return null;
        }

        return $this->action('Access Client Panel', route('reseller.accounts.impersonate', $domain->account_id), 'post');
    }

    private function action(string $label, string $href, string $method = 'get'): array
    {
        return compact('label', 'href', 'method');
    }
}
