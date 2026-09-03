<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectMarketingVariantRequest;
use App\Models\Project;
use App\Models\ProjectMarketingVariant;
use Illuminate\Http\JsonResponse;

class ProjectMarketingVariantController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project list')->only(['index']);
        $this->middleware('can:project edit')->only(['store', 'update', 'destroy']);
    }

    public function index(Project $project): JsonResponse
    {
        $variants = $project->marketingVariants()->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data'    => $variants,
        ]);
    }

    public function store(ProjectMarketingVariantRequest $request, Project $project): JsonResponse
    {
        $variant = $project->marketingVariants()->create($request->validated());

        return response()->json([
            'success' => true,
            'data'    => $variant,
            'message' => 'Variante creada',
        ], 201);
    }

    public function update(ProjectMarketingVariantRequest $request, Project $project, ProjectMarketingVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);
        $variant->update($request->validated());

        return response()->json([
            'success' => true,
            'data'    => $variant,
            'message' => 'Variante actualizada',
        ]);
    }

    public function destroy(Project $project, ProjectMarketingVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);
        $variant->delete();

        return response()->json([
            'success' => true,
            'message' => 'Variante eliminada',
        ]);
    }
}