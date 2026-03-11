<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FineFragrancePriceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'fine_fragrance_id',
        'precio_coleccion',
        'costo',
        'precio_oferta',
        'registrado_por',
        'notas',
    ];

    protected $casts = [
        'precio_coleccion' => 'float',
        'costo'            => 'float',
        'precio_oferta'    => 'float',
    ];

    public function fragrance(): BelongsTo
    {
        return $this->belongsTo(FineFragrance::class, 'fine_fragrance_id');
    }
}
