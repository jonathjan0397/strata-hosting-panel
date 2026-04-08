<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    // Fail2Ban

    public function index(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Security/Index', [
            'nodes' => $nodes,
        ]);
    }

    public function fail2banIndex(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Security/Fail2Ban', [
            'nodes' => $nodes,
        ]);
    }

    public function fail2banStatus(Request $request): JsonResponse
    {
        $node     = Node::findOrFail($request->query('node_id'));
        $response = AgentClient::for($node)->fail2banStatus();
        $configResponse = AgentClient::for($node)->fail2banConfig();
        $services = AgentClient::for($node)->services();
        $payload = $response->successful() ? $response->json() : ['jails' => [], 'error' => $response->body()];
        if ($configResponse->successful()) {
            $payload['config'] = $configResponse->json();
        } else {
            $payload['config_error'] = $configResponse->body();
        }
        $payload['service'] = collect($services->successful() ? $services->json() : [])
            ->firstWhere('name', 'fail2ban');

        return response()->json(
            $payload,
            $response->successful() ? 200 : 502
        );
    }

    public function ban(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'jail'    => ['required', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'ip'      => ['required', 'ip'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->fail2banBan($data['jail'], $data['ip']);

        if (! $response->successful()) {
            return response()->json(['message' => 'Ban failed: ' . $response->body()], 502);
        }

        AuditLog::record('security.fail2ban_ban', null, ['jail' => $data['jail'], 'ip' => $data['ip'], 'node' => $node->name]);

        return response()->json(['status' => 'ok', 'message' => "{$data['ip']} banned in {$data['jail']}."]);
    }

    public function unban(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'jail'    => ['required', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'ip'      => ['required', 'ip'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->fail2banUnban($data['jail'], $data['ip']);

        if (! $response->successful()) {
            return response()->json(['message' => 'Unban failed: ' . $response->body()], 502);
        }

        AuditLog::record('security.fail2ban_unban', null, ['jail' => $data['jail'], 'ip' => $data['ip'], 'node' => $node->name]);

        return response()->json(['status' => 'ok', 'message' => "{$data['ip']} unbanned from {$data['jail']}."]);
    }

    public function fail2banService(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'action'  => ['required', 'in:start,stop,restart'],
        ]);

        $node = Node::findOrFail($data['node_id']);
        $client = AgentClient::for($node);
        $response = match ($data['action']) {
            'start' => $client->startService('fail2ban'),
            'stop' => $client->stopService('fail2ban'),
            'restart' => $client->restartService('fail2ban'),
        };

        if (! $response->successful()) {
            return response()->json(['message' => "Fail2Ban {$data['action']} failed: " . $response->body()], 502);
        }

        AuditLog::record("security.fail2ban_{$data['action']}", $node, [
            'service' => 'fail2ban',
            'node' => $node->name,
        ]);

        return response()->json(['status' => 'ok', 'message' => "Fail2Ban {$data['action']} completed."]);
    }

    public function fail2banUpdateConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'defaults' => ['required', 'array'],
            'defaults.bantime' => ['required', 'integer', 'min:1'],
            'defaults.findtime' => ['required', 'integer', 'min:1'],
            'defaults.maxretry' => ['required', 'integer', 'min:1'],
            'jails' => ['required', 'array', 'min:1'],
            'jails.*.name' => ['required', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'jails.*.enabled' => ['required', 'boolean'],
            'jails.*.maxretry' => ['nullable', 'integer', 'min:1'],
            'jails.*.findtime' => ['nullable', 'integer', 'min:1'],
            'jails.*.bantime' => ['nullable', 'integer', 'min:1'],
        ]);

        $node = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->fail2banUpdateConfig([
            'defaults' => $data['defaults'],
            'jails' => collect($data['jails'])->map(fn ($jail) => [
                'name' => $jail['name'],
                'enabled' => (bool) $jail['enabled'],
                'maxretry' => $jail['maxretry'] ?? null,
                'findtime' => $jail['findtime'] ?? null,
                'bantime' => $jail['bantime'] ?? null,
            ])->values()->all(),
        ]);

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Fail2Ban settings update failed: ' . $response->body(),
            ], 502);
        }

        AuditLog::record('security.fail2ban_config_updated', $node, [
            'node' => $node->name,
            'defaults' => $data['defaults'],
            'jails' => $data['jails'],
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Fail2Ban settings saved.',
            'config' => $response->json(),
        ]);
    }

    // Firewall (UFW)

    public function firewallIndex(): Response
    {
        $nodes = Node::where('status', 'online')->select('id', 'name', 'hostname')->get();

        return Inertia::render('Admin/Security/Firewall', [
            'nodes' => $nodes,
        ]);
    }

    public function firewallRules(Request $request): JsonResponse
    {
        $node     = Node::findOrFail($request->query('node_id'));
        $response = AgentClient::for($node)->firewallRules();

        return response()->json(
            $response->successful() ? $response->json() : ['status' => 'unknown', 'rules' => [], 'error' => $response->body()],
            $response->successful() ? 200 : 502
        );
    }

    public function firewallAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'type'    => ['required', 'in:allow,deny'],
            'port'    => ['required', 'string', 'regex:/^\d{1,5}(:\d{1,5})?$/'],
            'proto'   => ['nullable', 'in:tcp,udp'],
            'from'    => ['nullable', 'ip'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->firewallAddRule(
            $data['type'],
            $data['port'],
            $data['proto'] ?? '',
            $data['from'] ?? '',
        );

        if ($response->successful()) {
            AuditLog::record('firewall.add', null, [
                'node' => $node->name,
                'type' => $data['type'],
                'port' => $data['port'],
                'from' => $data['from'] ?? 'any',
            ]);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'Firewall update failed: ' . $response->body()], 502);
        }

        return response()->json($response->json(), 201);
    }

    public function firewallBlockIp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'ip' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->isIpOrCidr((string) $value)) {
                        $fail('Enter a valid IP address or CIDR range.');
                    }
                },
            ],
        ]);

        $node = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->firewallBlockIp($data['ip']);

        if (! $response->successful()) {
            return response()->json(['message' => 'IP block failed: ' . $response->body()], 502);
        }

        AuditLog::record('firewall.ip_blocked', null, [
            'node' => $node->name,
            'ip' => $data['ip'],
        ]);

        return response()->json($response->json(), 201);
    }

    private function isIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (! str_contains($value, '/')) {
            return false;
        }

        [$address, $prefix] = explode('/', $value, 2);

        if (! ctype_digit($prefix) || ! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        $prefix = (int) $prefix;
        $isIpv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        return $prefix >= 0 && $prefix <= ($isIpv6 ? 128 : 32);
    }

    public function firewallDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:nodes,id'],
            'number'  => ['required', 'integer', 'min:1'],
        ]);

        $node     = Node::findOrFail($data['node_id']);
        $response = AgentClient::for($node)->firewallDeleteRule((int) $data['number']);

        if ($response->successful()) {
            AuditLog::record('firewall.delete', null, [
                'node'        => $node->name,
                'rule_number' => $data['number'],
            ]);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'Firewall delete failed: ' . $response->body()], 502);
        }

        return response()->json($response->json(), 200);
    }
}
