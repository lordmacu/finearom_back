<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeEvaluation extends Model
{
    use HasFactory;

    protected $table = 'time_evaluations';

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
