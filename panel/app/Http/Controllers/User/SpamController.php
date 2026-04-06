<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpamController extends Controller
{
    public function index(): Response
    {
        $account = auth()->user()->account()->with('node')->firstOrFail();

        return Inertia::render('User/Email/Spam', [
            'account' => [
                'id' => $account->id,
                'username' => $account->username,
                'node' => $account->node?->only(['id', 'name', 'hostname']),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $account = $request->user()->account()->with('node')->firstOrFail();
        $response = AgentClient::for($account->node)->rspamdStats();

        if (! $response->successful()) {
            return response()->json(['error' => 'Rspamd unreachable'], 503);
        }

        return response()->json($response->json());
    }
}
