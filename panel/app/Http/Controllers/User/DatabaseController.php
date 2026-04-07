<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
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
    private function account()
    {
        return auth()->user()->account()->with('node')->firstOrFail();
    }

    public function index(): Response
    {
        $account   = $this->account();
        $databases = HostingDatabase::where('account_id', $account->id)->latest()->get();

        return Inertia::render('User/Database/Index', [
            'account'   => $account,
            'databases' => $databases,
            'grants' => DatabaseGrant::where('account_id', $account->id)
                ->latest()
                ->get(['id', 'engine', 'db_name', 'db_user', 'host', 'password_hint', 'created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->account();

        if ($account->isSuspended()) {
            return back()->with('error', 'Your account is suspended.');
        }

        if ($account->max_databases > 0) {
            $current = HostingDatabase::where('account_id', $account->id)->count();
            if ($current >= $account->max_databases) {
                return back()->with('error', "Database limit reached ({$account->max_databases}).");
            }
        }

        $data = $request->validate([
            'db_name'  => ['required', 'regex:/^[a-z][a-z0-9_]{0,47}$/', 'unique:hosting_databases,db_name'],
            'db_user'  => ['required', 'regex:/^[a-z][a-z0-9_]{0,15}$/', 'unique:hosting_databases,db_user'],
            'engine'   => ['required', 'in:mysql,postgresql'],
            'password' => ['required', 'string', 'min:8'],
            'note'     => ['nullable', 'string', 'max:255'],
        ]);

        $provisioner = new DatabaseProvisioner(AgentClient::for($account->node));

        [$success, $error] = $provisioner->create(
            $account,
            $data['db_name'],
            $data['db_user'],
            $data['password'],
            $data['note'] ?? null,
            $data['engine'],
        );

        return $success
            ? back()->with('success', "{$data['db_name']} created.")
            : back()->with('error', "Database creation failed: {$error}");
    }

    public function destroy(HostingDatabase $database): RedirectResponse
    {
        $account = $this->account();
        abort_unless($database->account_id === $account->id, 403);

        $provisioner = new DatabaseProvisioner(AgentClient::for($database->node));
        [$success, $error] = $provisioner->delete($database);

        return $success
            ? redirect()->route('my.databases.index')->with('success', "{$database->db_name} deleted.")
            : back()->with('error', "Deletion failed: {$error}");
    }

    public function changePassword(Request $request, HostingDatabase $database): RedirectResponse
    {
        $account = $this->account();
        abort_unless($database->account_id === $account->id, 403);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $provisioner = new DatabaseProvisioner(AgentClient::for($database->node));
        [$success, $error] = $provisioner->changePassword($database, $data['password']);

        return $success
            ? back()->with('success', "Password updated for {$database->db_user}.")
            : back()->with('error', "Password change failed: {$error}");
    }

    public function grantUser(Request $request, HostingDatabase $database): RedirectResponse
    {
        $account = $this->account();
        abort_unless($database->account_id === $account->id, 403);

        $data = $request->validate([
            'db_user'  => ['required', 'regex:/^[a-z][a-z0-9_]{0,15}$/'],
            'password' => ['required', 'string', 'min:8'],
            'host'     => ['nullable', 'regex:/^(localhost|%|[A-Za-z0-9][A-Za-z0-9._%-]{0,252})$/'],
        ]);
        $host = $data['host'] ?: 'localhost';

        $client   = AgentClient::for($account->node);
        $engine = $database->engine ?? 'mysql';
        if ($engine === 'postgresql' && $host !== 'localhost') {
            return back()->with('error', 'PostgreSQL remote host access requires pg_hba.conf/listen_addresses configuration on the node.');
        }

        $response = $client->databaseGrant($database->db_name, $data['db_user'], $data['password'], $host, $engine);

        if (! $response->successful()) {
            return back()->with('error', 'Grant failed: ' . $response->body());
        }

        DatabaseGrant::updateOrCreate(
            ['db_name' => $database->db_name, 'db_user' => $data['db_user'], 'host' => $host],
            ['account_id' => $account->id, 'node_id' => $account->node_id, 'engine' => $engine, 'password_hint' => substr($data['password'], 0, 3) . '***'],
        );

        return back()->with('success', "User {$data['db_user']} granted access from {$host}.");
    }

    public function revokeUser(Request $request, HostingDatabase $database): RedirectResponse
    {
        $account = $this->account();
        abort_unless($database->account_id === $account->id, 403);

        $data = $request->validate([
            'db_user' => ['required', 'string'],
            'host' => ['nullable', 'regex:/^(localhost|%|[A-Za-z0-9][A-Za-z0-9._%-]{0,252})$/'],
        ]);
        $host = $data['host'] ?: 'localhost';

        $client = AgentClient::for($account->node);
        $response = $client->databaseRevoke($database->db_name, $data['db_user'], true, $host, $database->engine ?? 'mysql');

        if (! $response->successful()) {
            return back()->with('error', 'Revoke failed: ' . $response->body());
        }

        DatabaseGrant::where('db_name', $database->db_name)
            ->where('db_user', $data['db_user'])
            ->where('host', $host)
            ->delete();

        return back()->with('success', "Access revoked for {$data['db_user']} from {$host}.");
    }
}
