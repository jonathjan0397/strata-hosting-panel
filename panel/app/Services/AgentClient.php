<?php

namespace App\Services;

use App\Models\Node;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AgentClient
{
    public function __construct(public readonly Node $node) {}

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

    public function listDnsZones(): Response
    {
        return $this->get('/dns/zones');
    }

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

    public function createDatabase(string $dbName, string $username, string $password, string $engine = 'mysql'): Response
    {
        return $this->post('/databases', [
            'db_name'  => $dbName,
            'username' => $username,
            'password' => $password,
            'engine'   => $engine,
        ]);
    }

    public function deleteDatabase(string $dbName, string $username, string $engine = 'mysql'): Response
    {
        return $this->delete("/databases/{$dbName}?username={$username}&engine={$engine}");
    }

    public function changeDatabasePassword(string $username, string $password, string $engine = 'mysql'): Response
    {
        return $this->put("/databases/users/{$username}/password", ['password' => $password, 'engine' => $engine]);
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

    // ── File manager ──────────────────────────────────────────────────────────

    public function fileList(string $username, string $path = '/'): Response
    {
        return $this->get("/files/{$username}?path=" . urlencode($path));
    }

    public function fileDiskUsage(string $username, string $path = '/'): Response
    {
        return $this->get("/files/{$username}/disk-usage?path=" . urlencode($path));
    }

    public function fileRead(string $username, string $path): Response
    {
        return $this->get("/files/{$username}/read?path=" . urlencode($path));
    }

    public function fileTail(string $username, string $path, int $lines = 100): Response
    {
        return $this->get("/files/{$username}/tail?path=" . urlencode($path) . "&lines={$lines}");
    }

    public function fileWrite(string $username, string $path, string $content): Response
    {
        return $this->post("/files/{$username}/write", ['path' => $path, 'content' => $content]);
    }

    public function fileMkdir(string $username, string $path): Response
    {
        return $this->post("/files/{$username}/mkdir", ['path' => $path]);
    }

    public function fileRename(string $username, string $from, string $to): Response
    {
        return $this->post("/files/{$username}/rename", ['from' => $from, 'to' => $to]);
    }

    public function fileDelete(string $username, string $path): Response
    {
        return $this->delete("/files/{$username}?path=" . urlencode($path));
    }

    public function fileChmod(string $username, string $path, string $mode): Response
    {
        return $this->post("/files/{$username}/chmod", ['path' => $path, 'mode' => $mode]);
    }

    public function fileCompress(string $username, array $paths, string $dest, string $format = 'zip'): Response
    {
        return $this->post("/files/{$username}/compress", [
            'paths'  => $paths,
            'dest'   => $dest,
            'format' => $format,
        ]);
    }

    public function fileExtract(string $username, string $path, string $dest = ''): Response
    {
        return $this->post("/files/{$username}/extract", ['path' => $path, 'dest' => $dest]);
    }

    public function fileDownloadUrl(string $username, string $path): string
    {
        return $this->node->apiUrl("/files/{$username}/download?path=" . urlencode($path));
    }

    public function gitStatus(string $username, string $path = '/public_html'): Response
    {
        return $this->get("/git/{$username}/status?path=" . urlencode($path));
    }

    public function gitInit(string $username, string $path): Response
    {
        return $this->post("/git/{$username}/init", ['path' => $path]);
    }

    public function gitClone(string $username, string $path, string $remoteUrl, ?string $branch = null): Response
    {
        return $this->post("/git/{$username}/clone", array_filter([
            'path' => $path,
            'remote_url' => $remoteUrl,
            'branch' => $branch,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function gitPull(string $username, string $path): Response
    {
        return $this->post("/git/{$username}/pull", ['path' => $path]);
    }

    public function malwareScan(string $username, string $path = '/', bool $quarantine = false): Response
    {
        return $this->request('POST', "/malware/{$username}/scan", [
            'path' => $path,
            'quarantine' => $quarantine,
        ], timeout: 650);
    }

    // ── Backups ───────────────────────────────────────────────────────────────

    public function backupCreate(string $username, string $type = 'full'): Response
    {
        return $this->post("/backups/{$username}", ['type' => $type]);
    }

    public function backupList(string $username): Response
    {
        return $this->get("/backups/{$username}");
    }

    public function backupDelete(string $username, string $filename): Response
    {
        return $this->delete("/backups/{$username}/" . urlencode($filename));
    }

    public function backupDownload(string $username, string $filename): Response
    {
        return $this->get("/backups/{$username}/download/" . urlencode($filename));
    }

    public function backupUpload(string $username, string $filename, string $contents): Response
    {
        $boundary = 'strata-' . bin2hex(random_bytes(16));
        $eol = "\r\n";
        $body = "--{$boundary}{$eol}";
        $body .= 'Content-Disposition: form-data; name="filename"' . "{$eol}{$eol}";
        $body .= $filename . $eol;
        $body .= "--{$boundary}{$eol}";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . addslashes($filename) . "\"{$eol}";
        $body .= "Content-Type: application/gzip{$eol}{$eol}";
        $body .= $contents . $eol;
        $body .= "--{$boundary}--{$eol}";

        return $this->requestRaw('POST', "/backups/{$username}/upload", $body, "multipart/form-data; boundary={$boundary}", timeout: 600);
    }

    // ── PHP settings ──────────────────────────────────────────────────────────

    public function updatePhpSettings(string $username, string $phpVersion, array $settings): Response
    {
        return $this->put("/php/pool/{$username}/settings", array_merge(
            ['php_version' => $phpVersion],
            $settings,
        ));
    }

    // ── Backup restore ────────────────────────────────────────────────────────

    public function backupRestore(string $username, string $filename): Response
    {
        return $this->post("/backups/{$username}/restore/" . urlencode($filename));
    }

    public function backupRestorePath(string $username, string $filename, string $sourcePath, ?string $targetPath = null): Response
    {
        return $this->post("/backups/{$username}/restore-path/" . urlencode($filename), [
            'source_path' => $sourcePath,
            'target_path' => $targetPath,
        ]);
    }

    // ── fail2ban ──────────────────────────────────────────────────────────────

    public function fail2banStatus(): Response
    {
        return $this->get('/fail2ban/status');
    }

    public function fail2banUnban(string $jail, string $ip): Response
    {
        return $this->post('/fail2ban/unban', ['jail' => $jail, 'ip' => $ip]);
    }

    // ── Firewall (UFW) ────────────────────────────────────────────────────────

    public function firewallRules(): Response
    {
        return $this->get('/firewall/rules');
    }

    public function firewallAddRule(string $type, string $port, string $proto = '', string $from = ''): Response
    {
        return $this->post('/firewall/rules', [
            'type'  => $type,
            'port'  => $port,
            'proto' => $proto,
            'from'  => $from,
        ]);
    }

    public function firewallBlockIp(string $ip): Response
    {
        return $this->post('/firewall/rules', [
            'type' => 'deny',
            'port' => '',
            'proto' => '',
            'from' => $ip,
        ]);
    }

    public function firewallDeleteRule(int $number): Response
    {
        return $this->delete("/firewall/rules/{$number}");
    }

    // ── OS updates ────────────────────────────────────────────────────────────

    public function updatesAvailable(): Response
    {
        return $this->get('/system/updates');
    }

    public function updatesApply(): Response
    {
        return $this->post('/system/updates');
    }

    // ── Custom SSL cert ───────────────────────────────────────────────────────

    public function sslStore(string $domain, string $certPem, string $keyPem): Response
    {
        return $this->post("/ssl/store/{$domain}", [
            'cert_pem' => $certPem,
            'key_pem'  => $keyPem,
        ]);
    }

    // ── Agent upgrade ─────────────────────────────────────────────────────────

    public function upgradeAgent(string $version, string $downloadUrl): Response
    {
        return $this->post('/agent/upgrade', [
            'version'      => $version,
            'download_url' => $downloadUrl,
        ]);
    }

    // ── Autoresponders ────────────────────────────────────────────────────────

    public function autoresponderSet(string $email, string $subject, string $body, bool $active): Response
    {
        return $this->post('/mail/autoresponder', compact('email', 'subject', 'body', 'active'));
    }

    public function autoresponderDelete(string $email): Response
    {
        return $this->delete("/mail/autoresponder/{$email}");
    }

    public function mailboxSieveSet(string $email, ?string $script): Response
    {
        if ($script === null || trim($script) === '') {
            return $this->delete("/mail/mailbox-rules/{$email}");
        }

        return $this->post('/mail/mailbox-rules', [
            'email' => $email,
            'script' => $script,
        ]);
    }

    // ── SSH Keys ──────────────────────────────────────────────────────────────

    public function sshKeyList(string $username): Response
    {
        return $this->get("/accounts/{$username}/ssh-keys");
    }

    public function sshKeyAdd(string $username, string $name, string $publicKey): Response
    {
        return $this->post("/accounts/{$username}/ssh-keys", ['name' => $name, 'public_key' => $publicKey]);
    }

    public function sshKeyDelete(string $username, string $fingerprint): Response
    {
        return $this->delete("/accounts/{$username}/ssh-keys/{$fingerprint}");
    }

    // ── Rspamd ────────────────────────────────────────────────────────────────

    public function rspamdStats(): Response
    {
        return $this->get('/mail/rspamd/stats');
    }

    public function mailDeliveryLog(string $query, string $service = 'postfix', int $lines = 100): Response
    {
        return $this->get('/mail/delivery?query=' . urlencode($query) . '&service=' . urlencode($service) . '&lines=' . $lines);
    }

    // ── Database Grants ───────────────────────────────────────────────────────

    public function databaseGrant(string $dbName, string $dbUser, string $password, string $host = 'localhost', string $engine = 'mysql'): Response
    {
        return $this->post('/databases/grant', ['db_name' => $dbName, 'db_user' => $dbUser, 'password' => $password, 'host' => $host, 'engine' => $engine]);
    }

    public function databaseRevoke(string $dbName, string $dbUser, bool $deleteUser = false, string $host = 'localhost', string $engine = 'mysql'): Response
    {
        return $this->delete_with_body('/databases/grant', ['db_name' => $dbName, 'db_user' => $dbUser, 'delete_user' => $deleteUser, 'host' => $host, 'engine' => $engine]);
    }

    // ── App Installer ─────────────────────────────────────────────────────────

    public function appInstall(array $params): Response
    {
        return $this->postLong('/apps/install', $params);
    }

    public function appUpdate(array $params): Response
    {
        return $this->postLong('/apps/update', $params);
    }

    public function appUninstall(array $params): Response
    {
        return $this->delete_with_body('/apps/uninstall', $params);
    }

    // ── Remote Backup Push ────────────────────────────────────────────────────

    public function backupPush(string $username, string $filename, array $destination): Response
    {
        return $this->post("/backups/{$username}/push", array_merge(['filename' => $filename], $destination));
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

    /** Long-timeout POST for operations that can take minutes (app installs, updates). */
    public function postLong(string $path, array $body = []): Response
    {
        return $this->request('POST', $path, $body, timeout: 600);
    }

    private function request(string $method, string $path, array $body = [], int $timeout = 30): Response
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
            ->timeout($timeout);

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

    private function requestRaw(string $method, string $path, string $body, string $contentType, int $timeout = 30): Response
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $this->node->hmac_secret);

        $http = Http::withHeaders([
            'X-Strata-Signature' => $signature,
            'X-Strata-Timestamp' => $timestamp,
            'Accept'             => 'application/json',
        ])->timeout($timeout);

        return match ($method) {
            'POST' => $http->withBody($body, $contentType)->post($this->node->apiUrl($path)),
            default => throw new \InvalidArgumentException("Unsupported raw method: {$method}"),
        };
    }
}
