<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMarketingVariantReference;
use App\Models\ProjectStatusHistory;
use Illuminate\Support\Collection;

/**
 * Potencial a la vista: proyectos de una ejecutiva con las referencias que
 * Desarrollo creó, su precio y el potencial anual (USD y Kg). Los ajustes manuales
 * escriben sobre el dato real (el mismo que ve el detalle del proyecto) y
 * quedan en el historial del proyecto.
 */
class ProjectPotentialService
{
    /** Nombres de ejecutiva presentes en proyectos (incluye importados sin usuario). */
    public function ejecutivas(): Collection
    {
        return Project::query()
            ->whereNotNull('ejecutivo')
            ->where('ejecutivo', '!=', '')
            ->distinct()
            ->orderBy('ejecutivo')
            ->pluck('ejecutivo');
    }

    public function projectsFor(string $ejecutivo, ?string $estadoExterno = null): Collection
    {
        return Project::query()
            ->where('ejecutivo', $ejecutivo)
            ->when($estadoExterno, fn ($q) => $q->where('estado_externo', $estadoExterno))
            ->with([
                'client:id,client_name',
                'prospect:id,nombre',
                'marketingVariants' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
                'marketingVariants.references' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Project $p) => [
                'id'                 => $p->id,
                'nombre'             => $p->nombre,
                'tipo'               => $p->tipo,
                'origen'             => $this->origen($p),
                'cliente'            => $p->client?->client_name ?? $p->prospect?->nombre ?? $p->nombre_prospecto,
                'es_prospecto'       => $p->client_id === null,
                'estado_externo'     => $p->estado_externo,
                'estado_interno'     => $p->estado_interno,
                'fecha_creacion'     => $p->fecha_creacion?->format('Y-m-d'),
                'potencial_anual_usd' => $p->potencial_anual_usd !== null ? (float) $p->potencial_anual_usd : null,
                'potencial_anual_kg' => $p->potencial_anual_kg !== null ? (float) $p->potencial_anual_kg : null,
                'referencias'        => $p->marketingVariants->flatMap(
                    fn ($v) => $v->references->map(fn (ProjectMarketingVariantReference $r) => [
                        'id'         => $r->id,
                        'variante'   => $v->nombre,
                        'referencia' => $r->referencia,
                        'codigo'     => $r->codigo,
                        'precio'     => $r->precio !== null ? (float) $r->precio : null,
                    ])
                )->values(),
            ]);
    }

    /** Mismo criterio que el formulario de creación: homologación manda sobre proactivo. */
    private function origen(Project $project): string
    {
        return match (true) {
            (bool) $project->homologacion => 'homologacion',
            (bool) $project->proactivo    => 'proactivo',
            default                       => 'reactivo',
        };
    }

    /** @param array<string, float|null> $valores potencial_anual_usd y/o potencial_anual_kg */
    public function updatePotential(Project $project, array $valores, string $executive): Project
    {
        $etiquetas = [
            'potencial_anual_usd' => 'Potencial anual (USD)',
            'potencial_anual_kg'  => 'Potencial anual (Kg)',
        ];

        $antes = $project->only(array_keys($valores));
        $project->update($valores);

        foreach ($valores as $campo => $nuevo) {
            if ($this->changed($antes[$campo], $nuevo)) {
                $this->log($project->id, "{$etiquetas[$campo]}: {$this->fmt($antes[$campo])} → {$this->fmt($nuevo)}", $executive);
            }
        }

        return $project;
    }

    public function updateReferencePrice(ProjectMarketingVariantReference $reference, ?float $precio, string $executive): ProjectMarketingVariantReference
    {
        $antes = $reference->precio;
        $reference->update(['precio' => $precio]);

        if ($this->changed($antes, $precio)) {
            $nombre = $reference->referencia ?: ($reference->codigo ?: "#{$reference->id}");
            $this->log(
                $reference->variant->project_id,
                "Precio referencia {$nombre} (USD/Kg): {$this->fmt($antes)} → {$this->fmt($precio)}",
                $executive,
            );
        }

        return $reference;
    }

    private function changed($antes, ?float $despues): bool
    {
        return ($antes === null ? null : (float) $antes) !== $despues;
    }

    private function fmt($valor): string
    {
        return $valor === null ? '—' : number_format((float) $valor, 2, ',', '.');
    }

    private function log(int $projectId, string $descripcion, string $executive): void
    {
        ProjectStatusHistory::create([
            'project_id'  => $projectId,
            'tipo'        => 'campo',
            'descripcion' => $descripcion,
            'ejecutivo'   => $executive,
        ]);
    }
}
