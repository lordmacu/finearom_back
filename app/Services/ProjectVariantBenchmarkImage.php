<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imagen del benchmark de una variante de Desarrollo (disco local, privado).
 */
class ProjectVariantBenchmarkImage
{
    /**
     * Deja en $data la ruta de la imagen: nueva (reemplaza y borra la anterior),
     * null si se pidió quitarla, o sin tocar si no vino nada.
     */
    public function apply(array $data, Project $project, ?ProjectVariant $variant, ?UploadedFile $imagen, bool $quitar): array
    {
        unset($data['benchmark_imagen'], $data['remove_benchmark_imagen']);

        if ($imagen) {
            $this->delete($variant);
            $data['benchmark_imagen'] = Storage::disk('local')->putFileAs(
                "variant-benchmark/{$project->id}",
                $imagen,
                Str::uuid() . '.' . $imagen->getClientOriginalExtension(),
            );
        } elseif ($quitar) {
            $this->delete($variant);
            $data['benchmark_imagen'] = null;
        }

        return $data;
    }

    public function delete(?ProjectVariant $variant): void
    {
        if ($variant?->benchmark_imagen) {
            Storage::disk('local')->delete($variant->benchmark_imagen);
        }
    }
}
