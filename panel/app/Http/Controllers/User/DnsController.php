<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
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
    private function account()
    {
        return auth()->user()->account()->firstOrFail();
    }

    public function index(): Response
    {
        $account = $this->account();
        $domains = Domain::where('account_id', $account->id)
            ->with(['dnsZone' => fn ($q) => $q->withCount('records')])
            ->orderBy('domain')
            ->get();

        return Inertia::render('User/Dns/Index', [
            'domains' => $domains,
        ]);
    }

    public function show(Domain $domain): Response
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $domain->load('node');
        $zone    = DnsZone::with('records')->where('domain_id', $domain->id)->first();
        $records = $zone?->records()->orderBy('type')->orderBy('name')->get() ?? collect();

        return Inertia::render('User/Dns/ZoneShow', [
            'domain'  => $domain,
            'zone'    => $zone,
            'records' => $records,
        ]);
    }

    public function exportZone(Domain $domain): HttpResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $zone    = DnsZone::with('records')->where('domain_id', $domain->id)->firstOrFail();
        $records = $zone->records()->orderByRaw("FIELD(type,'SOA','NS','A','AAAA','MX','CNAME','TXT','SRV','CAA')")->get();

        $fqdn  = rtrim($domain->domain, '.') . '.';
        $lines = [
            "; Zone file for {$domain->domain}",
            "; Exported " . now()->toDateTimeString(),
            '',
            "\$ORIGIN {$fqdn}",
            "\$TTL 3600",
            '',
        ];

        foreach ($records as $r) {
            $name  = $r->name === $domain->domain ? '@' : rtrim(str_replace('.' . $domain->domain, '', $r->name), '.');
            $value = $r->type === 'MX' && $r->priority !== null ? "{$r->priority} {$r->value}" : $r->value;
            $lines[] = "{$name}\t{$r->ttl}\tIN\t{$r->type}\t{$value}";
        }

        $filename = $domain->domain . '.zone';

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function storeRecord(Request $request, DnsZone $zone): RedirectResponse
    {
        $account = $this->account();
        $domain  = $zone->domain;
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'type'     => ['required', 'in:A,AAAA,CNAME,MX,TXT,SRV,CAA,NS'],
            'ttl'      => ['required', 'integer', 'min:60', 'max:86400'],
            'value'    => ['required', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));

        [$success, $error] = $provisioner->addRecord(
            $zone,
            $data['name'],
            $data['type'],
            $data['ttl'],
            [$data['value']],
            false,
            $data['priority'] ?? null,
        );

        return $success
            ? back()->with('success', "{$data['type']} record added.")
            : back()->with('error', "Failed to add record: {$error}");
    }

    public function destroyRecord(DnsRecord $record): RedirectResponse
    {
        $account = $this->account();
        $zone    = $record->zone;
        abort_unless($zone->domain->account_id === $account->id, 403);

        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));
        [$success, $error] = $provisioner->deleteRecord($zone, $record->name, $record->type);

        return $success
            ? back()->with('success', 'Record deleted.')
            : back()->with('error', "Failed to delete record: {$error}");
    }

    public function importZone(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->account();
        abort_unless($domain->account_id === $account->id, 403);

        $data = $request->validate([
            'zone_text' => ['required', 'string', 'max:65536'],
        ]);

        $zone = DnsZone::where('domain_id', $domain->id)->firstOrFail();
        $domain->load('node');
        $records = $this->parseZoneText($data['zone_text'], $domain->domain);

        if (empty($records)) {
            return back()->with('error', 'No importable records found in zone file.');
        }

        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));
        $imported = 0;
        foreach ($records as $rec) {
            [$ok] = $provisioner->addRecord($zone, $rec['name'], $rec['type'], $rec['ttl'], [$rec['value']], false, $rec['priority'] ?? null);
            if ($ok) $imported++;
        }

        return back()->with('success', "{$imported} DNS record(s) imported.");
    }

    private function parseZoneText(string $text, string $zoneName): array
    {
        $records    = [];
        $defaultTtl = 3600;
        $skipTypes  = ['SOA', 'NS'];
        $allowTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/;.*$/', '', $line));
            if ($line === '') continue;

            if (preg_match('/^\$TTL\s+(\d+)/i', $line, $m)) {
                $defaultTtl = (int) $m[1];
                continue;
            }
            if (preg_match('/^\$ORIGIN/i', $line)) continue;

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
            $priority = null;
            if ($type === 'MX' && preg_match('/^(\d+)\s+(.+)$/', $value, $mx)) {
                $priority = (int) $mx[1];
                $value = rtrim($mx[2], '.');
            }

            $records[] = [
                'name'     => $name,
                'type'     => $type,
                'ttl'      => $ttl ? (int) $ttl : $defaultTtl,
                'value'    => $value,
                'priority' => $priority,
            ];
        }

        return $records;
    }
}
