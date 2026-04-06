<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    public const FEATURE_FALLBACKS = [
        'forwarders' => 'email',
        'autoresponders' => 'email',
    ];

    protected $fillable = [
        'user_id', 'node_id', 'reseller_id', 'hosting_package_id', 'username', 'plan',
        'status', 'php_version',
        'disk_limit_mb', 'bandwidth_limit_mb', 'max_domains',
        'max_subdomains', 'max_email_accounts', 'max_databases', 'max_ftp_accounts',
        'disk_used_mb', 'bandwidth_used_mb', 'suspended_at',
        'php_upload_max', 'php_post_max', 'php_memory_limit', 'php_max_exec_time',
        'backup_schedule', 'backup_time', 'backup_day',
    ];

    protected $casts = [
        'suspended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function hostingPackage(): BelongsTo
    {
        return $this->belongsTo(HostingPackage::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function enabledFeatures(): array
    {
        $features = $this->hostingPackage?->featureList?->features;

        if (! is_array($features) || $features === []) {
            return array_keys(\App\Models\FeatureList::catalog());
        }

        return array_values(array_unique($features));
    }

    public function hasFeature(string $feature): bool
    {
        $enabled = $this->enabledFeatures();

        if (in_array($feature, $enabled, true)) {
            return true;
        }

        $fallback = self::FEATURE_FALLBACKS[$feature] ?? null;

        return $fallback ? in_array($fallback, $enabled, true) : false;
    }
}
