<?php

namespace App\Services;

use App\Models\ProjectMarketingVariant;
use App\Models\ProjectVariant;
use App\Support\HtmlText;

/**
 * Las variantes que crea Desarrollo se crean solas en Marketing, para que
 * Marketing no tenga que volver a crearlas. Solo se sincroniza el nombre:
 * claims, color y referencias siguen siendo de Marketing.
 */
class ProjectVariantMarketingSync
{
    public function created(ProjectVariant $variant): ProjectMarketingVariant
    {
        $project = $variant->project;

        return $project->marketingVariants()->create([
            'project_variant_id' => $variant->id,
            'nombre'             => $variant->nombre,
            'orden'              => $project->marketingVariants()->max('orden') + 1,
        ]);
    }

    public function updated(ProjectVariant $variant): void
    {
        if (!$variant->wasChanged('nombre')) {
            return;
        }

        ProjectMarketingVariant::where('project_variant_id', $variant->id)
            ->update(['nombre' => $variant->nombre]);
    }

    /**
     * Llamar ANTES de borrar la variante de Desarrollo. Si Marketing aún no le
     * agregó nada, se borra también; si ya tiene claims o referencias, se
     * conserva (queda sin enlace) para no perder su trabajo.
     */
    public function deleting(ProjectVariant $variant): void
    {
        $marketing = ProjectMarketingVariant::withCount('references')
            ->where('project_variant_id', $variant->id)
            ->get();

        foreach ($marketing as $mv) {
            if ($mv->references_count === 0 && HtmlText::isBlank($mv->claims)) {
                $mv->delete();
            } else {
                $mv->update(['project_variant_id' => null]);
            }
        }
    }
}
