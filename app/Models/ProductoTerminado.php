<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoTerminado extends Model
{
    protected $table = 'productos_terminados';

    protected $fillable = [
        'consecutivo',
        'codigo',
        'nombre',
        'observaciones',
        'costo_unitario',
        'activo',
    ];

    protected $casts = [
        'costo_unitario' => 'decimal:4',
        'activo'         => 'boolean',
    ];

    public function formulaLines(): HasMany
    {
        return $this->hasMany(ProductoFormulaLine::class, 'producto_terminado_id');
    }
}
