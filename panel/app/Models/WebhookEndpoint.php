<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'active',
        'last_status',
        'last_error',
        'last_delivery_at',
    ];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'last_delivery_at' => 'datetime',
    ];

    protected $hidden = ['secret'];

    public function listensFor(string $event): bool
    {
        $events = $this->events;

        if (! is_array($events) || $events === []) {
            return true;
        }

        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
