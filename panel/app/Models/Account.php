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
        'web_disk' => 'ftp',
    ];

    protected $fillable = [
        'user_id', 'node_id', 'reseller_id', 'hosting_package_id', 'username', 'plan',
        'status', 'provisioning_error', 'php_version',
        'disk_limit_mb', 'bandwidth_limit_mb', 'max_domains',
        'max_subdomains', 'max_email_accounts', 'max_databases', 'max_ftp_accounts',
        'disk_used_mb', 'bandwidth_used_mb', 'suspended_at',
        'php_upload_max', 'php_post_max', 'php_memory_limit', 'php_max_exec_time',
        'backup_schedule', 'backup_time', 'backup_day',
        'malware_scan_schedule', 'malware_scan_path', 'malware_scan_quarantine',
        'malware_scan_last_queued_at',
    ];

    protected $casts = [
        'suspended_at' => 'datetime',
        'malware_scan_quarantine' => 'boolean',
        'malware_scan_last_queued_at' => 'datetime',
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

    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(HostingDatabase::class);
    }

    public function ftpAccounts(): HasMany
    {
        return $this->hasMany(FtpAccount::class);
    }

    public function webDavAccounts(): HasMany
    {
        return $this->hasMany(WebDavAccount::class);
    }

    public function malwareScanJobs(): HasMany
    {
        return $this->hasMany(MalwareScanJob::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isProvisioning(): bool
    {
        return $this->status === 'provisioning';
    }

    public function hasProvisioningFailed(): bool
    {
        return $this->status === 'failed';
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
