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
use Inertia\Inertia;
use Inertia\Response;

class StandaloneDnsController extends Controller
{
    /**
     * List all standalone DNS zones (not tied to a hosting account/domain).
     */
    public function index(): Response
    {
        $zones = DnsZone::whereNull('domain_id')
            ->with('node:id,name')
            ->withCount('records')
            ->orderBy('zone_name')
            ->get()
            ->map(fn ($z) => [
                'id'           => $z->id,
                'zone_name'    => $z->zone_name,
                'node'         => $z->node?->name,
                'node_id'      => $z->node_id,
                'records_count' => $z->records_count,
                'active'       => $z->active,
            ]);

        $nodes = Node::where('status', 'online')->select('id', 'name')->get();

        return Inertia::render('Admin/Dns/ServerZones', [
            'zones' => $zones,
            'nodes' => $nodes,
        ]);
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
        abort_if($zone->domain_id !== null, 404);

        $records = $zone->records()->orderBy('type')->orderBy('name')->get();

        return Inertia::render('Admin/Dns/ServerZoneShow', [
            'zone'    => $zone->only('id', 'zone_name', 'node_id', 'active'),
            'node'    => $zone->node?->only('id', 'name', 'hostname'),
            'records' => $records,
        ]);
    }

    /**
     * Add a record to a standalone zone.
     */
    public function storeRecord(Request $request, DnsZone $zone): RedirectResponse
    {
        abort_if($zone->domain_id !== null, 404);

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'type'  => ['required', 'in:A,AAAA,CNAME,MX,TXT,SRV,CAA,NS'],
            'ttl'   => ['required', 'integer', 'min:60', 'max:86400'],
            'value' => ['required', 'string', 'max:4096'],
        ]);

        $client      = AgentClient::for($zone->node);
        $provisioner = new DnsProvisioner($client);

        [$success, $error] = $provisioner->addRecord(
            $zone, $data['name'], $data['type'], $data['ttl'], [$data['value']]
        );

        AuditLog::record('dns.standalone_record_added', null, [
            'zone' => $zone->zone_name, 'name' => $data['name'], 'type' => $data['type'],
        ]);

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
        abort_if($zone->domain_id !== null, 404);

        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));
        [$success, $error] = $provisioner->deleteRecord($zone, $record->name, $record->type);

        AuditLog::record('dns.standalone_record_deleted', null, [
            'zone' => $zone->zone_name, 'name' => $record->name, 'type' => $record->type,
        ]);

        return $success
            ? back()->with('success', 'Record deleted.')
            : back()->with('error', "Failed: {$error}");
    }

    /**
     * Delete a standalone zone entirely.
     */
    public function destroy(DnsZone $zone): RedirectResponse
    {
        abort_if($zone->domain_id !== null, 404);

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
}
