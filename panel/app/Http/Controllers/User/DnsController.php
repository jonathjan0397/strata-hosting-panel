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
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    private function account()
    {
        return auth()->user()->account()->firstOrFail();
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
}
