<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectPotential\ProjectPotentialExportRequest;
use App\Http\Requests\ProjectPotential\ProjectPotentialIndexRequest;
use App\Http\Requests\ProjectPotential\ProjectPotentialSelectionsRequest;
use App\Models\Project;
use App\Services\ProjectPotentialExportService;
use App\Services\ProjectPotentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $anio     = (int) ($request->validated('anio') ?? now()->year);
        $projects = $this->service->projectsFor($request->validated('ejecutivo'), $request->validated('estado_externo'), $anio);
        $planes   = $projects->flatMap(fn ($p) => $p['referencias']->pluck('plan'))->filter();

        return response()->json([
            'success' => true,
            'data'    => $projects,
            'meta'    => [
                'total_proyectos'     => $projects->count(),
                'total_referencias'   => $projects->sum(fn ($p) => $p['referencias']->count()),
                'total_seleccionadas' => $projects->sum(fn ($p) => $p['referencias']->where('seleccionada', true)->count()),
                'total_potencial_usd' => round((float) $projects->sum('potencial_anual_usd'), 2),
                'total_potencial_kg'  => round((float) $projects->sum('potencial_anual_kg'), 2),
                'anio'                => $anio,
                // Venta estimada del año según el plan de despachos, total y por mes
                'total_anio_kg'       => round((float) $planes->sum('total_kg'), 2),
                'total_anio_usd'      => round((float) $planes->sum('total_usd'), 2),
                'meses'               => collect(range(1, 12))->map(fn ($mes) => [
                    'mes' => $mes,
                    'kg'  => round((float) $planes->sum(fn ($pl) => $pl['meses'][$mes - 1]['kg']), 2),
                    'usd' => round((float) $planes->sum(fn ($pl) => $pl['meses'][$mes - 1]['usd'] ?? 0), 2),
                ]),
            ],
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $anio = $request->integer('anio') ?: null;

        return response()->json(['success' => true, 'data' => $this->service->detail($project, $anio)]);
    }

    public function updateSelections(ProjectPotentialSelectionsRequest $request, Project $project): JsonResponse
    {
        $data = $this->service->saveSelections(
            $project,
            $request->validated('selecciones'),
            auth()->user()->name,
            $request->validated('anio'),
        );

        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => 'Referencias seleccionadas guardadas',
        ]);
    }

    public function export(ProjectPotentialExportRequest $request): StreamedResponse
    {
        $anio      = (int) ($request->validated('anio') ?? now()->year);
        $ejecutivo = $request->validated('ejecutivo');
        $writer    = new Xlsx($this->exportService->build($ejecutivo, $request->validated('estado_externo'), $anio));

        $fileName = 'potencial_a_la_vista_' . ($ejecutivo ? Str::slug($ejecutivo, '_') . '_' : '') . $anio . '.xlsx';

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment;filename=\"{$fileName}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
