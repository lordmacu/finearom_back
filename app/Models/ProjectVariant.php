<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectVariant extends Model
{
    use HasFactory;

    protected $table = 'project_variants';

    protected $fillable = [
        'project_id',
        'nombre',
        'categoria',
        'observaciones',
        'descripcion',
        'benchmark_reference_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(ProjectProposal::class, 'variant_id');
    }

    public function benchmarkReference(): BelongsTo
    {
        return $this->belongsTo(FinearomReference::class, 'benchmark_reference_id');
    }
}
