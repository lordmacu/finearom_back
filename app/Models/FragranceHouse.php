<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FragranceHouse extends Model
{
    use HasFactory;

    protected $table = 'fragrance_houses';

    protected $fillable = [
        'nombre',
    ];

    public function fragranceFamilies(): HasMany
    {
        return $this->hasMany(FragranceFamily::class, 'casa_id');
    }

    public function fineFragrances(): HasMany
    {
        return $this->hasMany(FineFragrance::class, 'casa_id');
    }
}
