<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailFilter extends Model
{
    protected $fillable = [
        'email_account_id',
        'name',
        'match_field',
        'match_operator',
        'match_value',
        'action',
        'action_value',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }
}
