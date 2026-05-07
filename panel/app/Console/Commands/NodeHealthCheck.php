<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Console\Command;

class NodeHealthCheck extends Command
{
    protected $signature   = 'strata:node-health';
    protected $description = 'Ping all registered nodes and update their status.';

    public function handle(): int
    {
        $nodes = Node::whereNull('deleted_at')->get();

        if ($nodes->isEmpty()) {
            $this->line('No nodes registered.');
            return Command::SUCCESS;
        }

        foreach ($nodes as $node) {
            try {
                $response = AgentClient::for($node)->health();

                if ($response->successful()) {
                    $data = $response->json();
                    $reportedVersion = null;
                    try {
                        $versionResponse = AgentClient::for($node)->version();
                        if ($versionResponse->successful() && is_string($versionResponse->json('version'))) {
                            $reportedVersion = $this->normalizeAgentVersion($versionResponse->json('version'));
                        }
                    } catch (\Throwable) {
                        // Health should not be marked offline just because an older agent lacks /version.
                    }

                    $updates = [
                        'last_seen_at' => now(),
                        'last_health' => $data,
                    ];

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
                    $this->line("<info>✓</info>  {$node->name} ({$node->ip_address}) — online");
                } else {
                    $node->update(['status' => 'offline']);
                    $this->line("<comment>✗</comment>  {$node->name} ({$node->ip_address}) — HTTP {$response->status()}");
                }
            } catch (\Throwable $e) {
                $node->update(['status' => 'offline']);
                $this->line("<error>✗</error>  {$node->name} ({$node->ip_address}) — {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }

    private function normalizeAgentVersion(?string $version): ?string
    {
        $value = trim((string) $version);

        if ($value === '' || strtolower($value) === 'dev') {
            return null;
        }

        return $value;
    }
}
