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

    // ── Health / Info ─────────────────────────────────────────────────────────

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

    // ── Services ──────────────────────────────────────────────────────────────

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

    // ── Logs ──────────────────────────────────────────────────────────────────

    public function logs(string $service, int $lines = 100): Response
    {
        return $this->get("/logs/{$service}?lines={$lines}");
    }

    public function logList(): Response
    {
        return $this->get('/logs');
    }

    // ── Account provisioning ──────────────────────────────────────────────────

    public function provisionAccount(array $data): Response
    {
        return $this->post('/accounts', $data);
    }

    public function deprovisionAccount(string $username): Response
    {
        return $this->delete("/accounts/{$username}");
    }

    // ── Nginx / vhost ─────────────────────────────────────────────────────────

    public function createVhost(array $config): Response
    {
        return $this->post('/nginx/vhost', $config);
    }

    public function deleteVhost(string $domain): Response
    {
        return $this->delete("/nginx/vhost/{$domain}");
    }

    // Aliases used by DomainProvisioner.
    public function createDomain(array $config): Response
    {
        return $this->createVhost($config);
    }

    public function removeDomain(string $domain): Response
    {
        return $this->deleteVhost($domain);
    }

    // ── PHP-FPM ───────────────────────────────────────────────────────────────

    public function createPhpPool(array $config): Response
    {
        return $this->post('/php/pool', $config);
    }

    public function deletePhpPool(string $user): Response
    {
        return $this->delete("/php/pool/{$user}");
    }

    public function setPhpVersion(string $user, string $oldVersion, string $newVersion): Response
    {
        return $this->put("/php/pool/{$user}/version", [
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
        ]);
    }

    // ── SSL ───────────────────────────────────────────────────────────────────

    public function issueSSL(string $domain, array $options = []): Response
    {
        return $this->post('/ssl/issue', array_merge(['domain' => $domain], $options));
    }

    public function removeSSL(string $domain): Response
    {
        return $this->delete("/ssl/{$domain}");
    }

    // ── Mail ──────────────────────────────────────────────────────────────────

    public function provisionMailDomain(string $domain): Response
    {
        return $this->post('/mail/domain', ['domain' => $domain]);
    }

    public function deprovisionMailDomain(string $domain): Response
    {
        return $this->delete("/mail/domain/{$domain}");
    }

    public function createMailbox(array $data): Response
    {
        return $this->post('/mail/mailbox', $data);
    }

    public function deleteMailbox(string $email): Response
    {
        return $this->delete("/mail/mailbox/{$email}");
    }

    public function changeMailboxPassword(string $email, string $password): Response
    {
        return $this->put("/mail/mailbox/{$email}/password", ['password' => $password]);
    }

    public function createForwarder(array $data): Response
    {
        return $this->post('/mail/forwarder', $data);
    }

    public function deleteForwarder(string $source): Response
    {
        return $this->delete("/mail/forwarder/{$source}");
    }

    // ── DNS ───────────────────────────────────────────────────────────────────

    public function createDnsZone(string $domain): Response
    {
        return $this->post('/dns/zone', ['domain' => $domain]);
    }

    public function deleteDnsZone(string $domain): Response
    {
        return $this->delete("/dns/zone/{$domain}");
    }

    public function getDnsZone(string $domain): Response
    {
        return $this->get("/dns/zone/{$domain}");
    }

    public function upsertDnsRecord(string $domain, string $name, string $type, int $ttl, array $contents): Response
    {
        return $this->patch("/dns/zone/{$domain}/record", [
            'name'     => $name,
            'type'     => $type,
            'ttl'      => $ttl,
            'contents' => $contents,
        ]);
    }

    public function deleteDnsRecord(string $domain, string $name, string $type): Response
    {
        return $this->delete_with_body("/dns/zone/{$domain}/record", [
            'name' => $name,
            'type' => $type,
        ]);
    }

    // ── Databases ─────────────────────────────────────────────────────────────

    public function createDatabase(string $dbName, string $username, string $password): Response
    {
        return $this->post('/databases', [
            'db_name'  => $dbName,
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function deleteDatabase(string $dbName, string $username): Response
    {
        return $this->delete("/databases/{$dbName}?username={$username}");
    }

    public function changeDatabasePassword(string $username, string $password): Response
    {
        return $this->put("/databases/users/{$username}/password", ['password' => $password]);
    }

    // ── FTP ───────────────────────────────────────────────────────────────────

    public function createFtpAccount(array $data): Response
    {
        return $this->post('/ftp/accounts', $data);
    }

    public function deleteFtpAccount(string $username): Response
    {
        return $this->delete("/ftp/accounts/{$username}");
    }

    public function changeFtpPassword(string $username, string $password): Response
    {
        return $this->put("/ftp/accounts/{$username}/password", ['password' => $password]);
    }

    // ── Agent upgrade ─────────────────────────────────────────────────────────

    public function upgradeAgent(string $version, string $downloadUrl): Response
    {
        return $this->post('/agent/upgrade', [
            'version'      => $version,
            'download_url' => $downloadUrl,
        ]);
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $body = []): Response
    {
        return $this->request('POST', $path, $body);
    }

    public function put(string $path, array $body = []): Response
    {
        return $this->request('PUT', $path, $body);
    }

    public function patch(string $path, array $body = []): Response
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path): Response
    {
        return $this->request('DELETE', $path);
    }

    /** DELETE with a JSON body (for record deletion where name+type are in body). */
    public function delete_with_body(string $path, array $body): Response
    {
        return $this->request('DELETE', $path, $body);
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
            'PATCH'  => $http->patch($url, $body),
            'DELETE' => $body ? $http->withBody(json_encode($body), 'application/json')->delete($url) : $http->delete($url),
            default  => throw new \InvalidArgumentException("Unknown method: {$method}"),
        };
    }
}
