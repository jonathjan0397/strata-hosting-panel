<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class HostingDatabase extends Model
{
    protected $table = 'hosting_databases';

    protected $fillable = [
        'account_id', 'node_id', 'engine', 'db_name', 'db_user', 'password', 'note', 'migration_reset_required',
    ];

    protected $casts = [
        'migration_reset_required' => 'boolean',
    ];

    protected $hidden = ['password'];

    public function getPasswordPlainAttribute(): ?string
    {
        return $this->password ? Crypt::decryptString($this->password) : null;
    }

    public function setPasswordAttribute(?string $value): void
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
