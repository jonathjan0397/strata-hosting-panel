<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountTrafficMetric extends Model
{
    protected $fillable = [
        'account_id',
        'domain_id',
        'node_id',
        'date',
        'requests',
        'bandwidth_bytes',
        'status_2xx',
        'status_3xx',
        'status_4xx',
        'status_5xx',
    ];

    protected $casts = [
        'date' => 'date',
    ];

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
