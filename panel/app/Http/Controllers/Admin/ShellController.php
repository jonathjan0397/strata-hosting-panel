<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShellController extends Controller
{
    /**
     * Render the browser-based SSH terminal for a node.
     *
     * A short-lived HMAC token is generated and passed to the frontend so the
     * browser can open a WebSocket directly to the agent without exposing the
     * full HMAC secret.  Token TTL: 60 seconds.
     */
    public function show(Request $request, Node $node): Response
    {
        $ts  = time();
        $sig = hash_hmac('sha256', "shell:{$ts}", $node->hmac_secret);

        // Build the WebSocket URL using the node's public hostname so the
        // browser TLS certificate check passes (the agent uses the panel cert).
        // Fall back to ip_address only if hostname is not set.
        $host   = $node->hostname ?: $node->ip_address;
        $wsUrl  = "wss://{$host}:{$node->port}/v1/shell?ts={$ts}&sig={$sig}";

        return Inertia::render('Admin/Shell', [
            'node'  => $node->only('id', 'name', 'hostname', 'ip_address'),
            'wsUrl' => $wsUrl,
        ]);
    }
}
