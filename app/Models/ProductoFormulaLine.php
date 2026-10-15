<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de la fórmula de un producto terminado. El ingrediente es una materia prima o un
 * corazón (raw_material_id) o bien otro producto terminado (ingrediente_producto_id).
 */
class ProductoFormulaLine extends Model
{
    protected $table = 'producto_formula_lines';

    protected $fillable = [
        'producto_terminado_id',
        'raw_material_id',
        'ingrediente_producto_id',
        'porcentaje',
        'notas',
    ];

    protected $casts = [
        'porcentaje' => 'decimal:4',
    ];

    public function productoTerminado(): BelongsTo
    {
        return $this->belongsTo(ProductoTerminado::class, 'producto_terminado_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }

    public function ingredienteProducto(): BelongsTo
    {
        return $this->belongsTo(ProductoTerminado::class, 'ingrediente_producto_id');
    }
}
