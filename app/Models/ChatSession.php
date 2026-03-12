<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatSession extends Model
{
    protected $fillable = [
        'user_id',
        'thread_id',
        'period_label',
        'period_start',
        'period_end',
        'messages',
    ];

    protected $casts = [
        'messages'     => 'array',
        'period_start' => 'date',
        'period_end'   => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
