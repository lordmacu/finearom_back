<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupClassification extends Model
{
    use HasFactory;

    protected $table = 'group_classifications';

    protected $fillable = [
        'rango_min',
        'rango_max',
        'tipo_cliente',
        'valor',
    ];

    protected $casts = [
        'rango_min' => 'decimal:2',
        'rango_max' => 'decimal:2',
        'valor' => 'integer',
    ];
}
