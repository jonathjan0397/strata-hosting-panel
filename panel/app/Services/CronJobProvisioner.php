<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CronJob;
use Cron\CronExpression;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CronJobProvisioner
{
    public function normalizePayload(Account $account, array $data): array
    {
        $cronLine = trim((string) ($data['cron_line'] ?? ''));

        if ($cronLine !== '') {
            [$expression, $command] = $this->parseCronLine($cronLine);
        } else {
            $expression = trim((string) ($data['expression'] ?? ''));
            $command = trim((string) ($data['command'] ?? ''));
        }

        if ($expression === '') {
            throw new InvalidArgumentException('Cron schedule is required.');
        }

        if (! CronExpression::isValidExpression($expression)) {
            throw new InvalidArgumentException('Cron schedule is invalid. Use the standard five-field cron format.');
        }

        if ($command === '') {
            throw new InvalidArgumentException('Command is required.');
        }

        if (str_contains($command, "\n") || str_contains($command, "\r")) {
            throw new InvalidArgumentException('Command must stay on a single line.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $workingDir = $this->normalizeWorkingDir($account, (string) ($data['working_dir'] ?? '.'));

        return [
            'name' => $name !== '' ? $name : null,
            'expression' => preg_replace('/\s+/', ' ', $expression),
            'command' => $command,
            'working_dir' => $workingDir,
            'is_enabled' => filter_var($data['is_enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        ];
    }

    public function sync(Account $account): array
    {
        return $this->syncJobs($account, $account->cronJobs()->orderBy('id')->get());
    }

    public function create(Account $account, array $data): array
    {
        $normalized = $this->normalizePayload($account, $data);
        $existing = $account->cronJobs()->orderBy('id')->get();
        $preview = $existing->map(fn (CronJob $job) => $this->serializeJob($job))
            ->push($normalized)
            ->all();

        [$success, $error] = $this->syncPreview($account, $preview);
        if (! $success) {
            return [false, $error];
        }

        $account->cronJobs()->create($normalized);

        return [true, null];
    }

    public function update(CronJob $job, array $data): array
    {
        $account = $job->account()->with('node')->firstOrFail();
        $normalized = $this->normalizePayload($account, $data);
        $existing = $account->cronJobs()->orderBy('id')->get();

        $preview = $existing->map(function (CronJob $existingJob) use ($job, $normalized) {
            return $existingJob->is($job) ? $normalized : $this->serializeJob($existingJob);
        })->all();

        [$success, $error] = $this->syncPreview($account, $preview);
        if (! $success) {
            return [false, $error];
        }

        $job->update($normalized);

        return [true, null];
    }

    public function delete(CronJob $job): array
    {
        $account = $job->account()->with('node')->firstOrFail();
        $existing = $account->cronJobs()->orderBy('id')->get();

        $preview = $existing
            ->reject(fn (CronJob $existingJob) => $existingJob->is($job))
            ->map(fn (CronJob $existingJob) => $this->serializeJob($existingJob))
            ->values()
            ->all();

        [$success, $error] = $this->syncPreview($account, $preview);
        if (! $success) {
            return [false, $error];
        }

        $job->delete();

        return [true, null];
    }

    private function syncJobs(Account $account, Collection $jobs): array
    {
        return $this->syncPreview(
            $account,
            $jobs->map(fn (CronJob $job) => $this->serializeJob($job))->all(),
        );
    }

    private function syncPreview(Account $account, array $jobs): array
    {
        $account->loadMissing('node');

        if (! $account->node) {
            return [false, 'Account has no assigned node.'];
        }

        $response = AgentClient::for($account->node)->syncCronJobs($account->username, $jobs);

        if (! $response->successful()) {
            return [false, $response->body() ?: 'Cron sync failed.'];
        }

        return [true, null];
    }

    private function serializeJob(CronJob $job): array
    {
        return [
            'name' => $job->name,
            'expression' => $job->expression,
            'command' => $job->command,
            'working_dir' => $job->working_dir,
            'is_enabled' => (bool) $job->is_enabled,
        ];
    }

    private function parseCronLine(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line), 6, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || count($parts) < 6) {
            throw new InvalidArgumentException('Cron line must include five schedule fields followed by a command.');
        }

        return [implode(' ', array_slice($parts, 0, 5)), trim($parts[5])];
    }

    private function normalizeWorkingDir(Account $account, string $workingDir): string
    {
        $workingDir = trim($workingDir);

        if ($workingDir === '') {
            $workingDir = '.';
        }

        if (str_contains($workingDir, "\n") || str_contains($workingDir, "\r")) {
            throw new InvalidArgumentException('Working directory must stay on a single line.');
        }

        if (! str_starts_with($workingDir, '/')) {
            $relativePath = $workingDir;
            if ($relativePath === '.' || $relativePath === './') {
                $relativePath = '';
            } elseif (str_starts_with($relativePath, './')) {
                $relativePath = substr($relativePath, 2);
            }

            $workingDir = '/home/' . $account->username . '/' . ltrim($relativePath, '/');
            $workingDir = preg_replace('#/+#', '/', $workingDir) ?: $workingDir;
        }

        return rtrim($workingDir, '/') ?: '/';
    }
}
