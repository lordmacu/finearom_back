<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMarketingVariantReference;
use App\Models\ProjectPotentialReference;
use App\Models\ProjectStatusHistory;
use App\Support\PotentialDispatchPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Potencial a la vista: proyectos de una ejecutiva con las referencias que
 * Desarrollo creó y su precio. La ejecutiva marca cuáles seleccionó el cliente
 * y llena el potencial de cada una. El potencial anual del proyecto (USD y Kg)
 * es el que se escribe a mano (creación, detalle o este módulo) y nunca se
 * recalcula; la suma de las referencias seleccionadas se informa aparte. Cada seleccionada trae su plan de
 * despachos mes a mes para cada año pedido (PotentialDispatchPlan).
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

    /** Proyectos con todo lo que muestra el módulo; sin ejecutiva trae los de todas. */
    public function query(?string $ejecutivo = null, ?string $estadoExterno = null): Builder
    {
        return Project::query()
            ->when($ejecutivo, fn ($q) => $q->where('ejecutivo', $ejecutivo))
            ->when($estadoExterno, fn ($q) => $q->where('estado_externo', $estadoExterno))
            ->with($this->relaciones());
    }

    /** @param int[] $anios */
    public function projectsFor(string $ejecutivo, ?string $estadoExterno, array $anios): Collection
    {
        return $this->query($ejecutivo, $estadoExterno)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Project $p) => $this->resumen($p) + [
                'referencias' => $this->referencias($p, $anios)->map(fn ($r) => [
                    'id'           => $r['id'],
                    'variante'     => $r['variante'],
                    'referencia'   => $r['referencia'],
                    'codigo'       => $r['codigo'],
                    'precio'       => $r['precio'],
                    'seleccionada' => $r['seleccionada'],
                    'planes'       => $r['planes'],
                ])->values(),
            ]);
    }

    /** Detalle para la ventana: el proyecto y todas sus referencias con el potencial de las seleccionadas. */
    /** @param int[] $anios */
    public function detail(Project $project, array $anios): array
    {
        $project->load($this->relaciones());

        return $this->resumen($project) + [
            'anios'       => $anios,
            'referencias' => $this->referencias($project, $anios)->values(),
        ];
    }

    /**
     * Sync completo de las referencias seleccionadas: las que no vienen dejan
     * de estar seleccionadas (se borran sus datos).
     *
     * @param array<int, array<string, mixed>> $selecciones
     */
    public function saveSelections(Project $project, array $selecciones, string $executive, array $anios): array
    {
        $refsDelProyecto = $project->marketingVariants()
            ->with('references')
            ->get()
            ->flatMap->references
            ->keyBy('id');

        $ajenas = collect($selecciones)->pluck('reference_id')->reject(fn ($id) => $refsDelProyecto->has($id));
        if ($ajenas->isNotEmpty()) {
            throw ValidationException::withMessages([
                'selecciones' => 'Hay referencias que no pertenecen a este proyecto.',
            ]);
        }

        $antes = $project->potentialReferences()->pluck('reference_id')->sort()->values()->all();

        DB::transaction(function () use ($project, $selecciones) {
            foreach ($selecciones as $seleccion) {
                ProjectPotentialReference::updateOrCreate(
                    ['reference_id' => $seleccion['reference_id']],
                    ['project_id' => $project->id] + collect($seleccion)->except('reference_id')->all(),
                );
            }

            $project->potentialReferences()
                ->whereNotIn('reference_id', collect($selecciones)->pluck('reference_id'))
                ->delete();
        });

        $despues = collect($selecciones)->pluck('reference_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($antes !== $despues) {
            $this->log($project->id, sprintf('Potencial a la vista: %d referencia(s) seleccionada(s) por el cliente', count($despues)), $executive);
        }

        return $this->detail($project->fresh(), $anios);
    }

    /**
     * Ajuste manual del potencial anual en Kg del proyecto (el mismo dato que
     * se escribe al crearlo o en su detalle). El USD lo recalcula el modelo.
     *
     * @param array<string, float|null> $valores potencial_anual_kg
     */
    public function updatePotential(Project $project, array $valores, string $executive): Project
    {
        $etiquetas = [
            'potencial_anual_kg' => 'Potencial anual (Kg)',
        ];

        $antes = $project->only(array_keys($valores));
        $project->update($valores);

        foreach ($valores as $campo => $nuevo) {
            if (($antes[$campo] === null ? null : (float) $antes[$campo]) !== $nuevo) {
                $this->log($project->id, "{$etiquetas[$campo]} (manual): {$this->fmt($antes[$campo])} → {$this->fmt($nuevo)}", $executive);
            }
        }

        return $project;
    }

    private function relaciones(): array
    {
        return [
            'client:id,client_name',
            'prospect:id,nombre',
            'productCategory:id,name',
            'marketingVariants' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
            'marketingVariants.references' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
            'marketingVariants.references.potential',
        ];
    }

    private function resumen(Project $p): array
    {
        $seleccionadas = $p->marketingVariants->flatMap->references->filter(fn ($r) => $r->potential !== null);

        return [
            'id'                  => $p->id,
            'nombre'              => $p->nombre,
            'tipo'                => $p->tipo,
            'origen'              => $this->origen($p),
            'segmento'            => $p->productCategory?->name,
            'cliente'             => $p->client?->client_name ?? $p->prospect?->nombre ?? $p->nombre_prospecto,
            'es_prospecto'        => $p->client_id === null,
            'ejecutivo'           => $p->ejecutivo,
            'estado_externo'      => $p->estado_externo,
            'estado_interno'      => $p->estado_interno,
            'fecha_creacion'      => $p->fecha_creacion?->format('Y-m-d'),
            'potencial_anual_usd' => $this->decimal($p->potencial_anual_usd),
            'potencial_anual_kg'  => $this->decimal($p->potencial_anual_kg),
            // Informativo: lo que suman las referencias seleccionadas (USD = Kg × precio)
            'suma_referencias'    => $seleccionadas->isEmpty() ? null : [
                'kg'  => round($seleccionadas->sum(fn ($r) => (float) $r->potential->kg_anio), 2),
                'usd' => round($seleccionadas->sum(fn ($r) => (float) $r->potential->kg_anio * (float) $r->precio), 2),
            ],
        ];
    }

    /** @param int[] $anios */
    private function referencias(Project $p, array $anios): Collection
    {
        return $p->marketingVariants->flatMap(
            fn ($v) => $v->references->map(function (ProjectMarketingVariantReference $r) use ($v, $anios) {
                $s      = $r->potential;
                $precio = $this->decimal($r->precio);
                $kg     = $this->decimal($s?->kg_anio);

                return [
                    'id'           => $r->id,
                    'variante'     => $v->nombre,
                    'referencia'   => $r->referencia,
                    'codigo'       => $r->codigo,
                    'precio'       => $precio,
                    'seleccionada' => $s !== null,
                    // Un plan por año pedido (null si la referencia no está seleccionada o le faltan datos)
                    'planes'       => $s ? collect($anios)
                        ->map(fn ($anio) => PotentialDispatchPlan::for($s->kg_anio, $r->precio, $s->frecuencia_compra, $s->fecha_primer_despacho, $anio))
                        ->filter()
                        ->values()
                        ->all() : [],
                    'potencial'    => $s ? [
                        'kg_anio'               => $kg,
                        'potencial_anual_usd'   => $kg !== null && $precio !== null ? round($kg * $precio, 2) : null,
                        'fecha_primer_despacho' => $s->fecha_primer_despacho?->format('Y-m-d'),
                        'venta_anio_usd'        => $this->decimal($s->venta_anio_usd),
                        'frecuencia_compra'     => $s->frecuencia_compra,
                        'seguimiento'           => $s->seguimiento,
                        'estado'                => $s->estado,
                        'probabilidad'          => $s->probabilidad,
                    ] : null,
                ];
            })
        );
    }

    /** Mismo criterio que el formulario de creación: homologación manda sobre proactivo. */
    public function origen(Project $project): string
    {
        return match (true) {
            (bool) $project->homologacion => 'homologacion',
            (bool) $project->proactivo    => 'proactivo',
            default                       => 'reactivo',
        };
    }

    private function decimal($valor): ?float
    {
        return $valor === null ? null : (float) $valor;
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
