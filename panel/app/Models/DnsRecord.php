<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    protected $fillable = [
        'dns_zone_id', 'name', 'type', 'ttl', 'value', 'priority', 'managed',
    ];

    protected $casts = [
        'managed'  => 'boolean',
        'ttl'      => 'integer',
        'priority' => 'integer',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }
}
