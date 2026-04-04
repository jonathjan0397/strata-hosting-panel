<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DeliverabilityController extends Controller
{
    // ── Page renders ─────────────────────────────────────────────────────────

    public function adminIndex(): Response
    {
        return Inertia::render('Admin/Deliverability', [
            'serverIp' => $this->serverIp(),
        ]);
    }

    public function userIndex(): Response
    {
        $user    = Auth::user();
        $account = $user->account;

        $domains = $account
            ? Domain::where('account_id', $account->id)->pluck('name')
            : collect();

        return Inertia::render('User/Deliverability', [
            'domains'  => $domains,
            'serverIp' => $this->serverIp(),
        ]);
    }

    // ── API: run all checks ───────────────────────────────────────────────────

    public function check(Request $request): JsonResponse
    {
        $data   = $request->validate(['domain' => 'required|string|max:253']);
        $domain = strtolower(trim($data['domain']));
        $ip     = $this->serverIp();

        return response()->json([
            'domain'    => $domain,
            'server_ip' => $ip,
            'checks'    => [
                'mx'         => $this->checkMX($domain),
                'spf'        => $this->checkSPF($domain, $ip),
                'dkim'       => $this->checkDKIM($domain),
                'dmarc'      => $this->checkDMARC($domain),
                'ptr'        => $this->checkPTR($ip, $domain),
                'blacklists' => $this->checkBlacklists($ip),
            ],
        ]);
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function checkMX(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX) ?: [];

        if (empty($records)) {
            return [
                'status' => 'fail',
                'label'  => 'MX Records',
                'detail' => 'No MX records found for this domain.',
                'fix'    => "Add an MX record in your DNS zone:\n\nType: MX\nName: @\nPriority: 10\nValue: mail.{$domain}",
                'data'   => [],
            ];
        }

        usort($records, fn($a, $b) => $a['pri'] <=> $b['pri']);

        $entries = array_map(fn($r) => "Priority {$r['pri']}: {$r['target']}", $records);

        return [
            'status' => 'pass',
            'label'  => 'MX Records',
            'detail' => count($records) . ' MX record(s) found.',
            'fix'    => null,
            'data'   => $entries,
        ];
    }

    private function checkSPF(string $domain, string $serverIp): array
    {
        $txtRecords = @dns_get_record($domain, DNS_TXT) ?: [];
        $spfRecords = array_filter($txtRecords, fn($r) => str_starts_with($r['txt'] ?? '', 'v=spf1'));

        if (count($spfRecords) === 0) {
            return [
                'status' => 'fail',
                'label'  => 'SPF Record',
                'detail' => 'No SPF record found.',
                'fix'    => "Add a TXT record to your DNS zone:\n\nType: TXT\nName: @\nValue: v=spf1 ip4:{$serverIp} ~all\n\nThis authorises your server IP to send email for this domain.",
                'data'   => [],
            ];
        }

        if (count($spfRecords) > 1) {
            return [
                'status' => 'fail',
                'label'  => 'SPF Record',
                'detail' => 'Multiple SPF records found — this is invalid (RFC 7208). Only one v=spf1 TXT record is allowed.',
                'fix'    => "Merge all SPF rules into a single TXT record:\n\nv=spf1 ip4:{$serverIp} ~all\n\nDelete the duplicate SPF records.",
                'data'   => array_values(array_column(array_values($spfRecords), 'txt')),
            ];
        }

        $spf     = reset($spfRecords)['txt'];
        $covered = $this->spfCoversIp($spf, $serverIp);

        if (! $covered) {
            return [
                'status' => 'warning',
                'label'  => 'SPF Record',
                'detail' => "SPF record exists but does not explicitly include the server IP ({$serverIp}).",
                'fix'    => "Add ip4:{$serverIp} to your SPF record, e.g.:\n\nv=spf1 ip4:{$serverIp} ~all",
                'data'   => [$spf],
            ];
        }

        return [
            'status' => 'pass',
            'label'  => 'SPF Record',
            'detail' => "SPF record found and covers server IP ({$serverIp}).",
            'fix'    => null,
            'data'   => [$spf],
        ];
    }

    private function checkDKIM(string $domain): array
    {
        $selectors = ['default', 'mail', 'strata', 'google', 'dkim'];
        $found     = [];

        foreach ($selectors as $sel) {
            $host    = "{$sel}._domainkey.{$domain}";
            $records = @dns_get_record($host, DNS_TXT) ?: [];
            foreach ($records as $r) {
                if (str_contains($r['txt'] ?? '', 'v=DKIM1')) {
                    $found[] = ['selector' => $sel, 'record' => $r['txt']];
                }
            }
        }

        if (empty($found)) {
            return [
                'status' => 'fail',
                'label'  => 'DKIM',
                'detail' => 'No DKIM TXT record found for common selectors (default, mail, strata).',
                'fix'    => "1. In the panel, go to your domain → Email → DKIM and copy the public key.\n\n2. Add a TXT record in your DNS zone:\n   Name: default._domainkey\n   Value: v=DKIM1; k=rsa; p=<your-public-key>\n\n3. If using an external DNS provider, make sure you are publishing the key they issued.",
                'data'   => [],
            ];
        }

        $details = array_map(fn($f) => "Selector: {$f['selector']}", $found);

        return [
            'status' => 'pass',
            'label'  => 'DKIM',
            'detail' => count($found) . ' DKIM record(s) published.',
            'fix'    => null,
            'data'   => $details,
        ];
    }

    private function checkDMARC(string $domain): array
    {
        $host    = "_dmarc.{$domain}";
        $records = @dns_get_record($host, DNS_TXT) ?: [];
        $dmarc   = null;

        foreach ($records as $r) {
            if (str_starts_with($r['txt'] ?? '', 'v=DMARC1')) {
                $dmarc = $r['txt'];
                break;
            }
        }

        if (! $dmarc) {
            return [
                'status' => 'warning',
                'label'  => 'DMARC',
                'detail' => 'No DMARC record found. Email is not rejected when SPF/DKIM fail.',
                'fix'    => "Add a TXT record to your DNS zone:\n\nType: TXT\nName: _dmarc\nValue: v=DMARC1; p=none; rua=mailto:dmarc-reports@{$domain}\n\nStart with p=none to monitor, then move to p=quarantine or p=reject once you're confident in your configuration.",
                'data'   => [],
            ];
        }

        // Parse policy
        preg_match('/p=(\w+)/i', $dmarc, $pm);
        $policy = strtolower($pm[1] ?? 'none');

        $policyStatus = match ($policy) {
            'reject'     => 'pass',
            'quarantine' => 'pass',
            'none'       => 'warning',
            default      => 'warning',
        };

        $policyNote = $policy === 'none'
            ? ' Policy is p=none — emails failing SPF/DKIM are not rejected. Consider upgrading to p=quarantine or p=reject.'
            : '';

        return [
            'status' => $policyStatus,
            'label'  => 'DMARC',
            'detail' => "DMARC record found. Policy: p={$policy}.{$policyNote}",
            'fix'    => $policy === 'none'
                ? "Update p=none to p=quarantine or p=reject once SPF and DKIM are fully working:\n\nv=DMARC1; p=quarantine; rua=mailto:dmarc-reports@{$domain}"
                : null,
            'data'   => [$dmarc],
        ];
    }

    private function checkPTR(string $ip, string $domain): array
    {
        if (! $ip) {
            return [
                'status' => 'warning',
                'label'  => 'Reverse DNS (PTR)',
                'detail' => 'Could not determine server IP to check PTR record.',
                'fix'    => null,
                'data'   => [],
            ];
        }

        $ptr = @gethostbyaddr($ip);

        if (! $ptr || $ptr === $ip) {
            return [
                'status' => 'fail',
                'label'  => 'Reverse DNS (PTR)',
                'detail' => "No PTR record found for {$ip}. Many receiving mail servers will reject or flag email from IPs without rDNS.",
                'fix'    => "Contact your hosting provider or VPS provider and request a PTR (reverse DNS) record for {$ip} pointing to your mail hostname (e.g. mail.{$domain}).\n\nThis is set at the IP/network level — it cannot be changed in your DNS zone.",
                'data'   => [],
            ];
        }

        // Forward-confirmed rDNS: does the PTR hostname resolve back to the same IP?
        $forward = @gethostbyname($ptr);
        $fcrdns  = $forward === $ip;

        if (! $fcrdns) {
            return [
                'status' => 'warning',
                'label'  => 'Reverse DNS (PTR)',
                'detail' => "PTR record found ({$ptr}) but does not forward-resolve back to {$ip} (got {$forward}). This may cause deliverability issues.",
                'fix'    => "Ensure that {$ptr} has an A record pointing to {$ip}. Check your DNS zone for that hostname.",
                'data'   => ["PTR: {$ip} → {$ptr}", "Forward: {$ptr} → {$forward}"],
            ];
        }

        return [
            'status' => 'pass',
            'label'  => 'Reverse DNS (PTR)',
            'detail' => "PTR record verified: {$ip} → {$ptr} (forward-confirmed).",
            'fix'    => null,
            'data'   => ["PTR: {$ip} → {$ptr}"],
        ];
    }

    private function checkBlacklists(string $ip): array
    {
        $rbls = [
            'zen.spamhaus.org'     => 'Spamhaus ZEN (SBL/XBL/PBL)',
            'bl.spamcop.net'       => 'SpamCop',
            'b.barracudacentral.org' => 'Barracuda',
            'dnsbl.sorbs.net'      => 'SORBS',
            'psbl.surriel.com'     => 'PSBL',
            'dnsbl-1.uceprotect.net' => 'UCEProtect L1',
        ];

        $listed   = [];
        $checked  = [];
        $reversed = implode('.', array_reverse(explode('.', $ip)));

        foreach ($rbls as $rbl => $name) {
            $query  = "{$reversed}.{$rbl}";
            $result = @dns_get_record($query, DNS_A);

            if (! empty($result)) {
                $listed[] = $name;
            }
            $checked[] = $name;
        }

        if (! empty($listed)) {
            return [
                'status' => 'fail',
                'label'  => 'Blacklist Check',
                'detail' => count($listed) . ' blacklist(s) list ' . $ip . ': ' . implode(', ', $listed),
                'fix'    => "Delist your IP from each list:\n\n" . implode("\n", array_filter(array_map(function ($name) use ($ip) {
                    return match (true) {
                        str_contains($name, 'Spamhaus')   => "• Spamhaus: https://www.spamhaus.org/lookup/{$ip}",
                        str_contains($name, 'SpamCop')    => "• SpamCop: https://www.spamcop.net/bl.shtml?{$ip}",
                        str_contains($name, 'Barracuda')  => "• Barracuda: https://www.barracudacentral.org/rbl/removal-request",
                        str_contains($name, 'SORBS')      => "• SORBS: http://www.sorbs.net/lookup.shtml",
                        default => null,
                    };
                }, $listed))),
                'data'   => array_map(fn($n) => "LISTED: {$n}", $listed),
            ];
        }

        return [
            'status' => 'pass',
            'label'  => 'Blacklist Check',
            'detail' => "IP ({$ip}) is not listed on " . count($checked) . ' checked blacklists.',
            'fix'    => null,
            'data'   => array_map(fn($n) => "Clean: {$n}", $checked),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Determine the server's outbound IP.
     * Prefer the primary node's IP address; fall back to server addr.
     */
    private function serverIp(): string
    {
        $node = Node::where('is_primary', true)->first();
        if ($node) {
            return $node->ip_address;
        }

        return $_SERVER['SERVER_ADDR'] ?? '';
    }

    /**
     * Very lightweight SPF IP coverage check.
     * Handles ip4: directives and a:host lookups. Does not recurse into
     * include: chains to keep this fast and dependency-free.
     */
    private function spfCoversIp(string $spf, string $serverIp): bool
    {
        // Direct ip4: match
        if (preg_match_all('/ip4:([^\s]+)/i', $spf, $m)) {
            foreach ($m[1] as $cidr) {
                if ($this->ipInCidr($serverIp, $cidr)) {
                    return true;
                }
            }
        }

        // a: or a (resolves A records of a hostname)
        if (preg_match_all('/(?:^|\s)a:([^\s\/]+)/i', $spf, $m)) {
            foreach ($m[1] as $host) {
                $resolved = @gethostbyname($host);
                if ($resolved === $serverIp) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);

        if ($ip_long === false || $subnet_long === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }
}
