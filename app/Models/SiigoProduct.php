<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiigoProduct extends Model
{
    protected $table = 'siigo_products';

    protected $fillable = [
        'codigo',
        'nombre',
        'precio',
        'unidad_medida',
        'grupo',
        'siigo_hash',
    ];
}
