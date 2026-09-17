<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectPotential\ProjectPotentialIndexRequest;
use App\Http\Requests\ProjectPotential\ProjectPotentialSelectionsRequest;
use App\Models\Project;
use App\Services\ProjectPotentialService;
use Illuminate\Http\JsonResponse;

class ProjectPotentialController extends Controller
{
    public function __construct(
        private readonly ProjectPotentialService $service
    ) {
        $this->middleware('can:project potential list')->only(['index', 'ejecutivas', 'show']);
        $this->middleware('can:project potential edit')->only(['updateSelections']);
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
                'total_proyectos'     => $projects->count(),
                'total_referencias'   => $projects->sum(fn ($p) => $p['referencias']->count()),
                'total_seleccionadas' => $projects->sum(fn ($p) => $p['referencias']->where('seleccionada', true)->count()),
                'total_potencial_usd' => round((float) $projects->sum('potencial_anual_usd'), 2),
                'total_potencial_kg'  => round((float) $projects->sum('potencial_anual_kg'), 2),
            ],
        ]);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->detail($project)]);
    }

    public function updateSelections(ProjectPotentialSelectionsRequest $request, Project $project): JsonResponse
    {
        $data = $this->service->saveSelections($project, $request->validated('selecciones'), auth()->user()->name);

        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => 'Referencias seleccionadas guardadas',
        ]);
    }
}
