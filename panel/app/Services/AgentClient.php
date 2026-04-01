<?php

namespace App\Services;

use App\Models\Node;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AgentClient
{
    public function __construct(private readonly Node $node) {}

    public static function for(Node $node): static
    {
        return new static($node);
    }

    public function health(): Response
    {
        return $this->get('/health');
    }

    public function version(): Response
    {
        return $this->get('/version');
    }

    public function systemInfo(): Response
    {
        return $this->get('/system/info');
    }

    public function services(): Response
    {
        return $this->get('/services');
    }

    public function startService(string $name): Response
    {
        return $this->post("/services/{$name}/start");
    }

    public function stopService(string $name): Response
    {
        return $this->post("/services/{$name}/stop");
    }

    public function restartService(string $name): Response
    {
        return $this->post("/services/{$name}/restart");
    }

    public function reloadService(string $name): Response
    {
        return $this->post("/services/{$name}/reload");
    }

    public function logs(string $service, int $lines = 100): Response
    {
        return $this->get("/logs/{$service}?lines={$lines}");
    }

    public function logList(): Response
    {
        return $this->get('/logs');
    }

    public function createVhost(array $config): Response
    {
        return $this->post('/nginx/vhost', $config);
    }

    public function deleteVhost(string $domain): Response
    {
        return $this->delete("/nginx/vhost/{$domain}");
    }

    public function createPhpPool(array $config): Response
    {
        return $this->post('/php/pool', $config);
    }

    public function deletePhpPool(string $user): Response
    {
        return $this->delete("/php/pool/{$user}");
    }

    public function setPhpVersion(string $user, string $version): Response
    {
        return $this->put("/php/pool/{$user}/version", ['version' => $version]);
    }

    public function issueSSL(string $domain, array $options = []): Response
    {
        return $this->post('/ssl/issue', array_merge(['domain' => $domain], $options));
    }

    public function upgradeAgent(string $version, string $downloadUrl): Response
    {
        return $this->post('/agent/upgrade', [
            'version'      => $version,
            'download_url' => $downloadUrl,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internal HTTP helpers
    // -----------------------------------------------------------------------

    private function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $body = []): Response
    {
        return $this->request('POST', $path, $body);
    }

    private function put(string $path, array $body = []): Response
    {
        return $this->request('PUT', $path, $body);
    }

    private function delete(string $path): Response
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $body = []): Response
    {
        $timestamp = (string) time();
        $bodyJson  = $body ? json_encode($body) : '';
        $signature = hash_hmac('sha256', $timestamp . "\n" . $bodyJson, $this->node->hmac_secret);

        $http = Http::withHeaders([
            'X-Strata-Signature' => $signature,
            'X-Strata-Timestamp' => $timestamp,
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
        ])
            ->timeout(30)
            ->withoutVerifying(); // TODO: verify against stored TLS fingerprint

        $url = $this->node->apiUrl($path);

        return match ($method) {
            'GET'    => $http->get($url),
            'POST'   => $http->post($url, $body),
            'PUT'    => $http->put($url, $body),
            'DELETE' => $http->delete($url),
            default  => throw new \InvalidArgumentException("Unknown method: {$method}"),
        };
    }
}
