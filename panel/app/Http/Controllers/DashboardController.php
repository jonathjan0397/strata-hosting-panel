<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Domain;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        $user = auth()->user();

        if ($user->isReseller()) {
            return redirect()->route('reseller.dashboard');
        }

        if (! $user->isAdmin()) {
            return redirect()->route('my.dashboard');
        }

        $nodes = Node::orderBy('is_primary', 'desc')
            ->orderBy('name')
            ->get(['id', 'name', 'hostname', 'status', 'agent_version', 'last_seen_at', 'is_primary']);

        $operationsNode = Node::where('is_primary', true)->first()
            ?? Node::orderBy('name')->first();

        $stats = [
            ['label' => 'Nodes',    'value' => Node::count(),    'color' => 'indigo'],
            ['label' => 'Accounts', 'value' => Account::count(), 'color' => 'emerald'],
            ['label' => 'Domains',  'value' => Domain::count(),  'color' => 'indigo'],
            ['label' => 'Suspended','value' => Account::where('status', 'suspended')->count(), 'color' => 'amber'],
        ];

        return Inertia::render('Dashboard', [
            'nodes' => $nodes,
            'stats' => $stats,
            'operations' => $this->operationsSnapshot($operationsNode),
        ]);
    }

    private function operationsSnapshot(?Node $node): array
    {
        if (! $node) {
            return [
                'node' => null,
                'info' => null,
                'error' => 'No primary node is configured.',
            ];
        }

        try {
            $response = AgentClient::for($node)->systemInfo();

            if (! $response->successful()) {
                return [
                    'node' => $node->only('id', 'name', 'hostname'),
                    'info' => null,
                    'error' => 'Primary node telemetry is unavailable.',
                ];
            }

            return [
                'node' => $node->only('id', 'name', 'hostname'),
                'info' => $response->json(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'node' => $node->only('id', 'name', 'hostname'),
                'info' => null,
                'error' => 'Primary node telemetry is unavailable: ' . $e->getMessage(),
            ];
        }
    }
}
