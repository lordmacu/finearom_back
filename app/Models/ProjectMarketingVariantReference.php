<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMarketingVariantReference extends Model
{
    use HasFactory;

    protected $table = 'project_marketing_variant_references';

    protected $fillable = [
        'variant_id',
        'referencia',
        'codigo',
        'aplicacion',
        'dosis',
        'color_etiqueta',
        'claims',
        'orden',
    ];

    protected $casts = [
        'dosis' => 'decimal:2',
        'orden' => 'integer',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProjectMarketingVariant::class, 'variant_id');
    }
}
