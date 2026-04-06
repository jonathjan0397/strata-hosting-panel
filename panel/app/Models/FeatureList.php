<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FeatureList extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    public static function catalog(): array
    {
        return [
            'domains' => 'Domains',
            'dns' => 'DNS Zone Editor',
            'email' => 'Email Accounts',
            'forwarders' => 'Forwarders',
            'autoresponders' => 'Autoresponders',
            'databases' => 'Databases',
            'ftp' => 'FTP Accounts',
            'file_manager' => 'File Manager',
            'git' => 'Git Version Control',
            'backups' => 'Backups',
            'metrics' => 'Metrics and Logs',
            'ssh_keys' => 'SSH Keys',
            'php' => 'PHP Settings',
            'deliverability' => 'Deliverability',
            'app_installer' => 'App Installer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (FeatureList $featureList) {
            if (! $featureList->slug) {
                $featureList->slug = Str::slug($featureList->name);
            }
            $featureList->features = array_values(array_unique(array_filter((array) $featureList->features)));
        });
    }

    public function packages(): HasMany
    {
        return $this->hasMany(HostingPackage::class);
    }
}
