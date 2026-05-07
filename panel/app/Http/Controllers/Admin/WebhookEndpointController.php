<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookEndpointController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Webhooks/Index', [
            'endpoints' => WebhookEndpoint::latest()->get()->map(fn (WebhookEndpoint $endpoint) => [
                'id' => $endpoint->id,
                'name' => $endpoint->name,
                'url' => $endpoint->url,
                'events' => $endpoint->events,
                'active' => $endpoint->active,
                'last_status' => $endpoint->last_status,
                'last_error' => $endpoint->last_error,
                'last_delivery_at' => $endpoint->last_delivery_at?->toDateTimeString(),
                'created_at' => $endpoint->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048'],
            'secret' => ['nullable', 'string', 'max:255'],
            'events' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
        ]);

        $endpoint = WebhookEndpoint::create([
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $data['secret'] ?? null,
            'events' => $this->parseEvents($data['events'] ?? ''),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        AuditLog::record('webhook.created', $endpoint, ['name' => $endpoint->name, 'url' => $endpoint->url]);

        return back()->with('success', 'Webhook endpoint created.');
    }

    public function update(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'secret' => ['nullable', 'string', 'max:255'],
            'secret_action' => ['required', Rule::in(['keep', 'replace', 'clear'])],
        ]);

        $updates = ['active' => $data['active']];

        if ($data['secret_action'] === 'replace') {
            $updates['secret'] = $data['secret'] ?: null;
        } elseif ($data['secret_action'] === 'clear') {
            $updates['secret'] = null;
        }

        $webhook->update($updates);

        AuditLog::record('webhook.updated', $webhook, ['name' => $webhook->name, 'active' => $webhook->active]);

        return back()->with('success', 'Webhook endpoint updated.');
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        AuditLog::record('webhook.deleted', $webhook, ['name' => $webhook->name, 'url' => $webhook->url]);

        $webhook->delete();

        return back()->with('success', 'Webhook endpoint deleted.');
    }

    private function parseEvents(string $events): ?array
    {
        $parsed = collect(preg_split('/[\s,]+/', $events) ?: [])
            ->map(fn (string $event) => trim($event))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $parsed === [] ? null : $parsed;
    }
}
