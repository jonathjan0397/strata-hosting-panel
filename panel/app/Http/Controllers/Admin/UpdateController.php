<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Models\SystemSetting;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;

class UpdateController extends Controller
{
    private const SUPPORTED_CHANNELS = [
        ['value' => 'main', 'label' => 'Main', 'description' => 'Latest supported integration branch.'],
        ['value' => 'latest-untested', 'label' => 'Latest-Untested', 'description' => 'Newer branch for early validation before normal release use.'],
        ['value' => 'experimental', 'label' => 'Experimental', 'description' => 'High-risk branch for active experiments and unfinished work.'],
    ];

    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Updates/Index', [
            'nodes' => $nodes,
            'panel' => [
                'version' => config('strata.version'),
                'upgrade_script' => File::exists('/root/strata-upgrade.sh'),
                'log_path' => storage_path('logs/strata-panel-upgrade.log'),
                'default_source_type' => 'channel',
                'default_source_value' => 'main',
                'channels' => self::SUPPORTED_CHANNELS,
                'auto_remote_agents' => SystemSetting::getValue('updates.auto_remote_agents', '0') === '1',
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

    public function panelSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_remote_agents' => ['required', 'boolean'],
        ]);

        SystemSetting::putValue('updates.auto_remote_agents', $data['auto_remote_agents']);

        AuditLog::record('panel.upgrade.settings_updated', null, [
            'auto_remote_agents' => $data['auto_remote_agents'],
        ]);

        return response()->json([
            'status' => 'saved',
            'message' => $data['auto_remote_agents']
                ? 'Automatic remote node agent upgrades are enabled.'
                : 'Automatic remote node agent upgrades are disabled. Upgrades will stay manual until re-enabled.',
        ]);
    }

    public function panelUpgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type' => ['required', 'in:channel,branch,version'],
            'source_value' => ['required', 'string', 'max:100'],
        ]);

        if (! File::exists('/root/strata-upgrade.sh')) {
            return response()->json([
                'status' => 'error',
                'message' => 'The panel upgrade utility is not installed on this server.',
            ], 500);
        }

        $sourceValue = trim($data['source_value']);
        [$sourceFlag, $normalizedValue] = $this->resolveUpgradeSource($data['source_type'], $sourceValue);
        $logPath = storage_path('logs/strata-panel-upgrade.log');

        File::ensureDirectoryExists(dirname($logPath));
        File::append(
            $logPath,
            sprintf(
                "[%s] Admin %s started panel upgrade: %s %s (auto_remote_agents=%s)\n",
                now()->toDateTimeString(),
                $request->user()->email,
                $sourceFlag,
                $normalizedValue,
                SystemSetting::getValue('updates.auto_remote_agents', '0')
            )
        );

        $autoRemoteAgents = SystemSetting::getValue('updates.auto_remote_agents', '0') === '1';
        $command = sprintf(
            "nohup /root/strata-upgrade.sh %s %s%s >> %s 2>&1 < /dev/null &",
            escapeshellarg($sourceFlag),
            escapeshellarg($normalizedValue),
            $autoRemoteAgents ? '' : ' --skip-remote-agents',
            escapeshellarg($logPath)
        );

        Process::fromShellCommandline('/bin/bash -lc ' . escapeshellarg($command))->run();

        AuditLog::record('panel.upgrade.started', null, [
            'source_type' => $data['source_type'],
            'source_value' => $normalizedValue,
        ]);

        return response()->json([
            'status' => 'started',
            'message' => 'Panel upgrade started. The panel may be briefly unavailable while services restart.',
            'log_path' => $logPath,
        ]);
    }

    public function remoteAgentsUpgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type' => ['required', 'in:channel,branch,version'],
            'source_value' => ['required', 'string', 'max:100'],
        ]);

        $sourceValue = trim($data['source_value']);
        $logPath = storage_path('logs/strata-remote-agents-upgrade.log');
        $phpBinary = File::exists('/usr/bin/php8.4') ? '/usr/bin/php8.4' : PHP_BINARY;
        [$flag, $normalizedValue] = $this->resolveRemoteAgentUpgradeSource($data['source_type'], $sourceValue);

        File::ensureDirectoryExists(dirname($logPath));
        File::append(
            $logPath,
            sprintf(
                "[%s] Admin %s started remote agent upgrade: %s %s\n",
                now()->toDateTimeString(),
                $request->user()->email,
                $flag,
                $normalizedValue
            )
        );

        $command = sprintf(
            "cd /opt/strata-panel/panel && nohup %s artisan strata:nodes-upgrade-agents %s=%s >> %s 2>&1 < /dev/null &",
            escapeshellarg($phpBinary),
            $flag,
            escapeshellarg($normalizedValue),
            escapeshellarg($logPath)
        );

        Process::fromShellCommandline('/bin/bash -lc ' . escapeshellarg($command))->run();

        AuditLog::record('panel.remote_agents_upgrade.started', null, [
            'source_type' => $data['source_type'],
            'source_value' => $normalizedValue,
        ]);

        return response()->json([
            'status' => 'started',
            'message' => 'Remote node agent upgrade started.',
            'log_path' => $logPath,
        ]);
    }

    private function resolveUpgradeSource(string $sourceType, string $sourceValue): array
    {
        if ($sourceType === 'version') {
            return ['--version', $sourceValue];
        }

        if ($sourceType === 'channel') {
            $this->assertSupportedChannel($sourceValue);

            return ['--channel', $sourceValue];
        }

        return ['--branch', $sourceValue];
    }

    private function resolveRemoteAgentUpgradeSource(string $sourceType, string $sourceValue): array
    {
        if ($sourceType === 'version') {
            return ['--target-version', $sourceValue];
        }

        if ($sourceType === 'channel') {
            $this->assertSupportedChannel($sourceValue);

            return ['--channel', $sourceValue];
        }

        return ['--branch', $sourceValue];
    }

    private function assertSupportedChannel(string $channel): void
    {
        $supported = collect(self::SUPPORTED_CHANNELS)->pluck('value')->all();

        abort_unless(in_array($channel, $supported, true), 422, 'Unsupported update channel.');
    }
}
