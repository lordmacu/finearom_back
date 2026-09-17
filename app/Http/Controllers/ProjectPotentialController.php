<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectPotential\ProjectPotentialIndexRequest;
use App\Http\Requests\ProjectPotential\ProjectPotentialUpdateRequest;
use App\Models\Project;
use App\Services\ProjectPotentialService;
use Illuminate\Http\JsonResponse;

class ProjectPotentialController extends Controller
{
    public function __construct(
        private readonly ProjectPotentialService $service
    ) {
        $this->middleware('can:project potential list')->only(['index', 'ejecutivas']);
        $this->middleware('can:project potential edit')->only(['updateProject']);
    }

    public function ejecutivas(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->ejecutivas()]);
    }

    public function index(ProjectPotentialIndexRequest $request): JsonResponse
    {
        $projects = $this->service->projectsFor($request->validated('ejecutivo'), $request->validated('estado_externo'));

        return response()->json([
            'success' => true,
            'data'    => $projects,
            'meta'    => [
                'total_proyectos'    => $projects->count(),
                'total_referencias'  => $projects->sum(fn ($p) => $p['referencias']->count()),
                'total_potencial_usd' => round((float) $projects->sum('potencial_anual_usd'), 2),
                'total_potencial_kg' => round((float) $projects->sum('potencial_anual_kg'), 2),
            ],
        ]);
    }

    public function updateProject(ProjectPotentialUpdateRequest $request, Project $project): JsonResponse
    {
        $valores = array_map(fn ($v) => $v === null ? null : (float) $v, $request->validated());
        $project = $this->service->updatePotential($project, $valores, auth()->user()->name);

        $decimal = fn ($v) => $v !== null ? (float) $v : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                  => $project->id,
                'potencial_anual_usd' => $decimal($project->potencial_anual_usd),
                'potencial_anual_kg'  => $decimal($project->potencial_anual_kg),
            ],
            'message' => 'Potencial actualizado',
        ]);
    }
}
