<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectAreaDeliverRequest;
use App\Models\Project;
use App\Models\ProjectAreaDeliveryLog;
use App\Services\ProjectAreaDeliveryService;
use App\Services\ProjectMailService;
use App\Support\ProjectOwnership;
use Illuminate\Http\JsonResponse;

/**
 * Entregas de las áreas con modal de notas + adjuntos (desarrollo,
 * aplicaciones, evaluaciones, marketing, regulatoria, especiales). Cada entrega (parcial,
 * final o actualización) queda en la bitácora con sus adjuntos y su correo;
 * la lógica vive en ProjectAreaDeliveryService.
 */
class ProjectAreaDeliveryController extends Controller
{
    public function __construct(
        private readonly ProjectAreaDeliveryService $deliveryService,
    ) {
        $this->middleware('can:project list')->only(['showDesarrollo', 'showAplicaciones', 'showEvaluaciones', 'showMarketing', 'showRegulatoria', 'showEspeciales']);
        $this->middleware('can:project deliver')->only(['deliverDesarrollo', 'deliverAplicaciones', 'deliverEvaluaciones', 'deliverMarketing', 'deliverRegulatoria', 'deliverEspeciales']);
    }

    public function showDesarrollo(Project $project): JsonResponse
    {
        return $this->showArea($project, 'desarrollo');
    }

    public function showAplicaciones(Project $project): JsonResponse
    {
        return $this->showArea($project, 'aplicaciones');
    }

    public function showEvaluaciones(Project $project): JsonResponse
    {
        return $this->showArea($project, 'evaluaciones');
    }

    public function showMarketing(Project $project): JsonResponse
    {
        return $this->showArea($project, 'marketing');
    }

    public function showRegulatoria(Project $project): JsonResponse
    {
        return $this->showArea($project, 'regulatoria');
    }

    public function showEspeciales(Project $project): JsonResponse
    {
        return $this->showArea($project, 'especiales');
    }

    public function deliverDesarrollo(ProjectAreaDeliverRequest $request, Project $project): JsonResponse
    {
        // Un ingeniero (rol Desarrollo, no admin) solo entrega proyectos asignados a él
        if (!ProjectOwnership::canDeliverDevelopment($request->user(), $project)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo puedes entregar el área de desarrollo en proyectos asignados a ti.',
            ], 403);
        }

        return $this->deliverArea($request, $project, 'desarrollo');
    }

    public function deliverAplicaciones(ProjectAreaDeliverRequest $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'aplicaciones');
    }

    public function deliverEvaluaciones(ProjectAreaDeliverRequest $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'evaluaciones');
    }

    public function deliverMarketing(ProjectAreaDeliverRequest $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'marketing');
    }

    public function deliverRegulatoria(ProjectAreaDeliverRequest $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'regulatoria');
    }

    public function deliverEspeciales(ProjectAreaDeliverRequest $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'especiales');
    }

    /** Datos del modal / vista: últimas notas, adjuntos y bitácora de entregas. */
    private function showArea(Project $project, string $area): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->deliveryService->show($project, $area),
        ]);
    }

    private function deliverArea(ProjectAreaDeliverRequest $request, Project $project, string $area): JsonResponse
    {
        if (!$project->email_thread_message_id) {
            return response()->json(['success' => false, 'message' => ProjectMailService::SIN_HILO_ENTREGA], 422);
        }

        $log = $this->deliveryService->deliver(
            $project,
            $area,
            $request->esParcial(),
            $request->input('notas'),
            $request->file('adjuntos', []),
            auth()->user(),
        );

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'El tamaño total de los adjuntos supera 25 MB. Reduce archivos para que el correo pueda salir.',
            ], 422);
        }

        $label = $this->deliveryService->label($area);

        return response()->json([
            'success' => true,
            'data'    => ['project' => $project->fresh()] + $this->deliveryService->show($project->fresh(), $area),
            'message' => match ($log->tipo) {
                ProjectAreaDeliveryLog::PARCIAL       => "Entrega parcial de {$label} registrada",
                ProjectAreaDeliveryLog::ACTUALIZACION => "Actualización de {$label} enviada",
                default                               => "{$label}: entrega final registrada",
            },
        ]);
    }
}
