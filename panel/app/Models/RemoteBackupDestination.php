<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class RemoteBackupDestination extends Model
{
    protected $fillable = ['name', 'type', 'config', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function getConfigAttribute($value): array
    {
        try {
            return json_decode(Crypt::decryptString($value), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setConfigAttribute(array $value): void
    {
        $this->attributes['config'] = Crypt::encryptString(json_encode($value));
    }
}
