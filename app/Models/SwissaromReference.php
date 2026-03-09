<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SwissaromReference extends Model
{
    use HasFactory;

    protected $table = 'swissarom_references';

    protected $fillable = [
        'codigo',
        'nombre',
        'precio',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
    ];

    public function projectProposals(): HasMany
    {
        return $this->hasMany(ProjectProposal::class, 'swissarom_reference_id');
    }
}
