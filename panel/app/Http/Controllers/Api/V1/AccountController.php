<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Node;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    /**
     * POST /api/v1/accounts
     * Provision a new hosting account.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:100'],
            'email'              => ['required', 'email', 'unique:users,email'],
            'username'           => ['required', 'regex:/^[a-z][a-z0-9_]{1,31}$/', 'unique:accounts,username'],
            'password'           => ['nullable', 'string', 'min:12'],
            'node_id'            => ['nullable', 'exists:nodes,id'],
            'php_version'        => ['nullable', 'in:8.1,8.2,8.3'],
            'disk_limit_mb'      => ['nullable', 'integer', 'min:0'],
            'bandwidth_limit_mb' => ['nullable', 'integer', 'min:0'],
            'max_domains'        => ['nullable', 'integer', 'min:0'],
            'max_email_accounts' => ['nullable', 'integer', 'min:0'],
            'max_databases'      => ['nullable', 'integer', 'min:0'],
        ]);

        $node = isset($data['node_id'])
            ? Node::findOrFail($data['node_id'])
            : Node::where('status', 'online')->first();

        if (! $node) {
            return response()->json(['error' => 'No online node available.'], 503);
        }

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password'] ?? Str::password(16)),
        ]);
        $user->assignRole('user');

        $account = Account::create([
            'user_id'            => $user->id,
            'node_id'            => $node->id,
            'username'           => $data['username'],
            'php_version'        => $data['php_version'] ?? '8.2',
            'status'             => 'active',
            'disk_limit_mb'      => $data['disk_limit_mb'] ?? 0,
            'bandwidth_limit_mb' => $data['bandwidth_limit_mb'] ?? 0,
            'max_domains'        => $data['max_domains'] ?? 0,
            'max_email_accounts' => $data['max_email_accounts'] ?? 0,
            'max_databases'      => $data['max_databases'] ?? 0,
        ]);

        try {
            (new AccountProvisioner($account))->provision();
        } catch (\Throwable $e) {
            $account->delete();
            $user->delete();
            return response()->json(['error' => 'Provisioning failed: ' . $e->getMessage()], 500);
        }

        AuditLog::record('api.account_created', $account, ['by' => 'api']);

        return response()->json([
            'id'       => $account->id,
            'username' => $account->username,
            'status'   => $account->status,
        ], 201);
    }

    /**
     * POST /api/v1/accounts/{id}/suspend
     */
    public function suspend(Request $request, Account $account): JsonResponse
    {
        if ($account->isSuspended()) {
            return response()->json(['error' => 'Account already suspended.'], 409);
        }

        $account->update(['status' => 'suspended', 'suspended_at' => now()]);
        AuditLog::record('api.account_suspended', $account, ['by' => 'api']);

        return response()->json(['status' => 'suspended']);
    }

    /**
     * POST /api/v1/accounts/{id}/unsuspend
     */
    public function unsuspend(Request $request, Account $account): JsonResponse
    {
        if ($account->isActive()) {
            return response()->json(['error' => 'Account is already active.'], 409);
        }

        $account->update(['status' => 'active', 'suspended_at' => null]);
        AuditLog::record('api.account_unsuspended', $account, ['by' => 'api']);

        return response()->json(['status' => 'active']);
    }

    /**
     * DELETE /api/v1/accounts/{id}
     */
    public function destroy(Account $account): JsonResponse
    {
        try {
            (new AccountProvisioner($account))->deprovision();
        } catch (\Throwable $e) {
            // Log but don't block — still delete from panel
        }

        AuditLog::record('api.account_terminated', $account, ['username' => $account->username, 'by' => 'api']);

        $account->user?->delete();
        $account->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /api/v1/accounts/{id}/usage
     */
    public function usage(Account $account): JsonResponse
    {
        $account->loadCount(['domains', 'databases' => fn ($q) => $q->getModel()::query()]);

        return response()->json([
            'id'                 => $account->id,
            'username'           => $account->username,
            'status'             => $account->status,
            'disk_used_mb'       => $account->disk_used_mb,
            'disk_limit_mb'      => $account->disk_limit_mb,
            'bandwidth_used_mb'  => $account->bandwidth_used_mb,
            'bandwidth_limit_mb' => $account->bandwidth_limit_mb,
            'domains'            => $account->domains()->count(),
            'max_domains'        => $account->max_domains,
            'email_accounts'     => $account->emailAccounts()->count(),
            'max_email_accounts' => $account->max_email_accounts,
            'databases'          => $account->databases()->count(),
            'max_databases'      => $account->max_databases,
        ]);
    }
}
