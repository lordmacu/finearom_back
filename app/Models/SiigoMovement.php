<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiigoMovement extends Model
{
    protected $table = 'siigo_movements';

    protected $fillable = [
        'tipo_comprobante',
        'numero_doc',
        'fecha',
        'nit_tercero',
        'cuenta_contable',
        'descripcion',
        'valor',
        'tipo_mov',
        'siigo_hash',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
    ];
}
