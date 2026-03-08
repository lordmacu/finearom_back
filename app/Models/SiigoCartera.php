<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiigoCartera extends Model
{
    protected $table = 'siigo_cartera';

    protected $fillable = [
        'tipo_registro',
        'nit_tercero',
        'cuenta_contable',
        'fecha',
        'descripcion',
        'tipo_mov',
        'siigo_hash',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(SiigoClient::class, 'nit_tercero', 'nit');
    }
}
