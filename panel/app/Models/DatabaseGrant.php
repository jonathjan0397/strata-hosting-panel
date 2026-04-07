<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseGrant extends Model
{
    protected $fillable = ['account_id', 'node_id', 'engine', 'db_name', 'db_user', 'host', 'password_hint'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
