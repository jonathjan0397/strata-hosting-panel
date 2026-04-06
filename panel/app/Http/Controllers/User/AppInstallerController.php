<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Jobs\InstallAppJob;
use App\Jobs\UpdateAppJob;
use App\Models\AppInstallation;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AppInstallerController extends Controller
{
    // ── GET /my/apps ──────────────────────────────────────────────────────────

    public function catalog(Request $request)
    {
        $account = Auth::user()->account;
        $domains = Domain::where('account_id', $account->id)
            ->with('node')
            ->orderBy('domain')
            ->get(['id', 'domain', 'document_root', 'node_id']);

        return Inertia::render('User/Apps/Catalog', [
            'catalog' => config('apps'),
            'domains' => $domains,
        ]);
    }

    // ── GET /my/apps/installed ─────────────────────────────────────────────────

    public function myApps()
    {
        $account = Auth::user()->account;
        $installs = AppInstallation::where('account_id', $account->id)
            ->with('domain:id,domain')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('User/Apps/MyApps', [
            'installations' => $installs->map(fn($i) => [
                'id'                => $i->id,
                'app_slug'          => $i->app_slug,
                'app_name'          => $i->app_name,
                'domain'            => $i->domain->domain,
                'install_path'      => $i->install_path,
                'site_url'          => $i->site_url,
                'setup_url'         => $i->setup_url,
                'installed_version' => $i->installed_version,
                'latest_version'    => $i->latest_version,
                'update_available'  => $i->update_available,
                'auto_update'       => $i->auto_update,
                'status'            => $i->status,
                'error_message'     => $i->error_message,
                'last_updated_at'   => $i->last_updated_at?->toDateString(),
                'created_at'        => $i->created_at->toDateString(),
            ]),
        ]);
    }

    // ── POST /my/apps/install ─────────────────────────────────────────────────

    public function install(Request $request)
    {
        $account = Auth::user()->account;

        $data = $request->validate([
            'app_slug'    => ['required', 'string', 'in:' . implode(',', array_keys(config('apps')))],
            'domain_id'   => ['required', 'integer'],
            'install_path'=> ['required', 'string', 'regex:/^\/[a-zA-Z0-9_\-\/]*$/'],
            'site_title'  => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email'],
            'auto_update' => ['boolean'],
        ]);

        $domain = Domain::where('id', $data['domain_id'])
            ->where('account_id', $account->id)
            ->with('node')
            ->firstOrFail();

        // Build install dir from domain docroot + path
        $installPath = rtrim($data['install_path'], '/') ?: '/';
        $installDir  = rtrim($domain->document_root, '/') . ($installPath === '/' ? '' : $installPath);
        $siteUrl     = 'https://' . $domain->domain . ($installPath === '/' ? '' : $installPath);

        // Generate unique DB credentials
        $prefix  = preg_replace('/[^a-z0-9]/', '', strtolower($account->username));
        $prefix  = substr($prefix, 0, 12);
        $suffix  = substr(md5($data['app_slug'] . $domain->id . time()), 0, 6);
        $dbName  = "{$prefix}_{$data['app_slug']}_{$suffix}";
        $dbUser  = "{$prefix}_{$suffix}";
        $dbPass  = bin2hex(random_bytes(16));

        $installation = AppInstallation::create([
            'account_id'   => $account->id,
            'domain_id'    => $domain->id,
            'node_id'      => $domain->node_id,
            'app_slug'     => $data['app_slug'],
            'install_path' => $installPath,
            'install_dir'  => $installDir,
            'db_name'      => $dbName,
            'db_user'      => $dbUser,
            'db_password'  => $dbPass,
            'site_url'     => $siteUrl,
            'site_title'   => $data['site_title'],
            'admin_email'  => $data['admin_email'],
            'auto_update'  => $data['auto_update'] ?? true,
            'status'       => 'queued',
        ]);

        InstallAppJob::dispatch($installation);

        return redirect()->route('my.apps.installed')
            ->with('success', config("apps.{$data['app_slug']}.name") . ' installation started. This may take a few minutes.');
    }

    // ── POST /my/apps/{installation}/update ───────────────────────────────────

    public function update(AppInstallation $installation)
    {
        $account = Auth::user()->account;
        abort_if($installation->account_id !== $account->id, 403);
        abort_unless(in_array($installation->status, ['active', 'error']), 422, 'Installation is not in a updatable state.');

        UpdateAppJob::dispatch($installation);

        return back()->with('success', 'Update started.');
    }

    // ── PATCH /my/apps/{installation}/auto-update ─────────────────────────────

    public function toggleAutoUpdate(AppInstallation $installation)
    {
        $account = Auth::user()->account;
        abort_if($installation->account_id !== $account->id, 403);

        $installation->update(['auto_update' => ! $installation->auto_update]);

        return back();
    }

    // ── DELETE /my/apps/{installation} ────────────────────────────────────────

    public function destroy(AppInstallation $installation)
    {
        $account = Auth::user()->account;
        abort_if($installation->account_id !== $account->id, 403);

        try {
            $response = AgentClient::for($installation->node)->appUninstall([
                'install_dir' => $installation->install_dir,
                'db_name'     => $installation->db_name,
                'db_user'     => $installation->db_user,
                'site_owner'  => $account->username,
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to remove app from the node: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            return back()->with('error', 'Failed to remove app: ' . $response->body());
        }

        $installation->delete();

        return redirect()->route('my.apps.installed')
            ->with('success', $installation->app_name . ' removed.');
    }
}
