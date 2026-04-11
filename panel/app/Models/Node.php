<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Node extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'hostname', 'ip_address', 'port',
        'node_id', 'hmac_secret', 'tls_fingerprint',
        'status', 'agent_version', 'target_agent_version', 'agent_upgrade_started_at', 'last_health', 'last_seen_at',
        'is_primary', 'hosts_dns', 'web_server', 'accelerators',
    ];

    protected $casts = [
        'last_health' => 'array',
        'last_seen_at' => 'datetime',
        'agent_upgrade_started_at' => 'datetime',
        'is_primary' => 'boolean',
        'hosts_dns' => 'boolean',
        'accelerators' => 'array',
    ];

    protected $hidden = ['hmac_secret'];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function hostsDns(): bool
    {
        return (bool) $this->hosts_dns;
    }

    public function apiUrl(string $path = ''): string
    {
        return $this->url('/v1' . $path);
    }

    public function url(string $path = ''): string
    {
        $host = $this->agentTlsHost();

        return "https://{$host}:{$this->port}{$path}";
    }

    public function agentTlsHost(): string
    {
        return $this->hostname ?: $this->ip_address;
    }

    public function agentConnectAddress(): string
    {
        return $this->ip_address ?: $this->hostname;
    }

    public function agentCaBundlePath(): string
    {
        return storage_path('app/node-certs/' . ($this->node_id ?: $this->id) . '.pem');
    }
}
