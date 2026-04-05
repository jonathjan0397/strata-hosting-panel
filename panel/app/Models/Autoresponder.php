<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Autoresponder extends Model
{
    protected $fillable = ['email_account_id', 'subject', 'body', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }
}
