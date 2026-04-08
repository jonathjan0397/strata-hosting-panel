<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Node;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class StandaloneDnsController extends Controller
{
    /**
     * List all standalone DNS zones (not tied to a hosting account/domain).
     * Merges DB records with live PowerDNS zones fetched from each online node.
     */
    public function index(): Response
    {
        $dbZones = DnsZone::query()
            ->with(['node:id,name', 'domain.account.user'])
            ->withCount('records')
            ->orderBy('zone_name')
            ->get()
            ->keyBy('zone_name');

        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname', 'is_primary')->get();

        // Collect live zones from each node's agent; de-duplicate by name.
        $liveZones = collect();
        $cluster = collect();
        foreach ($nodes as $node) {
            $nodeZones = 0;
            $nodeError = null;

            try {
                $response = AgentClient::for($node)->listDnsZones();
                if ($response->successful()) {
                    $zoneRows = $response->json() ?? [];
                    $nodeZones = count($zoneRows);

                    foreach ($zoneRows as $z) {
                        $name = rtrim(strtolower($z['name'] ?? ''), '.');
                        if ($name && ! $liveZones->has($name)) {
                            $liveZones->put($name, [
                                'zone_name' => $name,
                                'node'      => $node->name,
                                'node_id'   => $node->id,
                                'live'      => true,
                            ]);
                        }
                    }
                } else {
                    $nodeError = trim($response->body()) ?: "HTTP {$response->status()}";
                }
            } catch (\Throwable $e) {
                $nodeError = $e->getMessage();
                // Node unreachable — skip silently.
            }
            $cluster->push([
                'id'         => $node->id,
                'name'       => $node->name,
                'hostname'   => $node->hostname,
                'is_primary' => $node->is_primary,
                'live_zones' => $nodeZones,
                'status'     => $nodeError ? 'error' : 'ok',
                'error'      => $nodeError,
            ]);
        }

        // Merge: DB zones enriched with live flag; live-only zones have no DB id.
        $merged = $liveZones->map(function ($live) use ($dbZones) {
            $db = $dbZones->get($live['zone_name']);
            return [
                'id'            => $db?->id,
                'zone_name'     => $live['zone_name'],
                'node'          => $live['node'],
                'node_id'       => $live['node_id'],
                'records_count' => $db?->records_count ?? 0,
                'active'        => $db?->active ?? true,
                'live'          => true,
                'type'          => $db?->domain_id ? 'Hosted' : 'Standalone',
                'owner'         => $this->zoneOwnerLabel($db),
            ];
        });

        // Add DB zones not found in live list (e.g. node offline).
        foreach ($dbZones as $name => $db) {
            if (! $merged->has($name)) {
                $merged->put($name, [
                    'id'            => $db->id,
                    'zone_name'     => $db->zone_name,
                    'node'          => $db->node?->name,
                    'node_id'       => $db->node_id,
                    'records_count' => $db->records_count,
                    'active'        => $db->active,
                    'live'          => false,
                    'type'          => $db->domain_id ? 'Hosted' : 'Standalone',
                    'owner'         => $this->zoneOwnerLabel($db),
                ]);
            }
        }

        return Inertia::render('Admin/Dns/ServerZones', [
            'zones' => $merged->values()->sortBy('zone_name')->values(),
            'nodes' => $nodes->map->only('id', 'name'),
            'cluster' => $cluster,
        ]);
    }

    public function syncBackupZones(): RedirectResponse
    {
        $exitCode = Artisan::call('dns:sync-backup-zones');
        $output = trim(Artisan::output());

        return $exitCode === 0
            ? back()->with('success', $output ?: 'Backup DNS sync completed.')
            : back()->with('error', $output ?: 'Backup DNS sync failed.');
    }

    /**
     * Create a new standalone DNS zone on a node.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'zone_name' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'node_id'   => ['required', 'exists:nodes,id'],
        ]);

        $zoneName = rtrim(strtolower($data['zone_name']), '.');

        if (DnsZone::where('zone_name', $zoneName)->exists()) {
            return back()->with('error', "Zone {$zoneName} already exists.");
        }

        $node   = Node::findOrFail($data['node_id']);
        $client = AgentClient::for($node);

        $response = $client->createDnsZone($zoneName);
        if (! $response->successful()) {
            return back()->with('error', 'Agent error: ' . $response->body());
        }

        $zone = DnsZone::create([
            'domain_id'  => null,
            'account_id' => null,
            'node_id'    => $node->id,
            'zone_name'  => $zoneName,
            'active'     => true,
        ]);

        AuditLog::record('dns.standalone_zone_created', null, ['zone' => $zoneName, 'node' => $node->name]);

        return redirect()->route('admin.dns.server.show', $zone)
            ->with('success', "Zone {$zoneName} created.");
    }

    /**
     * Manage records for a standalone zone.
     */
    public function show(DnsZone $zone): Response
    {
        $zone->load(['node', 'domain.account.user']);

        $records = $zone->records()->orderBy('type')->orderBy('name')->get();

        return Inertia::render('Admin/Dns/ServerZoneShow', [
            'zone'    => [
                ...$zone->only('id', 'zone_name', 'node_id', 'active', 'domain_id'),
                'type' => $zone->domain_id ? 'Hosted' : 'Standalone',
                'owner' => $this->zoneOwnerLabel($zone),
            ],
            'node'    => $zone->node?->only('id', 'name', 'hostname'),
            'records' => $records,
        ]);
    }

    /**
     * Add a record to a standalone zone.
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

        $client      = AgentClient::for($zone->node);
        $provisioner = new DnsProvisioner($client);

        [$success, $error] = $provisioner->addRecord(
            $zone, $data['name'], $data['type'], $data['ttl'], [$data['value']], false, $data['priority'] ?? null
        );

        if ($success) {
            AuditLog::record('dns.standalone_record_added', null, [
                'zone' => $zone->zone_name, 'name' => $data['name'], 'type' => $data['type'],
            ]);
        }

        return $success
            ? back()->with('success', "{$data['type']} record added.")
            : back()->with('error', "Failed: {$error}");
    }

    /**
     * Delete a record from a standalone zone.
     */
    public function destroyRecord(DnsRecord $record): RedirectResponse
    {
        $zone = $record->zone;

        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));
        [$success, $error] = $provisioner->deleteRecord($zone, $record->name, $record->type);

        if ($success) {
            AuditLog::record('dns.standalone_record_deleted', null, [
                'zone' => $zone->zone_name, 'name' => $record->name, 'type' => $record->type,
            ]);
        }

        return $success
            ? back()->with('success', 'Record deleted.')
            : back()->with('error', "Failed: {$error}");
    }

    /**
     * Delete a standalone zone entirely.
     */
    public function destroy(DnsZone $zone): RedirectResponse
    {
        if ($zone->domain_id !== null) {
            $zone->load('domain');
            [$success, $error] = (new DnsProvisioner(AgentClient::for($zone->node)))->deleteZone($zone->domain);

            if (! $success) {
                return back()->with('error', "Hosted zone deletion failed and the zone was kept: {$error}");
            }

            AuditLog::record('dns.hosted_zone_deleted', $zone->domain, ['zone' => $zone->zone_name]);

            return redirect()->route('admin.dns.server.index')
                ->with('success', "Zone {$zone->zone_name} deleted.");
        }

        try {
            $client   = AgentClient::for($zone->node);
            $response = $client->deleteDnsZone($zone->zone_name);
            if (! $response->successful()) {
                return back()->with('error', 'Agent error: ' . $response->body());
            }
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLog::record('dns.standalone_zone_deleted', null, ['zone' => $zone->zone_name]);
        $zone->delete();

        return redirect()->route('admin.dns.server.index')
            ->with('success', "Zone {$zone->zone_name} deleted.");
    }

    private function zoneOwnerLabel(?DnsZone $zone): ?string
    {
        if (! $zone?->domain_id) {
            return null;
        }

        $account = $zone->domain?->account;
        if (! $account) {
            return 'Hosted domain';
        }

        $email = $account->user?->email;

        return $email ? "{$account->username} ({$email})" : $account->username;
    }
}
