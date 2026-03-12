<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FineFragrance extends Model
{
    use HasFactory;

    protected $fillable = [
        'fine_fragrance_house_id',
        'contratipo',
        'inspiracion',
        'ano_lanzamiento',
        'ano_desarrollo',
        'genero',
        'familia_olfativa',
        'nombre',
        'tipo',
        'salida',
        'corazon',
        'fondo',
        'precio_coleccion',
        'costo',
        'inventario_kg',
        'precio_oferta',
        'estado',
        'foto_url',
        'observaciones',
        'activo',
    ];

    protected $casts = [
        'activo'           => 'boolean',
        'estado'           => 'string',
        'genero'           => 'string',
        'tipo'             => 'string',
        'inventario_kg'    => 'float',
        'precio_coleccion' => 'float',
        'costo'            => 'float',
        'precio_oferta'    => 'float',
    ];

    public function house(): BelongsTo
    {
        return $this->belongsTo(FineFragranceHouse::class, 'fine_fragrance_house_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(FineFragrancePriceHistory::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(FineFragranceInventoryLog::class);
    }
}
