<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorazonFormulaLine extends Model
{
    protected $table = 'corazon_formula_lines';

    protected $fillable = [
        'corazon_id',
        'raw_material_id',
        'porcentaje',
        'notas',
    ];

    protected $casts = [
        'porcentaje' => 'decimal:4',
    ];

    public function corazon(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'corazon_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }
}
