<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierWebhookLog extends Model
{
    protected $fillable = [
        'carrier', 'endpoint', 'environment', 'ip', 'tracking_number',
        'payload', 'accepted', 'rejection_reason', 'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'accepted'     => 'boolean',
        'processed_at' => 'datetime',
    ];
}
