<?php

namespace App\Services;

use App\Models\Account;
use App\Models\WebDavAccount;
use Throwable;

class WebDavProvisioner
{
    public function __construct(private readonly AgentClient $client) {}

    public function create(Account $account, string $username, string $password): array
    {
        $homeDir = "/var/www/{$account->username}";

        try {
            $response = $this->client->createWebDavAccount([
                'username' => $username,
                'password' => $password,
                'home_dir' => $homeDir,
            ]);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            WebDavAccount::create([
                'account_id' => $account->id,
                'node_id' => $account->node_id,
                'username' => $username,
                'home_dir' => $homeDir,
            ]);

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    public function delete(WebDavAccount $webDavAccount): array
    {
        try {
            $response = $this->client->deleteWebDavAccount($webDavAccount->username);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $webDavAccount->delete();
            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    public function changePassword(WebDavAccount $webDavAccount, string $password): array
    {
        try {
            $response = $this->client->changeWebDavPassword($webDavAccount->username, $password, $webDavAccount->home_dir);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
