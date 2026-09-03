<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectMarketingVariantRequest;
use App\Models\Project;
use App\Models\ProjectMarketingVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProjectMarketingVariantController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project list')->only(['index']);
        $this->middleware('can:project edit')->only(['store', 'update', 'destroy']);
    }

    public function index(Project $project): JsonResponse
    {
        $variants = $project->marketingVariants()
            ->with('references')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $variants]);
    }

    public function store(ProjectMarketingVariantRequest $request, Project $project): JsonResponse
    {
        $variant = DB::transaction(function () use ($request, $project) {
            $variant = $project->marketingVariants()->create([
                'nombre' => $request->input('nombre'),
                'orden'  => $project->marketingVariants()->max('orden') + 1,
            ]);

            $this->syncReferences($variant, $request->input('referencias', []));

            return $variant;
        });

        return response()->json([
            'success' => true,
            'data'    => $variant->load('references'),
            'message' => 'Variante creada',
        ], 201);
    }

    public function update(ProjectMarketingVariantRequest $request, Project $project, ProjectMarketingVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);

        DB::transaction(function () use ($request, $variant) {
            $variant->update(['nombre' => $request->input('nombre')]);
            $this->syncReferences($variant, $request->input('referencias', []));
        });

        return response()->json([
            'success' => true,
            'data'    => $variant->load('references'),
            'message' => 'Variante actualizada',
        ]);
    }

    public function destroy(Project $project, ProjectMarketingVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);

        DB::transaction(function () use ($variant) {
            $variant->references()->delete();
            $variant->delete();
        });

        return response()->json(['success' => true, 'message' => 'Variante eliminada']);
    }

    /**
     * Las referencias se reemplazan enteras: el formulario siempre manda la
     * lista completa, así que conservar las viejas dejaría filas fantasma.
     */
    private function syncReferences(ProjectMarketingVariant $variant, array $referencias): void
    {
        $variant->references()->delete();

        foreach (array_values($referencias) as $orden => $referencia) {
            $variant->references()->create([
                'referencia'     => $referencia['referencia'] ?? null,
                'codigo'         => $referencia['codigo'] ?? null,
                'aplicacion'     => $referencia['aplicacion'] ?? null,
                'dosis'          => $referencia['dosis'] ?? null,
                'color_etiqueta' => $referencia['color_etiqueta'] ?? null,
                'claims'         => $referencia['claims'] ?? null,
                'orden'          => $orden,
            ]);
        }
    }
}
