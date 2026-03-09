<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProposal extends Model
{
    use HasFactory;

    protected $table = 'project_proposals';

    protected $fillable = [
        'variant_id',
        'swissarom_reference_id',
        'definitiva',
        'total_propuesta',
        'total_propuesta_cop',
    ];

    protected $casts = [
        'definitiva' => 'boolean',
        'total_propuesta' => 'decimal:2',
        'total_propuesta_cop' => 'decimal:2',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProjectVariant::class, 'variant_id');
    }

    public function swissaromReference(): BelongsTo
    {
        return $this->belongsTo(SwissaromReference::class, 'swissarom_reference_id');
    }
}
