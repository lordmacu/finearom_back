<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceFormulaLine extends Model
{
    use HasFactory;

    protected $table = 'reference_formula_lines';

    protected $fillable = [
        'finearom_reference_id',
        'raw_material_id',
        'porcentaje',
        'notas',
    ];

    protected $casts = [
        'porcentaje' => 'decimal:4',
    ];

    public function finearomReference(): BelongsTo
    {
        return $this->belongsTo(FinearomReference::class, 'finearom_reference_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id')
                    ->select(['id', 'nombre', 'unidad', 'costo_unitario', 'stock_disponible', 'activo']);
    }
}
