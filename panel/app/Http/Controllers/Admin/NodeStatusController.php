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
            'node' => $node->only('id', 'name', 'hostname', 'ip_address', 'port', 'status', 'agent_version', 'target_agent_version', 'agent_upgrade_started_at', 'is_primary', 'last_seen_at'),
        ]);
    }

    /**
     * Live system info — called by Vue polling every 30s.
     */
    public function info(Node $node): JsonResponse
    {
        $client = AgentClient::for($node);

        try {
            $infoRes = $client->systemInfo();
            $servicesRes = $client->services();
            $reportedVersion = null;

            if ($infoRes->successful()) {
                try {
                    $versionRes = $client->version();
                    if ($versionRes->successful()) {
                        $reportedVersion = $this->normalizeAgentVersion($versionRes->json('version'));
                    }
                } catch (\Throwable) {
                    // Do not fail node status if the version endpoint is missing or unhealthy.
                }

                $updates = ['last_seen_at' => now()];
                if ($reportedVersion !== null) {
                    $updates['agent_version'] = $reportedVersion;
                }

                if ($node->target_agent_version) {
                    if ($reportedVersion !== null && $reportedVersion === $node->target_agent_version) {
                        $updates['status'] = 'online';
                        $updates['target_agent_version'] = null;
                        $updates['agent_upgrade_started_at'] = null;
                    } else {
                        $updates['status'] = 'upgrading';
                    }
                } else {
                    $updates['status'] = 'online';
                }

                $node->update($updates);
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

    private function normalizeAgentVersion(?string $version): ?string
    {
        $value = trim((string) $version);

        if ($value === '' || strtolower($value) === 'dev') {
            return null;
        }

        return $value;
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

            if ($res->successful()) {
                AuditLog::record("service.{$action}", $node, [
                    'service' => $service,
                    'node'    => $node->name,
                ]);
            }

            return response()->json($res->json(), $res->status());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
