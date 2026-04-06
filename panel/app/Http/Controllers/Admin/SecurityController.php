<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    // ── Fail2ban ──────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Security/Index', [
            'nodes' => $nodes,
        ]);
    }

    public function fail2banStatus(Request $request): JsonResponse
    {
        $node     = Node::findOrFail($request->query('node_id'));
        $response = AgentClient::for($node)->fail2banStatus();

        return response()->json(
            $response->successful() ? $response->json() : ['jails' => [], 'error' => $response->body()],
            $response->successful() ? 200 : 502
        );
    }

    public function unban(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'jail'    => ['required', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'ip'      => ['required', 'ip'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->fail2banUnban($data['jail'], $data['ip']);

        if (! $response->successful()) {
            return response()->json(['message' => 'Unban failed: ' . $response->body()], 502);
        }

        AuditLog::record('security.unban', null, ['jail' => $data['jail'], 'ip' => $data['ip'], 'node' => $node->name]);

        return response()->json(['status' => 'ok', 'message' => "{$data['ip']} unbanned from {$data['jail']}."]);
    }

    // ── Firewall (UFW) ────────────────────────────────────────────────────────

    public function firewallIndex(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Security/Firewall', [
            'nodes' => $nodes,
        ]);
    }

    public function firewallRules(Request $request): JsonResponse
    {
        $node     = Node::findOrFail($request->query('node_id'));
        $response = AgentClient::for($node)->firewallRules();

        return response()->json(
            $response->successful() ? $response->json() : ['status' => 'unknown', 'rules' => [], 'error' => $response->body()],
            $response->successful() ? 200 : 502
        );
    }

    public function firewallAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'type'    => ['required', 'in:allow,deny'],
            'port'    => ['required', 'string', 'regex:/^\d{1,5}(:\d{1,5})?$/'],
            'proto'   => ['nullable', 'in:tcp,udp'],
            'from'    => ['nullable', 'ip'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->firewallAddRule(
            $data['type'],
            $data['port'],
            $data['proto'] ?? '',
            $data['from'] ?? '',
        );

        if ($response->successful()) {
            AuditLog::record('firewall.add', null, [
                'node' => $node->name,
                'type' => $data['type'],
                'port' => $data['port'],
                'from' => $data['from'] ?? 'any',
            ]);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'Firewall update failed: ' . $response->body()], 502);
        }

        return response()->json($response->json(), 201);
    }

    public function firewallDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'number'  => ['required', 'integer', 'min:1'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->firewallDeleteRule((int) $data['number']);

        if ($response->successful()) {
            AuditLog::record('firewall.delete', null, [
                'node'        => $node->name,
                'rule_number' => $data['number'],
            ]);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'Firewall delete failed: ' . $response->body()], 502);
        }

        return response()->json($response->json(), 200);
    }
}
