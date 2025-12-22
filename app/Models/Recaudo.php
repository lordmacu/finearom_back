<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recaudo extends Model
{
    protected $table = 'recaudos';

    protected $fillable = [
        'fecha_recaudo',
        'numero_recibo',
        'fecha_vencimiento',
        'numero_factura',
        'nit',
        'cliente',
        'dias',
        'valor_cancelado',
        'observaciones',
    ];

    protected $casts = [
        'fecha_recaudo'     => 'datetime:Y-m-d H:i:s',
        'fecha_vencimiento' => 'datetime:Y-m-d H:i:s',
        'dias'              => 'integer',
        'valor_cancelado'   => 'string',
    ];
}
