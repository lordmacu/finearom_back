<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carpeta virtual (solo en base de datos) de los catálogos de Marketing:
 * envases, diseños de etiqueta y pirámides. Se anidan sin límite de niveles.
 */
class ProjectCatalogFolder extends Model
{
    protected $table = 'project_catalog_folders';

    protected $fillable = ['tipo', 'parent_id', 'name'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProjectCatalogItem::class, 'folder_id');
    }

    public function scopeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    /** Carpetas desde la raíz hasta esta (incluida), para las migas de pan. */
    public function ruta(): array
    {
        $ruta = [];
        $actual = $this;
        while ($actual) {
            array_unshift($ruta, ['id' => $actual->id, 'name' => $actual->name]);
            $actual = $actual->parent_id ? self::find($actual->parent_id) : null;
        }

        return $ruta;
    }

    /** ¿Es $otraId esta carpeta o una descendiente? (para impedir ciclos al mover). */
    public function incluye(int $otraId): bool
    {
        $id = $otraId;
        while ($id) {
            if ($id === $this->id) {
                return true;
            }
            $id = (int) self::whereKey($id)->value('parent_id');
        }

        return false;
    }

    /** Ids de esta carpeta y de todas sus descendientes. */
    public function idsConDescendientes(): array
    {
        $ids = [$this->id];
        $pendientes = [$this->id];
        while ($pendientes) {
            $hijos = self::whereIn('parent_id', $pendientes)->pluck('id')->all();
            $ids = array_merge($ids, $hijos);
            $pendientes = $hijos;
        }

        return $ids;
    }
}
