<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Services\WebhookDispatcher;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_type', 'action',
        'subject_type', 'subject_id', 'payload',
        'ip_address', 'user_agent',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(
        string $action,
        ?Model $subject = null,
        array $payload = [],
        ?User $actor = null
    ): static {
        $actor ??= auth()->user();

        $log = static::create([
            'user_id'      => $actor?->id,
            'actor_type'   => $actor?->getRoleNames()->first() ?? 'system',
            'action'       => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'payload'      => $payload,
            'ip_address'   => request()->ip(),
            'user_agent'   => request()->userAgent(),
        ]);

        try {
            app(WebhookDispatcher::class)->dispatch($log);
        } catch (\Throwable) {
            // Webhook delivery must never break the panel action that produced the audit log.
        }

        return $log;
    }
}
