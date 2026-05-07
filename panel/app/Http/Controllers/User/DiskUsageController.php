<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiskUsageController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);

        return Inertia::render('User/DiskUsage', [
            'account' => [
                'id' => $account->id,
                'username' => $account->username,
                'disk_used_mb' => $account->disk_used_mb,
                'disk_limit_mb' => $account->disk_limit_mb,
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $this->account($request);
        $response = AgentClient::for($account->node)->fileDiskUsage(
            $account->username,
            $data['path'] ?? '/',
        );

        if (! $response->successful()) {
            return response()->json([
                'error' => $response->body(),
            ], $response->status());
        }

        return response()->json($response->json(), $response->status());
    }

    private function account(Request $request): Account
    {
        return $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();
    }
}
