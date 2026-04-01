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

class NodeStatusController extends Controller
{
    public function show(Node $node): Response
    {
        return Inertia::render('Admin/Nodes/Status', [
            'node' => $node->only('id', 'name', 'hostname', 'ip_address', 'port', 'status', 'agent_version', 'is_primary', 'last_seen_at'),
        ]);
    }

    /**
     * Live system info — called by Vue polling every 30s.
     */
    public function info(Node $node): JsonResponse
    {
        $client = AgentClient::for($node);

        try {
            $infoRes     = $client->systemInfo();
            $servicesRes = $client->services();

            if ($infoRes->successful()) {
                $node->update(['status' => 'online', 'last_seen_at' => now()]);
            } else {
                $node->update(['status' => 'offline']);
            }

            return response()->json([
                'info'     => $infoRes->successful() ? $infoRes->json() : null,
                'services' => $servicesRes->successful() ? $servicesRes->json() : [],
                'error'    => $infoRes->successful() ? null : 'Agent unreachable',
            ]);
        } catch (\Throwable $e) {
            $node->update(['status' => 'offline']);
            return response()->json(['error' => 'Agent unreachable: ' . $e->getMessage()], 502);
        }
    }

    /**
     * Log viewer — returns last N lines of an allowlisted log.
     */
    public function logs(Node $node, string $service): JsonResponse
    {
        $lines = min((int) request()->query('lines', 200), 500);

        try {
            $res = AgentClient::for($node)->logs($service, $lines);

            if ($res->status() === 400) {
                return response()->json(['error' => 'Unknown log service'], 400);
            }

            return response()->json($res->json());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Service control — start/stop/restart/reload.
     */
    public function serviceAction(Request $request, Node $node, string $service): JsonResponse
    {
        $action = $request->validate([
            'action' => ['required', 'in:start,stop,restart,reload'],
        ])['action'];

        try {
            $res = match ($action) {
                'start'   => AgentClient::for($node)->startService($service),
                'stop'    => AgentClient::for($node)->stopService($service),
                'restart' => AgentClient::for($node)->restartService($service),
                'reload'  => AgentClient::for($node)->reloadService($service),
            };

            AuditLog::record("service.{$action}", $node, [
                'service' => $service,
                'node'    => $node->name,
            ]);

            return response()->json($res->json(), $res->status());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
