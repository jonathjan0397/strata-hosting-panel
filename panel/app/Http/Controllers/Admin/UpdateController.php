<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;

class UpdateController extends Controller
{
    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Updates/Index', [
            'nodes' => $nodes,
            'panel' => [
                'version' => config('strata.version'),
                'upgrade_script' => File::exists('/root/strata-upgrade.sh'),
                'log_path' => storage_path('logs/strata-panel-upgrade.log'),
                'default_source_type' => 'branch',
                'default_source_value' => 'main',
            ],
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

    public function panelUpgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type' => ['required', 'in:branch,version'],
            'source_value' => ['required', 'string', 'max:100'],
        ]);

        if (! File::exists('/root/strata-upgrade.sh')) {
            return response()->json([
                'status' => 'error',
                'message' => 'The panel upgrade utility is not installed on this server.',
            ], 500);
        }

        $sourceFlag = $data['source_type'] === 'version' ? '--version' : '--branch';
        $sourceValue = trim($data['source_value']);
        $logPath = storage_path('logs/strata-panel-upgrade.log');

        File::ensureDirectoryExists(dirname($logPath));
        File::append(
            $logPath,
            sprintf(
                "[%s] Admin %s started panel upgrade: %s %s\n",
                now()->toDateTimeString(),
                $request->user()->email,
                $sourceFlag,
                $sourceValue
            )
        );

        $command = sprintf(
            "nohup /root/strata-upgrade.sh %s %s >> %s 2>&1 < /dev/null &",
            escapeshellarg($sourceFlag),
            escapeshellarg($sourceValue),
            escapeshellarg($logPath)
        );

        Process::fromShellCommandline('/bin/bash -lc ' . escapeshellarg($command))->run();

        AuditLog::record('panel.upgrade.started', null, [
            'source_type' => $data['source_type'],
            'source_value' => $sourceValue,
        ]);

        return response()->json([
            'status' => 'started',
            'message' => 'Panel upgrade started. The panel may be briefly unavailable while services restart.',
            'log_path' => $logPath,
        ]);
    }
}
