<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesHistory extends Model
{
    protected $table = 'sales_history';

    protected $fillable = [
        'nit',
        'cliente',
        'ejecutivo',
        'cliente_tipo',
        'categoria',
        'codigo',
        'referencia',
        'año',
        'mes',
        'venta',
        'cantidad',
        'newwin',
        'estado',
    ];

    protected $casts = [
        'newwin'   => 'boolean',
        'venta'    => 'integer',
        'cantidad' => 'integer',
    ];
}
