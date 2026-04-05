<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Security/Index', [
            'nodes' => $nodes,
        ]);
    }

    public function fail2banStatus(Request $request): JsonResponse
    {
        $node = Node::findOrFail($request->query('node_id'));
        $response = AgentClient::for($node)->fail2banStatus();

        return response()->json(
            $response->successful() ? $response->json() : ['jails' => [], 'error' => $response->body()],
            $response->successful() ? 200 : 502
        );
    }

    public function unban(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'jail'    => ['required', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'ip'      => ['required', 'ip'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->fail2banUnban($data['jail'], $data['ip']);

        if (! $response->successful()) {
            return back()->with('error', 'Unban failed: ' . $response->body());
        }

        AuditLog::record('security.unban', null, ['jail' => $data['jail'], 'ip' => $data['ip'], 'node' => $node->name]);

        return back()->with('success', "{$data['ip']} unbanned from {$data['jail']}.");
    }
}
