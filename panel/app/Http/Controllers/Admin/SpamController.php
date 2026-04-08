<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpamController extends Controller
{
    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->get(['id', 'name']);
        return Inertia::render('Admin/Security/Spam', ['nodes' => $nodes]);
    }

    public function stats(Request $request)
    {
        $node = Node::findOrFail($request->query('node_id'));
        $client   = AgentClient::for($node);
        $response = $client->rspamdStats();

        if (! $response->successful()) {
            return response()->json([
                'error' => trim($response->body()) !== '' ? trim($response->body()) : 'Rspamd unreachable',
            ], $response->status() ?: 503);
        }

        return response()->json($response->json());
    }
}
