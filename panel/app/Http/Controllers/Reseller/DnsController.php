<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    public function show(Request $request, Domain $domain): Response
    {
        $this->authorizeDomain($request, $domain);

        $domain->load(['account.user', 'node']);
        $provisioner = new DnsProvisioner(AgentClient::for($domain->node));
        $zone = $provisioner->zoneForDomain($domain);
        $records = $zone?->records()->orderBy('type')->orderBy('name')->get() ?? collect();

        return Inertia::render('Reseller/Dns/ZoneShow', [
            'domain' => $domain,
            'zone' => $zone,
            'records' => $this->presentRecords($domain, $records, $provisioner),
        ]);
    }

    public function storeRecord(Request $request, DnsZone $zone): RedirectResponse
    {
        $this->authorizeDomain($request, $zone->domain);

        $data = $this->validateRecordPayload($request);
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

    public function updateRecord(Request $request, DnsRecord $record): RedirectResponse
    {
        $this->authorizeDomain($request, $record->zone->domain);

        $data = $this->validateRecordPayload($request, false);
        $zone = $record->zone;
        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));

        [$success, $error] = $provisioner->addRecord(
            $zone,
            $record->name,
            $record->type,
            $data['ttl'],
            [$data['value']],
            $record->managed,
            $data['priority'] ?? null,
        );

        return $success
            ? back()->with('success', "{$record->type} record updated.")
            : back()->with('error', "Failed to update record: {$error}");
    }

    public function restoreRecord(Request $request, DnsRecord $record): RedirectResponse
    {
        $this->authorizeDomain($request, $record->zone->domain);

        if (! $record->managed) {
            return back()->with('error', 'Only managed records can be restored to the domain default.');
        }

        $zone = $record->zone;
        $domain = $zone->domain;
        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));
        [$success, $error] = $provisioner->restoreManagedRecord($domain, $record);

        return $success
            ? back()->with('success', "{$record->type} record restored to the domain default.")
            : back()->with('error', "Failed to restore record: {$error}");
    }

    public function destroyRecord(Request $request, DnsRecord $record): RedirectResponse
    {
        $this->authorizeDomain($request, $record->zone->domain);

        $zone = $record->zone;
        $provisioner = new DnsProvisioner(AgentClient::for($zone->node));
        [$success, $error] = $provisioner->deleteRecord($zone, $record->name, $record->type);

        return $success
            ? back()->with('success', 'Record deleted.')
            : back()->with('error', "Failed to delete record: {$error}");
    }

    private function authorizeDomain(Request $request, Domain $domain): void
    {
        $reseller = $request->user();
        $owns = $reseller->resellerClients()->where('id', $domain->account->user_id)->exists();
        abort_unless($owns, 403);
    }

    private function validateRecordPayload(Request $request, bool $includeNameAndType = true): array
    {
        $rules = [
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'value' => ['required', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];

        if ($includeNameAndType) {
            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'in:A,AAAA,CNAME,MX,TXT,SRV,CAA,NS'],
                ...$rules,
            ];
        }

        return $request->validate($rules);
    }

    private function presentRecords(Domain $domain, $records, DnsProvisioner $provisioner)
    {
        return $records->map(fn (DnsRecord $record) => [
            ...$record->toArray(),
            'can_restore_default' => $record->managed
                && $provisioner->defaultDefinitionForRecord($domain, $record->name, $record->type) !== null,
        ]);
    }
}
