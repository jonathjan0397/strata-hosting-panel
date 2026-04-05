<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    /**
     * DNS zones index — list all domains with their zone status.
     */
    public function index(Request $request): Response
    {
        $query = Domain::with(['account.user', 'dnsZone' => fn ($q) => $q->withCount('records')])
            ->orderBy('domain');

        if ($search = $request->query('search')) {
            $query->where('domain', 'like', "%{$search}%");
        }

        return Inertia::render('Admin/Dns/Index', [
            'domains' => $query->paginate(50)->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * DNS zone management page for a domain.
     */
    public function show(Domain $domain): Response
    {
        $domain->load(['account', 'node']);

        $zone    = DnsZone::with('records')->where('domain_id', $domain->id)->first();
        $records = $zone?->records()->orderBy('type')->orderBy('name')->get() ?? collect();

        return Inertia::render('Admin/Dns/ZoneShow', [
            'domain'  => $domain,
            'zone'    => $zone,
            'records' => $records,
        ]);
    }

    /**
     * Provision a new DNS zone on the node for this domain.
     */
    public function provision(Domain $domain): RedirectResponse
    {
        $existing = DnsZone::where('domain_id', $domain->id)->first();
        if ($existing) {
            return back()->with('error', 'A DNS zone already exists for this domain.');
        }

        $client    = AgentClient::for($domain->node);
        $provisioner = new DnsProvisioner($client);

        [$success, $error] = $provisioner->createZone($domain);

        AuditLog::record('dns.zone_created', $domain, ['domain' => $domain->domain, 'success' => $success]);

        return $success
            ? redirect()->route('admin.dns.show', $domain)
                ->with('success', "DNS zone created for {$domain->domain}.")
            : back()->with('error', "DNS provisioning failed: {$error}");
    }

    /**
     * Export DNS zone as a BIND-compatible zone file download.
     */
    public function export(Domain $domain): HttpResponse
    {
        $zone    = DnsZone::with('records')->where('domain_id', $domain->id)->firstOrFail();
        $records = $zone->records()->orderByRaw("FIELD(type,'SOA','NS','A','AAAA','MX','CNAME','TXT','SRV','CAA')")->get();

        $serial = date('Ymd') . '01';
        $fqdn   = rtrim($domain->domain, '.') . '.';

        $lines   = [];
        $lines[] = "; Zone file for {$domain->domain}";
        $lines[] = "; Exported " . now()->toDateTimeString();
        $lines[] = '';
        $lines[] = "\$ORIGIN {$fqdn}";
        $lines[] = "\$TTL 3600";
        $lines[] = '';

        foreach ($records as $r) {
            $name  = $r->name === $domain->domain ? '@' : rtrim(str_replace('.' . $domain->domain, '', $r->name), '.');
            $value = $r->value;
            if ($r->type === 'MX' && $r->priority !== null) {
                $value = "{$r->priority} {$value}";
            }
            $lines[] = "{$name}\t{$r->ttl}\tIN\t{$r->type}\t{$value}";
        }

        $content  = implode("\n", $lines) . "\n";
        $filename = $domain->domain . '.zone';

        return response($content, 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Add or replace a DNS record.
     */
    public function storeRecord(Request $request, DnsZone $zone): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'type'     => ['required', 'in:A,AAAA,CNAME,MX,TXT,SRV,CAA,NS'],
            'ttl'      => ['required', 'integer', 'min:60', 'max:86400'],
            'value'    => ['required', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $domain = $zone->domain;
        $client = AgentClient::for($zone->node);
        $provisioner = new DnsProvisioner($client);

        [$success, $error] = $provisioner->addRecord(
            $zone,
            $data['name'],
            $data['type'],
            $data['ttl'],
            [$data['value']],
        );

        AuditLog::record('dns.record_added', $domain, [
            'name' => $data['name'], 'type' => $data['type'],
        ]);

        return $success
            ? back()->with('success', "{$data['type']} record added.")
            : back()->with('error', "Failed to add record: {$error}");
    }

    /**
     * Delete a DNS record.
     */
    public function destroyRecord(DnsRecord $record): RedirectResponse
    {
        $zone   = $record->zone;
        $client = AgentClient::for($zone->node);
        $provisioner = new DnsProvisioner($client);

        [$success, $error] = $provisioner->deleteRecord($zone, $record->name, $record->type);

        AuditLog::record('dns.record_deleted', $zone->domain, [
            'name' => $record->name, 'type' => $record->type,
        ]);

        return $success
            ? back()->with('success', 'Record deleted.')
            : back()->with('error', "Failed to delete record: {$error}");
    }

    public function import(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate([
            'zone_text' => ['required', 'string', 'max:65536'],
        ]);

        $zone = DnsZone::where('domain_id', $domain->id)->firstOrFail();
        $records = $this->parseZoneText($data['zone_text'], $domain->domain);

        if (empty($records)) {
            return back()->with('error', 'No importable records found in zone file.');
        }

        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));
        $imported = 0;
        foreach ($records as $rec) {
            [$ok] = $provisioner->addRecord($zone, $rec['name'], $rec['type'], $rec['ttl'], [$rec['value']]);
            if ($ok) $imported++;
        }

        AuditLog::record('dns.import', $domain->account, ['domain' => $domain->domain, 'imported' => $imported]);

        return back()->with('success', "{$imported} DNS record(s) imported.");
    }

    private function parseZoneText(string $text, string $zoneName): array
    {
        $records    = [];
        $origin     = rtrim($zoneName, '.') . '.';
        $defaultTtl = 3600;
        $skipTypes  = ['SOA', 'NS'];
        $allowTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/;.*$/', '', $line));
            if ($line === '') continue;

            if (preg_match('/^\$ORIGIN\s+(\S+)/i', $line, $m)) {
                $origin = $m[1];
                continue;
            }
            if (preg_match('/^\$TTL\s+(\d+)/i', $line, $m)) {
                $defaultTtl = (int) $m[1];
                continue;
            }

            // name [ttl] [IN] type value
            if (! preg_match('/^(\S+)\s+(?:(\d+)\s+)?(?:IN\s+)?(\S+)\s+(.+)$/i', $line, $m)) {
                continue;
            }
            [, $rawName, $ttl, $type, $value] = $m;
            $type = strtoupper($type);

            if (in_array($type, $skipTypes) || ! in_array($type, $allowTypes)) continue;

            $name = $rawName === '@' ? rtrim($zoneName, '.') : rtrim(str_replace('@', $zoneName, $rawName), '.');
            if (! str_contains($name, '.')) {
                $name = $name . '.' . rtrim($zoneName, '.');
            }

            $value = trim($value, '"');
            if ($type === 'MX' && preg_match('/^(\d+)\s+(.+)$/', $value, $mx)) {
                $value = $mx[1] . ' ' . rtrim($mx[2], '.');
            }

            $records[] = [
                'name'  => $name,
                'type'  => $type,
                'ttl'   => $ttl ? (int) $ttl : $defaultTtl,
                'value' => $value,
            ];
        }

        return $records;
    }
}
