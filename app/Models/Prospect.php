<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prospect extends Model
{
    protected $table = 'prospects';

    protected $fillable = [
        'nombre',
        'nit',
        'tipo_cliente',
        'ejecutivo',
        'legacy_id',
    ];

    protected $casts = [
        'legacy_id' => 'integer',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'prospect_id');
    }

    public function scopePareto($query)
    {
        return $query->where('tipo_cliente', 'pareto');
    }

    public function scopeBalance($query)
    {
        return $query->where('tipo_cliente', 'balance');
    }
}
