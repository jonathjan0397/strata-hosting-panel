<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GitController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);
        $domains = $account->domains()
            ->orderBy('domain')
            ->get(['id', 'domain', 'document_root']);

        return Inertia::render('User/Git', [
            'account' => [
                'id' => $account->id,
                'username' => $account->username,
            ],
            'paths' => $domains
                ->map(fn (Domain $domain) => [
                    'id' => $domain->id,
                    'label' => $domain->domain,
                    'path' => $this->relativePath($account, $domain->document_root),
                ])
                ->prepend([
                    'id' => 'public_html',
                    'label' => 'Primary web root',
                    'path' => '/public_html',
                ])
                ->values(),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
        ]);

        $account = $this->account($request);
        $response = AgentClient::for($account->node)->gitStatus($account->username, $data['path']);

        return $this->relay($response);
    }

    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
        ]);

        $account = $this->account($request);
        $response = AgentClient::for($account->node)->gitInit($account->username, $data['path']);

        return $this->relay($response);
    }

    public function clone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'remote_url' => ['required', 'url:http,https'],
            'branch' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $this->account($request);
        $response = AgentClient::for($account->node)->gitClone(
            $account->username,
            $data['path'],
            $data['remote_url'],
            $data['branch'] ?? null,
        );

        return $this->relay($response);
    }

    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
        ]);

        $account = $this->account($request);
        $response = AgentClient::for($account->node)->gitPull($account->username, $data['path']);

        return $this->relay($response);
    }

    private function account(Request $request): Account
    {
        return $request->user()
            ->account()
            ->with('node')
            ->firstOrFail();
    }

    private function relay($response): JsonResponse
    {
        if (! $response->successful()) {
            return response()->json([
                'error' => $response->body(),
            ], $response->status());
        }

        return response()->json($response->json(), $response->status());
    }

    private function relativePath(Account $account, string $absolutePath): string
    {
        $prefix = "/var/www/{$account->username}";

        if (! str_starts_with($absolutePath, $prefix)) {
            return '/public_html';
        }

        $path = substr($absolutePath, strlen($prefix));

        return $path === '' ? '/' : $path;
    }
}
