<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Domain extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'account_id', 'node_id', 'domain', 'type', 'document_root',
        'web_server', 'php_version', 'ssl_enabled', 'ssl_wildcard', 'force_https', 'ssl_provider', 'ssl_expires_at',
        'mail_enabled', 'dkim_enabled', 'dkim_public_key', 'dkim_dns_record',
        'spf_enabled', 'spf_dns_record', 'dmarc_enabled', 'dmarc_dns_record', 'server_ip',
        'custom_directives', 'redirects', 'directory_privacy', 'hotlink_protection', 'modsecurity', 'leech_protection', 'mail_spam_action',
    ];

    protected $casts = [
        'ssl_enabled'       => 'boolean',
        'ssl_wildcard'      => 'boolean',
        'force_https'       => 'boolean',
        'dkim_enabled'      => 'boolean',
        'spf_enabled'       => 'boolean',
        'dmarc_enabled'     => 'boolean',
        'ssl_expires_at'    => 'datetime',
        'custom_directives' => 'string',
        'redirects'         => 'array',
        'directory_privacy' => 'array',
        'hotlink_protection' => 'array',
        'modsecurity' => 'array',
        'leech_protection' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function dnsZone(): HasOne
    {
        return $this->hasOne(DnsZone::class);
    }

    public function sslExpiresSoon(int $days = 14): bool
    {
        return $this->ssl_enabled
            && $this->ssl_expires_at
            && $this->ssl_expires_at->diffInDays(now()) <= $days;
    }
}
