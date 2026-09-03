<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMarketingVariant extends Model
{
    use HasFactory;

    protected $table = 'project_marketing_variants';

    protected $fillable = [
        'project_id',
        'referencia',
        'codigo',
        'aplicacion',
        'dosis',
        'color_etiqueta',
        'claims',
    ];

    protected $casts = [
        'dosis' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}