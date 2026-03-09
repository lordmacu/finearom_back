<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeFine extends Model
{
    use HasFactory;

    protected $table = 'time_fine';

    protected $fillable = [
        'num_fragrances_min',
        'num_fragrances_max',
        'tipo_cliente',
        'valor',
    ];

    protected $casts = [
        'num_fragrances_min' => 'integer',
        'num_fragrances_max' => 'integer',
        'valor' => 'integer',
    ];
}
