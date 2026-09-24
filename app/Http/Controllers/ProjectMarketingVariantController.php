<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectMarketingVariantRequest;
use App\Models\Project;
use App\Models\ProjectMarketingVariant;
use App\Support\MarketingVariantPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de la variante en sí: nombre, claims y color de etiqueta.
 * Pertenece a Comercial. Las referencias son de Desarrollo y viven en
 * ProjectMarketingVariantReferenceController.
 */
class ProjectMarketingVariantController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project list')->only(['index']);
        $this->middleware('can:' . MarketingVariantPermissions::COMMERCIAL)
            ->only(['store', 'update', 'destroy']);
    }

    public function index(Project $project): JsonResponse
    {
        $variants = $project->marketingVariants()
            ->with('references')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $variants,
            'meta'    => MarketingVariantPermissions::metaFor(auth()->user()),
        ]);
    }

    public function store(ProjectMarketingVariantRequest $request, Project $project): JsonResponse
    {
        $variant = $project->marketingVariants()->create(array_merge(
            $request->validated(),
            ['orden' => $project->marketingVariants()->max('orden') + 1],
        ));

        return response()->json([
            'success' => true,
            'data'    => $variant->load('references'),
            'message' => 'Variante creada',
        ], 201);
    }

    public function update(ProjectMarketingVariantRequest $request, Project $project, ProjectMarketingVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);

        $data = $request->validated();

        // El nombre de las variantes creadas desde Desarrollo solo se cambia desde Desarrollo
        if ($variant->project_variant_id && array_key_exists('nombre', $data) && $data['nombre'] !== $variant->nombre) {
            return response()->json([
                'message' => 'Esta variante se creó desde Desarrollo: su nombre solo se cambia desde allá.',
                'errors'  => ['nombre' => ['El nombre se cambia desde Desarrollo.']],
            ], 422);
        }

        $variant->update($data);

        return response()->json([
            'success' => true,
            'data'    => $variant->load('references'),
            'message' => 'Variante actualizada',
        ]);
    }

    public function destroy(Project $project, ProjectMarketingVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);

        // Las variantes creadas desde Desarrollo solo se borran desde Desarrollo
        if ($variant->project_variant_id) {
            return response()->json([
                'message' => 'Esta variante se creó desde Desarrollo: solo se puede borrar desde allá.',
            ], 422);
        }

        DB::transaction(function () use ($variant) {
            $variant->references()->delete();
            $variant->delete();
        });

        return response()->json(['success' => true, 'message' => 'Variante eliminada']);
    }
}
