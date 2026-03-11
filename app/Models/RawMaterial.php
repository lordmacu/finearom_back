<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterial extends Model
{
    use HasFactory;

    protected $table = 'raw_materials';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'unidad',
        'costo_unitario',
        'stock_disponible',
        'descripcion',
        'proveedor',
        'activo',
    ];

    protected $casts = [
        'costo_unitario'   => 'decimal:4',
        'stock_disponible' => 'decimal:4',
        'activo'           => 'boolean',
    ];

    public function priceHistory(): HasMany
    {
        return $this->hasMany(RawMaterialPriceHistory::class, 'raw_material_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(RawMaterialStockMovement::class, 'raw_material_id');
    }

    public function formulaLines(): HasMany
    {
        return $this->hasMany(ReferenceFormulaLine::class, 'raw_material_id');
    }

    /** Sub-ingredientes de este corazón (solo aplica cuando tipo=corazon) */
    public function corazonComponents(): HasMany
    {
        return $this->hasMany(CorazonFormulaLine::class, 'corazon_id');
    }

    /** Referencias a fórmulas de corazón donde esta materia prima es ingrediente */
    public function usedInCorazones(): HasMany
    {
        return $this->hasMany(CorazonFormulaLine::class, 'raw_material_id');
    }
}
