<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Ítem de los catálogos de Marketing (diseños de etiqueta y pirámides).
 */
class ProjectCatalogItem extends Model
{
    /** tipo => nombre del catálogo */
    public const TIPOS = [
        'etiqueta' => 'Catálogo Diseño Etiquetas',
        'piramide' => 'Catálogo Pirámides',
    ];

    protected $table = 'project_catalog_items';

    protected $fillable = [
        'tipo',
        'name',
        'category',
        'photo_path',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_catalog_item_project', 'catalog_item_id', 'project_id')
            ->withTimestamps();
    }

    public function scopeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeBuscar(Builder $query, ?string $search): Builder
    {
        return $search
            ? $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%"))
            : $query;
    }
}
