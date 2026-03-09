<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeMarketing extends Model
{
    use HasFactory;

    protected $table = 'time_marketing';

    protected $fillable = [
        'grupo',
        'solicitud',
        'valor',
    ];

    protected $casts = [
        'grupo' => 'integer',
        'valor' => 'integer',
    ];
}
