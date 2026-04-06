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

class UpdateController extends Controller
{
    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Updates/Index', [
            'nodes' => $nodes,
        ]);
    }

    public function available(Request $request): JsonResponse
    {
        $node     = Node::findOrFail($request->query('node_id'));
        $response = AgentClient::for($node)->updatesAvailable();

        return response()->json(
            $response->successful()
                ? $response->json()
                : ['count' => 0, 'packages' => [], 'error' => $response->body()],
            $response->successful() ? 200 : 502
        );
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->updatesApply();

        if ($response->successful()) {
            AuditLog::record('system.updates.applied', null, ['node' => $node->name]);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = [
                'status' => 'error',
                'output' => $response->body(),
            ];
        }

        return response()->json($payload, $response->status());
    }
}
