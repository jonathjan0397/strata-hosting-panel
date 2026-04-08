<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FtpAccount;
use Throwable;

class FtpProvisioner
{
    public function __construct(private readonly AgentClient $client) {}

    /**
     * Create a Pure-FTPd virtual account jailed to the account's web root.
     * Returns [bool $success, ?string $error].
     */
    public function create(Account $account, string $username, string $password, ?int $quotaMb = 0): array
    {
        $homeDir = "/var/www/{$account->username}/public_html";

        try {
            $response = $this->client->createFtpAccount([
                'username' => $username,
                'password' => $password,
                'home_dir' => $homeDir,
            ]);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            FtpAccount::create([
                'account_id' => $account->id,
                'node_id'    => $account->node_id,
                'username'   => $username,
                'home_dir'   => $homeDir,
                'quota_mb'   => $quotaMb ?? 0,
            ]);

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Delete a Pure-FTPd virtual account.
     */
    public function delete(FtpAccount $ftp): array
    {
        try {
            $response = $this->client->deleteFtpAccount($ftp->username);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            $ftp->delete();

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Change an FTP account's password.
     */
    public function changePassword(FtpAccount $ftp, string $password): array
    {
        try {
            if ($ftp->migration_reset_required) {
                $create = $this->client->createFtpAccount([
                    'username' => $ftp->username,
                    'password' => $password,
                    'home_dir' => $ftp->home_dir,
                ]);

                if (! $create->successful()) {
                    $update = $this->client->changeFtpPassword($ftp->username, $password);
                    if (! $update->successful()) {
                        return [false, $create->body() . ' / ' . $update->body()];
                    }
                }

                $ftp->update(['migration_reset_required' => false, 'active' => true]);
                return [true, null];
            }

            $response = $this->client->changeFtpPassword($ftp->username, $password);
            if (! $response->successful()) {
                return [false, $response->body()];
            }
            $ftp->update(['migration_reset_required' => false, 'active' => true]);
            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
