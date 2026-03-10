<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderGoogleTaskConfig extends Model
{
    protected $fillable = ['trigger', 'user_ids'];

    protected $casts = [
        'user_ids' => 'array',
    ];
}
