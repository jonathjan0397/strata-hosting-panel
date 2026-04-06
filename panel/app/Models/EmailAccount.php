<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'domain_id', 'account_id', 'node_id',
        'email', 'local_part', 'quota_mb', 'used_mb', 'active', 'spam_action',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function autoresponder(): HasOne
    {
        return $this->hasOne(Autoresponder::class);
    }

    public function filters(): HasMany
    {
        return $this->hasMany(EmailFilter::class)->orderBy('sort_order')->orderBy('id');
    }
}
