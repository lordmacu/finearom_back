<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FineFragrance extends Model
{
    use HasFactory;

    protected $table = 'fine_fragrances';

    protected $fillable = [
        'nombre',
        'codigo',
        'precio',
        'precio_usd',
        'casa_id',
        'family_id',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'precio_usd' => 'decimal:2',
    ];

    public function casa(): BelongsTo
    {
        return $this->belongsTo(FragranceHouse::class, 'casa_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(FragranceFamily::class, 'family_id');
    }

    public function projectFragrances(): HasMany
    {
        return $this->hasMany(ProjectFragrance::class, 'fine_fragrance_id');
    }
}
