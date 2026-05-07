<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupJob extends Model
{
    protected $fillable = [
        'account_id', 'node_id', 'filename', 'type',
        'status', 'restore_status', 'size_bytes', 'error', 'restore_error', 'last_restored_at', 'trigger',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'last_restored_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = $this->size_bytes ?? 0;
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1)       . ' KB';
        return $bytes . ' B';
    }
}
