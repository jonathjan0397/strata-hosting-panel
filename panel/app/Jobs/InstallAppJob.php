<?php

namespace App\Jobs;

use App\Models\AppInstallation;
use App\Models\HostingDatabase;
use App\Services\AgentClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InstallAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes — WP-CLI + npm installs can be slow
    public int $tries   = 1;

    public function __construct(public AppInstallation $installation) {}

    public function handle(): void
    {
        $inst = $this->installation;
        $inst->update(['status' => 'installing']);

        try {
            $response = AgentClient::for($inst->node)->appInstall([
                'app'          => $inst->app_slug,
                'install_dir'  => $inst->install_dir,
                'db_name'      => $inst->db_name,
                'db_user'      => $inst->db_user,
                'db_password'  => $inst->db_password_plain,
                'site_url'     => $inst->site_url,
                'site_title'   => $inst->site_title,
                'admin_email'  => $inst->admin_email,
                'admin_username' => $inst->admin_username,
                'admin_password' => $inst->admin_password_plain,
                'site_owner'   => $inst->account->username,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if ($inst->db_name && $inst->db_user) {
                    HostingDatabase::updateOrCreate(
                        [
                            'account_id' => $inst->account_id,
                            'db_name' => $inst->db_name,
                        ],
                        [
                            'node_id' => $inst->node_id,
                            'domain_id' => $inst->domain_id,
                            'engine' => 'mysql',
                            'db_user' => $inst->db_user,
                            'password' => $inst->db_password_plain,
                            'note' => "App installer: {$inst->app_name}",
                        ],
                    );
                }

                $inst->update([
                    'status'            => 'active',
                    'installed_version' => $data['version'] ?? null,
                    'setup_url'         => $data['setup_url'] ?? null,
                    'error_message'     => null,
                ]);
            } else {
                $inst->update([
                    'status'        => 'error',
                    'error_message' => $response->json('error') ?? "Agent returned {$response->status()}",
                ]);
            }
        } catch (\Throwable $e) {
            $inst->update([
                'status'        => 'error',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
