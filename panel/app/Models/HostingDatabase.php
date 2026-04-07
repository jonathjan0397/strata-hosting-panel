<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostingDatabase extends Model
{
    protected $table = 'hosting_databases';

    protected $fillable = [
        'account_id', 'node_id', 'engine', 'db_name', 'db_user', 'note',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
