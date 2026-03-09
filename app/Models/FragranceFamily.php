<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FragranceFamily extends Model
{
    use HasFactory;

    protected $table = 'fragrance_families';

    protected $fillable = [
        'nombre',
        'familia_olfativa',
        'nucleo',
        'genero',
        'casa_id',
    ];

    public function casa(): BelongsTo
    {
        return $this->belongsTo(FragranceHouse::class, 'casa_id');
    }

    public function fineFragrances(): HasMany
    {
        return $this->hasMany(FineFragrance::class, 'family_id');
    }
}
