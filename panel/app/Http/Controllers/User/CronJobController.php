<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CronJob;
use App\Services\CronJobProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CronJobController extends Controller
{
    public function __construct(private readonly CronJobProvisioner $provisioner)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $account = $this->account($request);

        if (! $account) {
            return redirect()->route('my.dashboard')->with('error', 'No hosting account found.');
        }

        return Inertia::render('User/Cron/Index', [
            'account' => $account->only(['id', 'username', 'php_version']),
            'jobs' => $account->cronJobs()->orderBy('id')->get()->map(fn (CronJob $job) => $this->presentJob($job)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $data = $this->validatePayload($request);

        [$success, $error] = $this->provisioner->create($account, $data);

        if ($success) {
            AuditLog::record('cron.job_created', $account, [
                'name' => $data['name'] ?? null,
                'cron_line' => trim((string) ($data['cron_line'] ?? '')),
            ]);
        }

        return $success
            ? back()->with('success', 'Cron job created and applied.')
            : back()->with('error', "Cron job creation failed: {$error}");
    }

    public function update(Request $request, CronJob $cronJob): RedirectResponse
    {
        $account = $this->requireAccount($request);
        abort_unless($cronJob->account_id === $account->id, 403);

        $data = $this->validatePayload($request);
        [$success, $error] = $this->provisioner->update($cronJob, $data);

        if ($success) {
            AuditLog::record('cron.job_updated', $account, [
                'job_id' => $cronJob->id,
                'name' => $data['name'] ?? $cronJob->name,
            ]);
        }

        return $success
            ? back()->with('success', 'Cron job updated and applied.')
            : back()->with('error', "Cron job update failed: {$error}");
    }

    public function destroy(Request $request, CronJob $cronJob): RedirectResponse
    {
        $account = $this->requireAccount($request);
        abort_unless($cronJob->account_id === $account->id, 403);

        [$success, $error] = $this->provisioner->delete($cronJob);

        if ($success) {
            AuditLog::record('cron.job_deleted', $account, [
                'job_id' => $cronJob->id,
                'name' => $cronJob->name,
            ]);
        }

        return $success
            ? back()->with('success', 'Cron job removed and applied.')
            : back()->with('error', "Cron job deletion failed: {$error}");
    }

    public function sync(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        [$success, $error] = $this->provisioner->sync($account);

        if ($success) {
            AuditLog::record('cron.jobs_synced', $account, [
                'count' => $account->cronJobs()->count(),
            ]);
        }

        return $success
            ? back()->with('success', 'Cron jobs reapplied to the node.')
            : back()->with('error', "Cron sync failed: {$error}");
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'cron_line' => ['nullable', 'string', 'max:1024'],
            'expression' => ['nullable', 'string', 'max:255'],
            'command' => ['nullable', 'string', 'max:2000'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);
    }

    private function account(Request $request): ?Account
    {
        return Account::where('user_id', $request->user()->id)->with('node')->first();
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->account($request);
        abort_if(! $account, 404, 'No hosting account.');

        return $account;
    }

    private function presentJob(CronJob $job): array
    {
        return [
            'id' => $job->id,
            'name' => $job->name,
            'expression' => $job->expression,
            'command' => $job->command,
            'is_enabled' => (bool) $job->is_enabled,
            'cron_line' => trim($job->expression . ' ' . $job->command),
            'created_at' => optional($job->created_at)?->toDateTimeString(),
            'updated_at' => optional($job->updated_at)?->toDateTimeString(),
        ];
    }
}
