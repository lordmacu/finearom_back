<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fragrance extends Model
{
    use HasFactory;

    protected $table = 'fragrances';

    protected $fillable = [
        'nombre',
        'referencia',
        'codigo',
        'precio',
        'precio_usd',
        'usos',
    ];

    protected $casts = [
        'usos' => 'array',
        'precio' => 'decimal:2',
        'precio_usd' => 'decimal:2',
    ];

    public function projectRequests(): HasMany
    {
        return $this->hasMany(ProjectRequest::class, 'fragrance_id');
    }
}
