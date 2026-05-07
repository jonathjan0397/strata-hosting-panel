<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class HostingPackage extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'feature_list_id',
        'php_version',
        'disk_limit_mb',
        'bandwidth_limit_mb',
        'max_domains',
        'max_subdomains',
        'max_email_accounts',
        'max_databases',
        'max_ftp_accounts',
        'available_to_resellers',
        'is_active',
    ];

    protected $casts = [
        'available_to_resellers' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (HostingPackage $package) {
            if (! $package->slug) {
                $package->slug = Str::slug($package->name);
            }
        });
    }

    public function featureList(): BelongsTo
    {
        return $this->belongsTo(FeatureList::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function accountAttributes(): array
    {
        return [
            'hosting_package_id' => $this->id,
            'plan' => $this->slug,
            'php_version' => $this->php_version,
            'disk_limit_mb' => $this->disk_limit_mb,
            'bandwidth_limit_mb' => $this->bandwidth_limit_mb,
            'max_domains' => $this->max_domains,
            'max_subdomains' => $this->max_subdomains,
            'max_email_accounts' => $this->max_email_accounts,
            'max_databases' => $this->max_databases,
            'max_ftp_accounts' => $this->max_ftp_accounts,
        ];
    }
}
