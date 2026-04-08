<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailQueueController extends Controller
{
    public function index(Request $request): Response
    {
        $nodes = Node::where('status', 'online')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get(['id', 'name', 'hostname', 'is_primary']);

        $selectedNode = $this->selectedNode($request);
        $queue = null;
        $error = null;

        if ($selectedNode) {
            $response = AgentClient::for($selectedNode)->mailQueue();
            if ($response->successful()) {
                $queue = $response->json();
            } else {
                $error = trim($response->body()) ?: 'Unable to read the mail queue.';
            }
        }

        return Inertia::render('Admin/Email/MailQueue', [
            'nodes' => $nodes->map(fn (Node $node) => [
                'id' => $node->id,
                'name' => $node->name,
                'hostname' => $node->hostname,
                'is_primary' => $node->is_primary,
            ]),
            'selectedNodeId' => $selectedNode?->id,
            'queue' => $queue,
            'error' => $error,
        ]);
    }

    public function flush(Request $request): RedirectResponse
    {
        $node = $this->validatedNode($request);
        $response = AgentClient::for($node)->mailQueueFlush();

        if (! $response->successful()) {
            return back()->with('error', trim($response->body()) ?: 'Mail queue flush failed.');
        }

        AuditLog::record('mail.queue_flushed', $node, ['node' => $node->name]);

        return back()->with('status', 'Mail queue flush requested.');
    }

    public function delete(Request $request, string $queueId): RedirectResponse
    {
        $node = $this->validatedNode($request);
        $queueId = strtoupper(trim($queueId));

        if (! preg_match('/^[A-F0-9]{5,}$/', $queueId)) {
            return back()->with('error', 'Invalid Postfix queue ID.');
        }

        $response = AgentClient::for($node)->mailQueueDelete($queueId);

        if (! $response->successful()) {
            return back()->with('error', trim($response->body()) ?: 'Queued message deletion failed.');
        }

        AuditLog::record('mail.queue_message_deleted', $node, [
            'node' => $node->name,
            'queue_id' => $queueId,
        ]);

        return back()->with('status', "Deleted queued message {$queueId}.");
    }

    public function deleteAll(Request $request): RedirectResponse
    {
        $node = $this->validatedNode($request);
        $data = $request->validate([
            'confirm' => ['required', 'string', 'in:DELETE'],
        ]);

        $response = AgentClient::for($node)->mailQueueDeleteAll();

        if (! $response->successful()) {
            return back()->with('error', trim($response->body()) ?: 'Mail queue purge failed.');
        }

        AuditLog::record('mail.queue_purged', $node, [
            'node' => $node->name,
            'confirm' => $data['confirm'],
        ]);

        return back()->with('status', 'Mail queue purged.');
    }

    private function selectedNode(Request $request): ?Node
    {
        $nodeId = $request->query('node_id');
        if ($nodeId) {
            return Node::where('status', 'online')->findOrFail($nodeId);
        }

        return Node::where('status', 'online')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->first();
    }

    private function validatedNode(Request $request): Node
    {
        $data = $request->validate([
            'node_id' => ['required', 'integer', 'exists:nodes,id'],
        ]);

        return Node::where('status', 'online')->findOrFail($data['node_id']);
    }
}
