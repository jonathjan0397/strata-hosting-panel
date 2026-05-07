<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupImport extends Model
{
    protected $fillable = [
        'account_id',
        'node_id',
        'backup_job_id',
        'imported_by',
        'source_system',
        'status',
        'original_filename',
        'stored_path',
        'converted_filename',
        'size_bytes',
        'detected_paths',
        'notes',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'detected_paths' => 'array',
        'size_bytes' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function backupJob(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = $this->size_bytes ?? 0;
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
