<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinearomReference extends Model
{
    use HasFactory;

    protected $table = 'finearom_references';

    protected $fillable = [
        'codigo',
        'nombre',
        'precio',
        'dosis',
        'tipo_producto',
        'descripcion_olfativa',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'dosis'  => 'decimal:2',
    ];

    public function projectProposals(): HasMany
    {
        return $this->hasMany(ProjectProposal::class, 'finearom_reference_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(FinearomPriceHistory::class, 'finearom_reference_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(FinearomEvaluation::class, 'finearom_reference_id');
    }
}
