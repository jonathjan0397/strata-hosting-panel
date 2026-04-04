<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class NodeController extends Controller
{
    public function index(): Response
    {
        $nodes = Node::orderBy('is_primary', 'desc')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Nodes/Index', ['nodes' => $nodes]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Nodes/Create', [
            'webServers'   => ['nginx', 'apache'],
            'accelerators' => ['varnish', 'redis', 'memcached'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'hostname'     => ['required', 'string', 'max:253'],
            'ip_address'   => ['required', 'ip'],
            'port'         => ['nullable', 'integer', 'between:1,65535'],
            'web_server'   => ['required', 'in:nginx,apache'],
            'accelerators' => ['nullable', 'array'],
            'accelerators.*' => ['in:varnish,redis,memcached'],
        ]);

        $node = Node::create([
            ...$data,
            'port'         => $data['port'] ?? 8743,
            'node_id'      => Str::uuid()->toString(),
            'hmac_secret'  => Str::random(64),
            'status'       => 'unknown',
            'accelerators' => $data['accelerators'] ?? [],
        ]);

        AuditLog::record('node.created', $node);

        return redirect()->route('admin.nodes.show', $node)
            ->with('success', 'Node created. Use the credentials below to install the agent.');
    }

    public function show(Node $node): Response
    {
        $health = null;

        try {
            $response = AgentClient::for($node)->health();
            if ($response->successful()) {
                $health = $response->json();
                $node->update(['status' => 'online', 'last_seen_at' => now()]);
            } else {
                $node->update(['status' => 'offline']);
            }
        } catch (\Throwable) {
            $node->update(['status' => 'offline']);
        }

        return Inertia::render('Admin/Nodes/Show', [
            'node'   => $node->fresh(),
            'health' => $health,
        ]);
    }

    public function destroy(Node $node): RedirectResponse
    {
        AuditLog::record('node.deleted', $node);
        $node->delete();

        return redirect()->route('admin.nodes.index')
            ->with('success', 'Node removed.');
    }
}
