<?php

namespace App\Services;

use App\Models\Account;

class AccountProvisioner
{
    /**
     * Provision a system user + PHP-FPM pool on the account's node.
     * Returns [bool $success, ?string $error].
     */
    public function provision(Account $account): array
    {
        try {
            $response = AgentClient::for($account->node)->provisionAccount([
                'username'    => $account->username,
                'php_version' => $account->php_version,
            ]);

            if ($response->successful()) {
                $actualPhpVersion = $response->json('php_version');
                if (is_string($actualPhpVersion) && $actualPhpVersion !== '' && $actualPhpVersion !== $account->php_version) {
                    $account->update(['php_version' => $actualPhpVersion]);
                }

                return [true, null];
            }

            return [false, $response->json('message') ?? $response->body()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Remove the system user and all their files from the node.
     */
    public function deprovision(Account $account): array
    {
        try {
            $response = AgentClient::for($account->node)->deprovisionAccount($account->username);

            if ($response->successful()) {
                return [true, null];
            }

            return [false, $response->json('message') ?? $response->body()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
