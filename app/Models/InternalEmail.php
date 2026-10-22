<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalEmail extends Model
{
    /** Áreas internas de las campañas: clave => etiqueta (en este orden se muestran). */
    public const AREAS = [
        'operaciones' => 'Operaciones',
        'general' => 'General',
        'comercial' => 'Comercial',
        'desarrollo' => 'Desarrollo',
    ];

    protected $fillable = ['area', 'email'];
}
