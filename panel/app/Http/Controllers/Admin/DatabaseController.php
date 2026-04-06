<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\DatabaseGrant;
use App\Models\HostingDatabase;
use App\Services\AgentClient;
use App\Services\DatabaseProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    /**
     * Database management page for an account.
     */
    public function index(Account $account): Response
    {
        $account->load('node');
        $databases = HostingDatabase::where('account_id', $account->id)->latest()->get();

        return Inertia::render('Admin/Database/Index', [
            'account'   => $account,
            'databases' => $databases,
            'grants' => DatabaseGrant::where('account_id', $account->id)
                ->latest()
                ->get(['id', 'db_name', 'db_user', 'host', 'password_hint', 'created_at']),
        ]);
    }

    /**
     * Create a new database + user for the account.
     */
    public function store(Request $request, Account $account): RedirectResponse
    {
        // Enforce account database limit.
        if ($account->max_databases > 0) {
            $current = HostingDatabase::where('account_id', $account->id)->count();
            if ($current >= $account->max_databases) {
                return back()->with('error', "Database limit reached ({$account->max_databases}).");
            }
        }

        $data = $request->validate([
            'db_name'  => ['required', 'regex:/^[a-z][a-z0-9_]{0,47}$/', 'unique:hosting_databases,db_name'],
            'db_user'  => ['required', 'regex:/^[a-z][a-z0-9_]{0,15}$/', 'unique:hosting_databases,db_user'],
            'password' => ['required', 'string', 'min:8'],
            'note'     => ['nullable', 'string', 'max:255'],
        ]);

        $client   = AgentClient::for($account->node);
        $provisioner = new DatabaseProvisioner($client);

        [$success, $error] = $provisioner->create(
            $account,
            $data['db_name'],
            $data['db_user'],
            $data['password'],
            $data['note'] ?? null,
        );

        if ($success) {
            AuditLog::record('database.created', $account, [
                'db_name' => $data['db_name'], 'db_user' => $data['db_user'],
            ]);
        }

        return $success
            ? back()->with('success', "{$data['db_name']} created.")
            : back()->with('error', "Database creation failed: {$error}");
    }

    /**
     * Delete a database and its associated user.
     */
    public function destroy(HostingDatabase $database): RedirectResponse
    {
        $account = $database->account;
        $client  = AgentClient::for($database->node);
        $provisioner = new DatabaseProvisioner($client);

        [$success, $error] = $provisioner->delete($database);

        if ($success) {
            AuditLog::record('database.deleted', $account, [
                'db_name' => $database->db_name, 'db_user' => $database->db_user,
            ]);
        }

        return $success
            ? redirect()->route('admin.accounts.databases', $account->id)
                ->with('success', "{$database->db_name} deleted.")
            : back()->with('error', "Deletion failed: {$error}");
    }

    /**
     * Change the password for a database user.
     */
    public function changePassword(Request $request, HostingDatabase $database): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $client = AgentClient::for($database->node);
        $provisioner = new DatabaseProvisioner($client);

        [$success, $error] = $provisioner->changePassword($database, $data['password']);

        return $success
            ? back()->with('success', "Password updated for {$database->db_user}.")
            : back()->with('error', "Password change failed: {$error}");
    }

    public function grantUser(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'db_name'  => ['required', 'string'],
            'db_user'  => ['required', 'regex:/^[a-z][a-z0-9_]{0,15}$/'],
            'password' => ['required', 'string', 'min:8'],
            'host'     => ['nullable', 'regex:/^(localhost|%|[A-Za-z0-9][A-Za-z0-9._%-]{0,252})$/'],
        ]);
        $host = $data['host'] ?: 'localhost';

        $client   = AgentClient::for($account->node);
        $response = $client->databaseGrant($data['db_name'], $data['db_user'], $data['password'], $host);

        if (! $response->successful()) {
            return back()->with('error', 'Grant failed: ' . $response->body());
        }

        DatabaseGrant::updateOrCreate(
            ['db_name' => $data['db_name'], 'db_user' => $data['db_user'], 'host' => $host],
            ['account_id' => $account->id, 'node_id' => $account->node_id, 'password_hint' => substr($data['password'], 0, 3) . '***'],
        );

        AuditLog::record('database.grant', $account, ['db_name' => $data['db_name'], 'db_user' => $data['db_user'], 'host' => $host]);

        return back()->with('success', "User {$data['db_user']} granted to {$data['db_name']} from {$host}.");
    }

    public function revokeUser(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'db_name' => ['required', 'string'],
            'db_user' => ['required', 'string'],
            'host' => ['nullable', 'regex:/^(localhost|%|[A-Za-z0-9][A-Za-z0-9._%-]{0,252})$/'],
        ]);
        $host = $data['host'] ?: 'localhost';

        $client   = AgentClient::for($account->node);
        $response = $client->databaseRevoke($data['db_name'], $data['db_user'], true, $host);

        if (! $response->successful()) {
            return back()->with('error', 'Revoke failed: ' . $response->body());
        }

        DatabaseGrant::where('db_name', $data['db_name'])
            ->where('db_user', $data['db_user'])
            ->where('host', $host)
            ->delete();

        AuditLog::record('database.revoke', $account, ['db_name' => $data['db_name'], 'db_user' => $data['db_user'], 'host' => $host]);

        return back()->with('success', "Access revoked for {$data['db_user']} from {$host}.");
    }
}
