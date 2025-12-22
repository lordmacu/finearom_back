<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrmDaily extends Model
{
    protected $table = 'trm_daily';

    protected $fillable = [
        'date',
        'value',
        'source',
        'metadata',
        'is_weekend',
        'is_holiday',
    ];

    protected $casts = [
        'date' => 'date',
        'value' => 'decimal:4',
        'metadata' => 'array',
        'is_weekend' => 'boolean',
        'is_holiday' => 'boolean',
    ];
}

