<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Models\SystemSetting;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;

class UpdateController extends Controller
{
    private const PANEL_UPGRADE_BIN = '/usr/sbin/strata-upgrade';
    private const SUPPORTED_CHANNELS = [
        ['value' => 'main', 'label' => 'Main', 'description' => 'Latest supported integration branch.'],
        ['value' => 'latest-untested', 'label' => 'Latest-Untested', 'description' => 'Newer branch for early validation before normal release use.'],
        ['value' => 'experimental', 'label' => 'Experimental', 'description' => 'High-risk branch for active experiments and unfinished work.'],
    ];

    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();
        $latestRelease = $this->latestPanelRelease();

        return Inertia::render('Admin/Updates/Index', [
            'nodes' => $nodes,
            'panel' => [
                'version' => config('strata.version'),
                'latest_release' => $latestRelease,
                'available_branches' => $this->availablePanelBranches(),
                'upgrade_script' => $this->panelUpgradeUtilityAvailable(),
                'log_path' => storage_path('logs/strata-panel-upgrade.log'),
                'activity' => $this->upgradeActivityPayload(),
                'default_source_type' => 'version',
                'default_source_value' => $latestRelease['tag_name'] ?? '',
                'auto_remote_agents' => SystemSetting::getValue('updates.auto_remote_agents', '0') === '1',
                'rollback_backups' => $this->availableRollbackBackups(),
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

        if (! $this->panelUpgradeUtilityAvailable()) {
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
            "nohup sudo -n %s %s %s%s >> %s 2>&1 < /dev/null &",
            escapeshellarg(self::PANEL_UPGRADE_BIN),
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

    public function rollbackBackup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'backup_name' => ['required', 'string', 'max:255'],
        ]);

        if (! $this->panelUpgradeUtilityAvailable()) {
            return response()->json([
                'status' => 'error',
                'message' => 'The panel upgrade utility is not installed on this server.',
            ], 500);
        }

        $backupName = trim($data['backup_name']);
        $backups = collect($this->availableRollbackBackups());
        abort_unless($backups->contains(fn (array $backup) => ($backup['name'] ?? null) === $backupName), 422, 'Unknown rollback backup.');

        $logPath = storage_path('logs/strata-panel-rollback.log');
        File::ensureDirectoryExists(dirname($logPath));
        File::append(
            $logPath,
            sprintf(
                "[%s] Admin %s started panel rollback from backup: %s\n",
                now()->toDateTimeString(),
                $request->user()->email,
                $backupName
            )
        );

        $command = sprintf(
            "nohup sudo -n %s %s %s >> %s 2>&1 < /dev/null &",
            escapeshellarg(self::PANEL_UPGRADE_BIN),
            escapeshellarg('--rollback-backup'),
            escapeshellarg($backupName),
            escapeshellarg($logPath)
        );

        Process::fromShellCommandline('/bin/bash -lc ' . escapeshellarg($command))->run();

        AuditLog::record('panel.rollback.started', null, [
            'backup_name' => $backupName,
        ]);

        return response()->json([
            'status' => 'started',
            'message' => 'Panel rollback started. The panel may be briefly unavailable while services restart.',
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

    public function activity(): JsonResponse
    {
        return response()->json($this->upgradeActivityPayload());
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

    private function panelUpgradeUtilityAvailable(): bool
    {
        return is_executable(self::PANEL_UPGRADE_BIN);
    }

    private function availableRollbackBackups(): array
    {
        if (! $this->panelUpgradeUtilityAvailable()) {
            return [];
        }

        $process = new Process(['sudo', '-n', self::PANEL_UPGRADE_BIN, '--list-backups']);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $payload = json_decode($process->getOutput(), true);

        return is_array($payload) ? $payload : [];
    }

    private function latestPanelRelease(): ?array
    {
        return Cache::remember('updates.github.latest_release', now()->addMinutes(2), function (): ?array {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get('https://api.github.com/repos/jonathjan0397/strata-hosting-panel/releases/latest');

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return null;
            }

            return [
                'name' => $payload['name'] ?? null,
                'tag_name' => $payload['tag_name'] ?? null,
                'html_url' => $payload['html_url'] ?? null,
                'published_at' => $payload['published_at'] ?? null,
                'prerelease' => (bool) ($payload['prerelease'] ?? false),
            ];
        });
    }

    private function availablePanelBranches(): array
    {
        return Cache::remember('updates.github.available_branches', now()->addMinutes(2), function (): array {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get('https://api.github.com/repos/jonathjan0397/strata-hosting-panel/branches');

            if (! $response->successful()) {
                return collect(self::SUPPORTED_CHANNELS)
                    ->map(fn (array $channel) => [
                        'name' => $channel['value'],
                        'label' => $channel['label'],
                        'description' => $channel['description'],
                    ])
                    ->all();
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return [];
            }

            $branchDescriptions = collect(self::SUPPORTED_CHANNELS)
                ->keyBy('value');

            return collect($payload)
                ->map(function ($branch) use ($branchDescriptions) {
                    $name = $branch['name'] ?? null;

                    if (! is_string($name) || $name === '') {
                        return null;
                    }

                    $known = $branchDescriptions->get($name);

                    return [
                        'name' => $name,
                        'label' => $known['label'] ?? $name,
                        'description' => $known['description'] ?? 'Available branch from GitHub.',
                    ];
                })
                ->filter()
                ->values()
                ->all();
        });
    }

    private function upgradeActivityPayload(): array
    {
        $activities = [
            $this->buildLogActivity(
                key: 'panel_upgrade',
                label: 'Panel Upgrade',
                path: storage_path('logs/strata-panel-upgrade.log'),
                processNeedles: ['/usr/sbin/strata-upgrade'],
                stages: [
                    ['match' => 'started panel upgrade', 'progress' => 5, 'label' => 'Queued'],
                    ['match' => 'Creating rollback backup', 'progress' => 15, 'label' => 'Creating rollback backup'],
                    ['match' => 'Downloading', 'progress' => 28, 'label' => 'Downloading release source'],
                    ['match' => 'Installing new source', 'progress' => 42, 'label' => 'Installing source'],
                    ['match' => 'Installing panel dependencies and running migrations', 'progress' => 56, 'label' => 'Installing dependencies and migrations'],
                    ['match' => 'Building frontend assets', 'progress' => 68, 'label' => 'Building frontend assets'],
                    ['match' => 'Building strata-agent', 'progress' => 78, 'label' => 'Building agent binaries'],
                    ['match' => 'Restarting services', 'progress' => 88, 'label' => 'Restarting services'],
                    ['match' => 'Running health checks', 'progress' => 95, 'label' => 'Running health checks'],
                    ['match' => 'Upgrade completed successfully.', 'progress' => 100, 'label' => 'Completed'],
                ],
            ),
            $this->buildLogActivity(
                key: 'panel_rollback',
                label: 'Panel Rollback',
                path: storage_path('logs/strata-panel-rollback.log'),
                processNeedles: ['/usr/sbin/strata-upgrade'],
                stages: [
                    ['match' => 'started panel rollback from backup', 'progress' => 5, 'label' => 'Queued'],
                    ['match' => 'Creating rollback backup', 'progress' => 18, 'label' => 'Creating safety backup'],
                    ['match' => 'Rolling back', 'progress' => 60, 'label' => 'Restoring backup'],
                    ['match' => 'Rollback completed from backup', 'progress' => 100, 'label' => 'Completed'],
                ],
            ),
            $this->buildLogActivity(
                key: 'remote_agents',
                label: 'Remote Agent Upgrade',
                path: storage_path('logs/strata-remote-agents-upgrade.log'),
                processNeedles: ['artisan strata:nodes-upgrade-agents'],
                stages: [
                    ['match' => 'started remote agent upgrade', 'progress' => 5, 'label' => 'Queued'],
                    ['match' => 'Starting agent upgrades', 'progress' => 25, 'label' => 'Preparing upgrade jobs'],
                    ['match' => 'Queued upgrade for', 'progress' => 55, 'label' => 'Queueing remote upgrades'],
                    ['match' => 'All remote node agent upgrades have been queued', 'progress' => 100, 'label' => 'Completed'],
                ],
            ),
        ];

        usort($activities, function (array $left, array $right): int {
            return ($right['last_modified_unix'] ?? 0) <=> ($left['last_modified_unix'] ?? 0);
        });

        $current = collect($activities)->firstWhere('status', 'running')
            ?? collect($activities)->first(fn (array $activity) => ($activity['exists'] ?? false))
            ?? $activities[0];

        return [
            'current_log_key' => $current['key'] ?? 'panel_upgrade',
            'activities' => array_map(function (array $activity): array {
                unset($activity['last_modified_unix']);

                return $activity;
            }, $activities),
        ];
    }

    private function buildLogActivity(string $key, string $label, string $path, array $processNeedles, array $stages): array
    {
        $exists = File::exists($path);
        $lines = $exists ? $this->tailLines($path, 250) : [];
        $lastModifiedUnix = $exists ? (File::lastModified($path) ?: 0) : 0;
        $lastLine = $lines !== [] ? end($lines) : null;
        $matchingProcesses = $this->matchingProcesses($processNeedles);
        $status = $this->inferActivityStatus($lines, $matchingProcesses !== []);
        $stage = $this->inferActivityStage($lines, $stages, $status);

        return [
            'key' => $key,
            'label' => $label,
            'exists' => $exists,
            'log_path' => $path,
            'status' => $status,
            'progress' => $stage['progress'],
            'stage' => $stage['label'],
            'last_line' => $lastLine,
            'last_modified_at' => $lastModifiedUnix > 0 ? date(DATE_ATOM, $lastModifiedUnix) : null,
            'last_modified_unix' => $lastModifiedUnix,
            'process_count' => count($matchingProcesses),
            'lines' => $lines,
        ];
    }

    private function inferActivityStatus(array $lines, bool $running): string
    {
        $content = strtolower(implode("\n", $lines));

        if ($running) {
            return 'running';
        }

        if (str_contains($content, 'completed successfully')
            || str_contains($content, 'rollback completed from backup')
            || str_contains($content, 'all remote node agent upgrades have been queued')) {
            return 'completed';
        }

        if (str_contains($content, '[err]')
            || str_contains($content, 'upgrade failed')
            || str_contains($content, 'rolling back')
            || str_contains($content, 'failed')) {
            return 'failed';
        }

        if ($lines !== []) {
            return 'idle';
        }

        return 'idle';
    }

    private function inferActivityStage(array $lines, array $stages, string $status): array
    {
        $content = implode("\n", $lines);
        $resolved = ['progress' => 0, 'label' => 'Idle'];

        foreach ($stages as $stage) {
            if (str_contains($content, $stage['match'])) {
                $resolved = [
                    'progress' => $stage['progress'],
                    'label' => $stage['label'],
                ];
            }
        }

        if ($status === 'running' && $resolved['progress'] === 0) {
            return ['progress' => 10, 'label' => 'Running'];
        }

        if ($status === 'failed' && $resolved['progress'] === 0) {
            return ['progress' => 100, 'label' => 'Failed'];
        }

        if ($status === 'completed' && $resolved['progress'] < 100) {
            return ['progress' => 100, 'label' => 'Completed'];
        }

        return $resolved;
    }

    private function tailLines(string $path, int $limit = 200): array
    {
        $content = File::get($path);
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $lines = array_values(array_filter($lines, static fn ($line) => $line !== ''));

        return array_slice($lines, -$limit);
    }

    private function matchingProcesses(array $needles): array
    {
        $process = new Process(['ps', '-eo', 'pid=,args=']);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $matches = [];
        foreach (preg_split("/\r\n|\n|\r/", $process->getOutput()) ?: [] as $line) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($line, $needle)) {
                    $matches[] = trim($line);
                    break;
                }
            }
        }

        return $matches;
    }
}
