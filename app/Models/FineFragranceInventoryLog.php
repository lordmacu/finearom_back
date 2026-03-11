<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FineFragranceInventoryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'fine_fragrance_id',
        'tipo',
        'cantidad_kg',
        'inventario_anterior_kg',
        'inventario_nuevo_kg',
        'notas',
        'registrado_por',
    ];

    protected $casts = [
        'cantidad_kg'            => 'float',
        'inventario_anterior_kg' => 'float',
        'inventario_nuevo_kg'    => 'float',
    ];

    public function fragrance(): BelongsTo
    {
        return $this->belongsTo(FineFragrance::class, 'fine_fragrance_id');
    }
}
