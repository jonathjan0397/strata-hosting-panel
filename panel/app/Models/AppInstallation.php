<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class AppInstallation extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'node_id',
        'app_slug', 'install_path', 'install_dir',
        'db_name', 'db_user', 'db_password',
        'site_url', 'site_title', 'admin_email', 'admin_username', 'admin_password',
        'setup_url',
        'installed_version', 'latest_version',
        'update_available', 'auto_update',
        'status', 'migration_verification_required', 'error_message',
        'last_checked_at', 'last_updated_at',
    ];

    protected $casts = [
        'update_available' => 'boolean',
        'auto_update'      => 'boolean',
        'migration_verification_required' => 'boolean',
        'last_checked_at'  => 'datetime',
        'last_updated_at'  => 'datetime',
    ];

    protected $hidden = ['db_password', 'admin_password'];

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getDbPasswordPlainAttribute(): ?string
    {
        return $this->db_password ? Crypt::decryptString($this->db_password) : null;
    }

    public function setDbPasswordAttribute(?string $value): void
    {
        $this->attributes['db_password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAdminPasswordPlainAttribute(): ?string
    {
        return $this->admin_password ? Crypt::decryptString($this->admin_password) : null;
    }

    public function setAdminPasswordAttribute(?string $value): void
    {
        $this->attributes['admin_password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAppConfigAttribute(): array
    {
        return config("apps.{$this->app_slug}", []);
    }

    public function getAppNameAttribute(): string
    {
        return $this->app_config['name'] ?? ucfirst($this->app_slug);
    }

    public function isAutomated(): bool
    {
        return (bool) ($this->app_config['automated'] ?? false);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
