<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMarketingVariant extends Model
{
    use HasFactory;

    protected $table = 'project_marketing_variants';

    /** Tope de referencias por variante. */
    public const MAX_REFERENCES = 3;

    protected $fillable = [
        'project_id',
        'nombre',
        'orden',
    ];

    protected $casts = ['orden' => 'integer'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(ProjectMarketingVariantReference::class, 'variant_id')->orderBy('orden');
    }
}
