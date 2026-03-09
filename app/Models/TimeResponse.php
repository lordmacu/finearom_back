<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeResponse extends Model
{
    use HasFactory;

    protected $table = 'time_responses';

    protected $fillable = [
        'num_variantes_min',
        'num_variantes_max',
        'grupo',
        'valor',
    ];

    protected $casts = [
        'num_variantes_min' => 'integer',
        'num_variantes_max' => 'integer',
        'grupo' => 'integer',
        'valor' => 'integer',
    ];
}
