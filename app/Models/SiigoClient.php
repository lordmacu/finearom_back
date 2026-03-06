<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiigoClient extends Model
{
    protected $table = 'siigo_clients';

    protected $fillable = [
        'nit',
        'nombre',
        'tipo_doc',
        'numero_doc',
        'direccion',
        'ciudad',
        'telefono',
        'email',
        'tipo_tercero',
        'siigo_codigo',
        'siigo_hash',
    ];
}
