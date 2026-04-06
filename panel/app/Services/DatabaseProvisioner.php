<?php

namespace App\Services;

use App\Models\Account;
use App\Models\DatabaseGrant;
use App\Models\HostingDatabase;
use Throwable;

class DatabaseProvisioner
{
    public function __construct(private readonly AgentClient $client) {}

    /**
     * Create a new database + user on the node and record in the panel DB.
     * Returns [bool $success, ?string $error].
     */
    public function create(Account $account, string $dbName, string $dbUser, string $password, ?string $note = null): array
    {
        try {
            $response = $this->client->createDatabase($dbName, $dbUser, $password);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            HostingDatabase::create([
                'account_id' => $account->id,
                'node_id'    => $account->node_id,
                'db_name'    => $dbName,
                'db_user'    => $dbUser,
                'note'       => $note,
            ]);

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Drop the database + user on the node and remove from panel DB.
     */
    public function delete(HostingDatabase $db): array
    {
        try {
            $grants = DatabaseGrant::where('db_name', $db->db_name)
                ->where('account_id', $db->account_id)
                ->get();

            foreach ($grants as $grant) {
                $revoke = $this->client->databaseRevoke($db->db_name, $grant->db_user, true, $grant->host ?? 'localhost');
                if (! $revoke->successful()) {
                    return [false, "revoke {$grant->db_user}@{$grant->host}: {$revoke->body()}"];
                }
            }

            $response = $this->client->deleteDatabase($db->db_name, $db->db_user);
            if (! $response->successful()) {
                return [false, $response->body()];
            }

            DatabaseGrant::where('db_name', $db->db_name)
                ->where('account_id', $db->account_id)
                ->delete();

            $db->delete();

            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Change a database user's password on the node.
     */
    public function changePassword(HostingDatabase $db, string $password): array
    {
        try {
            $response = $this->client->changeDatabasePassword($db->db_user, $password);
            if (! $response->successful()) {
                return [false, $response->body()];
            }
            return [true, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
