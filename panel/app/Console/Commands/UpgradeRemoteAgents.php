<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Console\Command;

class UpgradeRemoteAgents extends Command
{
    protected $signature = 'strata:nodes-upgrade-agents
        {--version= : GitHub release/tag version to install on remote agents}
        {--branch= : GitHub branch to install on remote agents}
        {--download-url= : Explicit trusted GitHub archive URL}
        {--include-primary : Also ask the primary/local node agent to self-upgrade}';

    protected $description = 'Cascade a Strata agent upgrade to online remote nodes.';

    public function handle(): int
    {
        $version = trim((string) $this->option('version'));
        $branch = trim((string) $this->option('branch'));
        $downloadUrl = trim((string) $this->option('download-url'));

        $selected = count(array_filter([$version, $branch, $downloadUrl], fn (string $value) => $value !== ''));
        if ($selected !== 1) {
            $this->error('Choose exactly one of --version, --branch, or --download-url.');
            return Command::FAILURE;
        }

        if ($version !== '') {
            $targetVersion = $version;
            $downloadUrl = "https://github.com/jonathjan0397/strata-hosting-panel/archive/refs/tags/{$version}.tar.gz";
        } elseif ($branch !== '') {
            $targetVersion = $branch;
            $downloadUrl = "https://github.com/jonathjan0397/strata-hosting-panel/archive/refs/heads/{$branch}.tar.gz";
        } else {
            if (! preg_match('#^https://github\.com/jonathjan0397/strata-hosting-panel/(archive/refs/(tags|heads)/[^/]+\.tar\.gz|releases/download/.+)$#', $downloadUrl)) {
                $this->error('download-url must be a trusted strata-hosting-panel GitHub archive or release asset.');
                return Command::FAILURE;
            }

            $targetVersion = basename($downloadUrl, '.tar.gz');
        }

        $nodes = Node::query()
            ->whereNull('deleted_at')
            ->where('status', 'online')
            ->when(! $this->option('include-primary'), fn ($query) => $query->where('is_primary', false))
            ->orderBy('id')
            ->get();

        if ($nodes->isEmpty()) {
            $this->line('No online remote nodes require an agent upgrade.');
            return Command::SUCCESS;
        }

        $errors = 0;

        foreach ($nodes as $node) {
            $this->line("Upgrading {$node->name} ({$node->hostname}) to {$targetVersion}...");

            try {
                $response = AgentClient::for($node)->upgradeAgent($targetVersion, $downloadUrl);

                if (! $response->successful()) {
                    $errors++;
                    $this->warn("  failed: HTTP {$response->status()} {$response->body()}");
                    continue;
                }

                $node->update([
                    'status' => 'upgrading',
                    'agent_version' => $targetVersion,
                ]);

                $this->info("  queued");
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  failed: {$e->getMessage()}");
            }
        }

        if ($errors > 0) {
            $this->error("Remote agent upgrade completed with {$errors} error(s).");
            return Command::FAILURE;
        }

        $this->info('Remote agent upgrade requests queued.');
        return Command::SUCCESS;
    }
}
