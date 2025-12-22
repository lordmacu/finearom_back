<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailDispatchQueue extends Model
{
    protected $table = 'email_dispatch_queues';

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'email_sent_date' => 'datetime',
    ];
}

