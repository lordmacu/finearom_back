<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectPotential\ProjectPotentialExportRequest;
use App\Http\Requests\ProjectPotential\ProjectPotentialIndexRequest;
use App\Http\Requests\ProjectPotential\ProjectPotentialSelectionsRequest;
use App\Http\Requests\ProjectPotential\ProjectPotentialShowRequest;
use App\Models\Project;
use App\Services\ProjectPotentialExportService;
use App\Services\ProjectPotentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectPotentialController extends Controller
{
    public function __construct(
        private readonly ProjectPotentialService $service,
        private readonly ProjectPotentialExportService $exportService,
    ) {
        $this->middleware('can:project potential list')->only(['index', 'ejecutivas', 'show', 'export']);
        $this->middleware('can:project potential edit')->only(['updateSelections']);
    }

    public function ejecutivas(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->ejecutivas()]);
    }

    public function index(ProjectPotentialIndexRequest $request): JsonResponse
    {
        $anios    = $request->anios();
        $projects = $this->service->projectsFor($request->validated('ejecutivo'), $request->validated('estado_externo'), $anios);
        $planes   = $projects->flatMap(fn ($p) => $p['referencias']->flatMap(fn ($r) => $r['planes']));

        return response()->json([
            'success' => true,
            'data'    => $projects,
            'meta'    => [
                'total_proyectos'     => $projects->count(),
                'total_referencias'   => $projects->sum(fn ($p) => $p['referencias']->count()),
                'total_seleccionadas' => $projects->sum(fn ($p) => $p['referencias']->where('seleccionada', true)->count()),
                'total_potencial_usd' => round((float) $projects->sum('potencial_anual_usd'), 2),
                'total_potencial_kg'  => round((float) $projects->sum('potencial_anual_kg'), 2),
                'anios'               => $anios,
                // Venta estimada por año según el plan de despachos
                'por_anio'            => collect($anios)->map(fn ($anio) => [
                    'anio'      => $anio,
                    'total_kg'  => round((float) $planes->where('anio', $anio)->sum('total_kg'), 2),
                    'total_usd' => round((float) $planes->where('anio', $anio)->sum('total_usd'), 2),
                ])->values(),
            ],
        ]);
    }

    public function show(ProjectPotentialShowRequest $request, Project $project): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->detail($project, $request->anios())]);
    }

    public function updateSelections(ProjectPotentialSelectionsRequest $request, Project $project): JsonResponse
    {
        $data = $this->service->saveSelections(
            $project,
            $request->validated('selecciones'),
            auth()->user()->name,
            $request->anios(),
        );

        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => 'Referencias seleccionadas guardadas',
        ]);
    }

    public function export(ProjectPotentialExportRequest $request): StreamedResponse
    {
        $anios     = $request->anios();
        $ejecutivo = $request->validated('ejecutivo');
        $writer    = new Xlsx($this->exportService->build($ejecutivo, $request->validated('estado_externo'), $anios));

        $fileName = 'potencial_a_la_vista_' . ($ejecutivo ? Str::slug($ejecutivo, '_') . '_' : '') . implode('-', $anios) . '.xlsx';

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment;filename=\"{$fileName}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
