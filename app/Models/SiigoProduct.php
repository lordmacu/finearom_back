<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiigoProduct extends Model
{
    protected $table = 'siigo_products';

    protected $fillable = [
        'codigo',
        'nombre',
        'nombre_corto',
        'precio',
        'unidad_medida',
        'grupo',
        'referencia',
        'empresa',
        'siigo_hash',
    ];
}
