<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'sender_email',
        'recipient_email',
        'subject',
        'content',
        'process_type',
        'metadata',
        'status',
        'sent_at',
        'opened_at',
        'ip_address',
        'user_agent',
        'open_count',
        'error_message'
    ];

    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
    ];
}
