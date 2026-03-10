<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectSampleRequest;
use App\Http\Requests\Project\ProjectApplicationRequest;
use App\Http\Requests\Project\ProjectEvaluationRequest;
use App\Http\Requests\Project\ProjectMarketingRequest;
use App\Http\Requests\Project\ProjectVariantRequest;
use App\Models\Project;
use App\Models\ProjectFragrance;
use App\Models\ProjectProposal;
use App\Models\ProjectRequest as ProjectRequestModel;
use App\Models\ProjectVariant;
use App\Models\FinearomReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectDetailController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project edit');
        $this->middleware('can:project factor edit')->only(['updateFactor']);
    }

    // ─── Muestra ──────────────────────────────────────────────────────────────
    public function updateSample(ProjectSampleRequest $request, Project $project): JsonResponse
    {
        $sample = $project->sample()->updateOrCreate(
            ['project_id' => $project->id],
            $request->validated()
        );

        return response()->json(['success' => true, 'data' => $sample, 'message' => 'Muestra actualizada']);
    }

    // ─── Aplicación ───────────────────────────────────────────────────────────
    public function updateApplication(ProjectApplicationRequest $request, Project $project): JsonResponse
    {
        $application = $project->application()->updateOrCreate(
            ['project_id' => $project->id],
            $request->validated()
        );

        return response()->json(['success' => true, 'data' => $application, 'message' => 'Aplicación actualizada']);
    }

    // ─── Evaluación ───────────────────────────────────────────────────────────
    public function updateEvaluation(ProjectEvaluationRequest $request, Project $project): JsonResponse
    {
        $evaluation = $project->evaluation()->updateOrCreate(
            ['project_id' => $project->id],
            $request->validated()
        );

        return response()->json(['success' => true, 'data' => $evaluation, 'message' => 'Evaluación actualizada']);
    }

    // ─── Marketing y Calidad ──────────────────────────────────────────────────
    public function updateMarketing(ProjectMarketingRequest $request, Project $project): JsonResponse
    {
        $marketing = $project->marketingYCalidad()->updateOrCreate(
            ['project_id' => $project->id],
            $request->validated()
        );

        return response()->json(['success' => true, 'data' => $marketing, 'message' => 'Marketing actualizado']);
    }

    // ─── Observaciones departamentales ────────────────────────────────────────
    public function updateObservaciones(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'obs_lab' => 'nullable|string',
            'obs_des' => 'nullable|string',
            'obs_mer' => 'nullable|string',
            'obs_cal' => 'nullable|string',
            'obs_esp' => 'nullable|string',
            'obs_ext' => 'nullable|string',
        ]);

        $project->update($data);

        return response()->json(['success' => true, 'data' => $project->fresh(), 'message' => 'Observaciones actualizadas']);
    }

    // ─── Variantes (Desarrollo) ───────────────────────────────────────────────
    public function storeVariant(ProjectVariantRequest $request, Project $project): JsonResponse
    {
        $variant = $project->variants()->create($request->validated());

        return response()->json(['success' => true, 'data' => $variant, 'message' => 'Variante creada'], 201);
    }

    public function updateVariant(ProjectVariantRequest $request, Project $project, ProjectVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);
        $variant->update($request->validated());

        return response()->json(['success' => true, 'data' => $variant->fresh(), 'message' => 'Variante actualizada']);
    }

    public function destroyVariant(Project $project, ProjectVariant $variant): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);
        $variant->delete();

        return response()->json(['success' => true, 'message' => 'Variante eliminada']);
    }

    // ─── Propuestas (por variante) ────────────────────────────────────────────
    public function storeProposal(Request $request, Project $project, ProjectVariant $variant): JsonResponse
    {
        $data = $request->validate([
            'finearom_reference_id' => 'nullable|integer|exists:finearom_references,id',
            'total_propuesta'        => 'nullable|numeric|min:0',
            'total_propuesta_cop'    => 'nullable|numeric|min:0',
            'definitiva'             => 'nullable|boolean',
        ]);

        // Snapshot del precio actual de la referencia al crear la propuesta
        if (!empty($data['finearom_reference_id'])) {
            $ref = FinearomReference::find($data['finearom_reference_id']);
            $data['precio_snapshot'] = $ref?->precio;
        }

        $proposal = $variant->proposals()->create($data);

        return response()->json([
            'success' => true,
            'data'    => $proposal->load('finearomReference'),
            'message' => 'Propuesta creada',
        ], 201);
    }

    public function updateProposal(Request $request, Project $project, ProjectVariant $variant, ProjectProposal $proposal): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);
        abort_if($proposal->variant_id !== $variant->id, 404);
        $data = $request->validate([
            'finearom_reference_id' => 'nullable|integer|exists:finearom_references,id',
            'total_propuesta'        => 'nullable|numeric|min:0',
            'total_propuesta_cop'    => 'nullable|numeric|min:0',
            'definitiva'             => 'nullable|boolean',
        ]);

        $proposal->update($data);

        return response()->json(['success' => true, 'data' => $proposal->fresh('finearomReference'), 'message' => 'Propuesta actualizada']);
    }

    public function destroyProposal(Project $project, ProjectVariant $variant, ProjectProposal $proposal): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);
        abort_if($proposal->variant_id !== $variant->id, 404);
        $proposal->delete();

        return response()->json(['success' => true, 'message' => 'Propuesta eliminada']);
    }

    public function setDefinitiva(Project $project, ProjectVariant $variant, ProjectProposal $proposal): JsonResponse
    {
        abort_if($variant->project_id !== $project->id, 404);
        abort_if($proposal->variant_id !== $variant->id, 404);
        $variant->proposals()->update(['definitiva' => false]);
        $proposal->update(['definitiva' => true]);

        return response()->json([
            'success' => true,
            'data'    => $proposal->fresh('finearomReference'),
            'message' => 'Propuesta marcada como definitiva',
        ]);
    }

    // ─── Solicitudes de fragancia (Colección) ─────────────────────────────────
    public function storeRequest(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'fragrance_id'    => 'required|integer|exists:fragrances,id',
            'tipo'            => 'nullable|string|max:100',
            'porcentaje'      => 'nullable|numeric|min:0|max:100',
            'nombre_asociado' => 'nullable|string|max:300',
        ]);

        $projectRequest = $project->requests()->create($data);

        return response()->json([
            'success' => true,
            'data'    => $projectRequest->load('fragrance'),
            'message' => 'Solicitud creada',
        ], 201);
    }

    public function updateRequest(Request $request, Project $project, ProjectRequestModel $projectRequest): JsonResponse
    {
        $data = $request->validate([
            'fragrance_id'    => 'nullable|integer|exists:fragrances,id',
            'tipo'            => 'nullable|string|max:100',
            'porcentaje'      => 'nullable|numeric|min:0|max:100',
            'nombre_asociado' => 'nullable|string|max:300',
        ]);

        $projectRequest->update($data);

        return response()->json(['success' => true, 'data' => $projectRequest->fresh('fragrance'), 'message' => 'Solicitud actualizada']);
    }

    public function destroyRequest(Project $project, ProjectRequestModel $projectRequest): JsonResponse
    {
        $projectRequest->delete();

        return response()->json(['success' => true, 'message' => 'Solicitud eliminada']);
    }

    // ─── Fragancias finas (Fine Fragances) ────────────────────────────────────
    public function storeProjectFragrance(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'fine_fragrance_id' => 'required|integer|exists:fine_fragrances,id',
            'gramos'            => 'nullable|numeric|min:0',
        ]);

        $pf = $project->fragrances()->create($data);

        return response()->json([
            'success' => true,
            'data'    => $pf->load('fineFragrance'),
            'message' => 'Fragancia agregada',
        ], 201);
    }

    public function updateProjectFragrance(Request $request, Project $project, ProjectFragrance $projectFragrance): JsonResponse
    {
        $data = $request->validate([
            'fine_fragrance_id' => 'nullable|integer|exists:fine_fragrances,id',
            'gramos'            => 'nullable|numeric|min:0',
        ]);

        $projectFragrance->update($data);

        return response()->json(['success' => true, 'data' => $projectFragrance->fresh('fineFragrance'), 'message' => 'Fragancia actualizada']);
    }

    public function destroyProjectFragrance(Project $project, ProjectFragrance $projectFragrance): JsonResponse
    {
        $projectFragrance->delete();

        return response()->json(['success' => true, 'message' => 'Fragancia eliminada']);
    }

    public function toggleActualizado(Project $project): JsonResponse
    {
        $project->update(['actualizado' => !$project->actualizado]);

        return response()->json(['success' => true, 'data' => $project->fresh(), 'message' => 'Estado actualizado']);
    }

    // ─── Cambiar factor y recalcular propuestas ────────────────────────────────
    public function updateFactor(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['factor' => 'required|numeric|min:0.0001']);

        $factorNuevo = (float) $data['factor'];
        $trm = (float) $project->trm;

        $project->update(['factor' => $factorNuevo]);

        if ($trm > 0) {
            foreach ($project->variants()->with('proposals')->get() as $variant) {
                foreach ($variant->proposals as $proposal) {
                    if ($proposal->total_propuesta !== null) {
                        $proposal->update([
                            'total_propuesta_cop' => round((float) $proposal->total_propuesta * $trm * $factorNuevo, 0),
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $project->fresh(),
            'message' => 'Factor actualizado y propuestas recalculadas',
        ]);
    }
}
