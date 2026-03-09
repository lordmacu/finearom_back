<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiigoWebhookLog extends Model
{
    protected $table = 'siigo_webhook_logs';

    protected $fillable = [
        'event',
        'source_ip',
        'payload',
        'status',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
