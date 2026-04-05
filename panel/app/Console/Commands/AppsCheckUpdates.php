<?php

namespace App\Console\Commands;

use App\Jobs\UpdateAppJob;
use App\Models\AppInstallation;
use App\Services\AgentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppsCheckUpdates extends Command
{
    protected $signature   = 'apps:check-updates {--apply : Apply updates to installations with auto_update enabled}';
    protected $description = 'Check all active app installations for available updates, optionally auto-apply';

    public function handle(): void
    {
        $installations = AppInstallation::where('status', 'active')->with('node')->get();

        if ($installations->isEmpty()) {
            $this->info('No active installations to check.');
            return;
        }

        $this->info("Checking {$installations->count()} installation(s)…");

        foreach ($installations as $inst) {
            try {
                $latest = $this->fetchLatestVersion($inst->app_slug);
                if (! $latest) {
                    continue;
                }

                $updateAvailable = version_compare($latest, $inst->installed_version ?? '0', '>');

                $inst->update([
                    'latest_version'  => $latest,
                    'update_available'=> $updateAvailable,
                    'last_checked_at' => now(),
                ]);

                if ($updateAvailable) {
                    $this->line("  [{$inst->app_name}] {$inst->site_url} — update available: {$inst->installed_version} → {$latest}");

                    if ($this->option('apply') && $inst->auto_update) {
                        $this->line("    Auto-updating…");
                        UpdateAppJob::dispatch($inst);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("apps:check-updates failed for installation #{$inst->id}: " . $e->getMessage());
            }
        }

        $this->info('Done.');
    }

    private function fetchLatestVersion(string $appSlug): ?string
    {
        try {
            return match ($appSlug) {
                'wordpress' => $this->fetchWordPressLatest(),
                'joomla'    => $this->fetchJoomlaLatest(),
                default     => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchWordPressLatest(): ?string
    {
        $response = Http::timeout(10)->get('https://api.wordpress.org/core/version-check/1.7/');
        $offers   = $response->json('offers');
        return $offers[0]['version'] ?? null;
    }

    private function fetchJoomlaLatest(): ?string
    {
        $response = Http::timeout(10)->get('https://downloads.joomla.org/api/v1/latest/cms');
        return $response->json('latest') ?? null;
    }
}
