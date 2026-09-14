<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\ProjectMailService;
use App\Services\ProjectWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Entrega con notas + adjuntos de las áreas hechas por fuera de la plataforma
 * (Evaluaciones y Marketing): modal con editor enriquecido y archivos que
 * viajan adjuntos en el correo del hilo.
 *
 * Los adjuntos viven en project_files con la categoria del área. Límites
 * anti-problemas SMTP: 10 MB por archivo y 25 MB en total (conservados + nuevos).
 */
class ProjectAreaDeliveryController extends Controller
{
    private const MAX_FILE_KB = 10240;      // 10 MB por archivo
    private const MAX_TOTAL_B = 26214400;   // 25 MB en total

    /**
     * Config por área: relación 1:1 donde van las notas (null = tabla genérica
     * project_area_deliveries), columna de estado y categoría de archivos.
     */
    private const AREAS = [
        'aplicaciones' => [
            'relation'  => 'application',
            'estado'    => 'estado_laboratorio',
            'categoria' => 'aplicaciones',
        ],
        'evaluaciones' => [
            'relation'  => 'evaluation',
            'estado'    => 'estado_evaluaciones',
            'categoria' => 'evaluaciones',
        ],
        'marketing' => [
            'relation'  => 'marketingYCalidad',
            'estado'    => 'estado_mercadeo',
            'categoria' => 'marketing',
        ],
        'regulatoria' => [
            'relation'  => null,
            'estado'    => 'estado_calidad',
            'categoria' => 'regulatoria',
        ],
        'especiales' => [
            'relation'  => null,
            'estado'    => 'estado_especiales',
            'categoria' => 'especiales',
        ],
    ];

    public function __construct(
        private readonly ProjectWorkflowService $workflowService,
        private readonly ProjectMailService $projectMailService,
    ) {
        $this->middleware('can:project list')->only(['showAplicaciones', 'showEvaluaciones', 'showMarketing', 'showRegulatoria', 'showEspeciales']);
        $this->middleware('can:project deliver')->only(['deliverAplicaciones', 'deliverEvaluaciones', 'deliverMarketing', 'deliverRegulatoria', 'deliverEspeciales']);
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

    public function deliverAplicaciones(Request $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'aplicaciones');
    }

    public function deliverEvaluaciones(Request $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'evaluaciones');
    }

    public function deliverMarketing(Request $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'marketing');
    }

    public function deliverRegulatoria(Request $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'regulatoria');
    }

    public function deliverEspeciales(Request $request, Project $project): JsonResponse
    {
        return $this->deliverArea($request, $project, 'especiales');
    }

    /** Datos del modal / vista: notas previas + adjuntos actuales. */
    private function showArea(Project $project, string $area): JsonResponse
    {
        $cfg = self::AREAS[$area];

        return response()->json([
            'success' => true,
            'data'    => [
                'notas_entrega' => $project->notasEntrega($area),
                'adjuntos'      => $this->adjuntos($project, $cfg['categoria']),
            ],
        ]);
    }

    private function deliverArea(Request $request, Project $project, string $area): JsonResponse
    {
        $cfg = self::AREAS[$area];

        $request->validate([
            'notas'       => 'nullable|string|max:20000',
            'adjuntos'    => 'nullable|array|max:10',
            'adjuntos.*'  => 'file|max:' . self::MAX_FILE_KB . '|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip',
            'eliminar'    => 'nullable|array',
            'eliminar.*'  => 'integer',
        ]);

        $eliminar = collect($request->input('eliminar', []));
        $conservados = $project->files()
            ->where('categoria', $cfg['categoria'])
            ->whereNotIn('id', $eliminar)
            ->get();

        // Tamaño total: conservados + nuevos, contra el límite del correo
        $total = $conservados->sum('size')
            + collect($request->file('adjuntos', []))->sum(fn ($f) => $f->getSize());

        if ($total > self::MAX_TOTAL_B) {
            return response()->json([
                'success' => false,
                'message' => 'El tamaño total de los adjuntos supera 25 MB. Reduce archivos para que el correo pueda salir.',
            ], 422);
        }

        $user         = auth()->user();
        $wasDelivered = (bool) $project->{$cfg['estado']};
        $notasAntes   = $project->notasEntrega($area);

        DB::transaction(function () use ($request, $project, $area, $eliminar, $cfg, $user) {
            // Áreas con subentidad 1:1 guardan ahí; las demás en project_area_deliveries
            if ($cfg['relation']) {
                $subentidad = $project->{$cfg['relation']}()->firstOrNew(['project_id' => $project->id]);
                $subentidad->notas_entrega = $request->input('notas');
                $subentidad->save();
            } else {
                $project->areaDeliveries()->updateOrCreate(
                    ['area' => $area],
                    ['notas_entrega' => $request->input('notas')]
                );
            }

            // Eliminar los marcados (solo adjuntos de esta área en este proyecto)
            $project->files()
                ->where('categoria', $cfg['categoria'])
                ->whereIn('id', $eliminar)
                ->get()
                ->each(function (ProjectFile $file) {
                    Storage::disk('local')->delete($file->path);
                    $file->delete();
                });

            foreach ($request->file('adjuntos', []) as $archivo) {
                $nombreOriginal = $archivo->getClientOriginalName();
                $nombreStorage  = Str::uuid() . '.' . $archivo->getClientOriginalExtension();
                $path           = Storage::disk('local')
                    ->putFileAs("project-files/{$project->id}", $archivo, $nombreStorage);

                ProjectFile::create([
                    'project_id'      => $project->id,
                    'nombre_original' => $nombreOriginal,
                    'nombre_storage'  => $nombreStorage,
                    'path'            => $path,
                    'mime_type'       => $archivo->getMimeType(),
                    'size'            => $archivo->getSize(),
                    'categoria'       => $cfg['categoria'],
                    'ejecutivo'       => $user->name,
                ]);
            }
        });

        $this->workflowService->deliver($project, $this->department($area), $user->name);

        // Correo al hilo con notas + adjuntos (silencioso). Se atribuye al área,
        // no a $user: al equipo le importa qué área entregó.
        $this->projectMailService->sendAreaDelivered($project->fresh(), $area, $wasDelivered, $notasAntes);

        $cfg = self::AREAS[$area];

        return response()->json([
            'success' => true,
            'data'    => [
                'project'       => $project->fresh(),
                'notas_entrega' => $project->fresh()->notasEntrega($area),
                'adjuntos'      => $this->adjuntos($project, $cfg['categoria']),
            ],
            'message' => $wasDelivered
                ? "Actualización de {$area} enviada"
                : match ($area) {
                    'marketing'    => 'Marketing entregado',
                    'aplicaciones' => 'Aplicaciones entregadas',
                    'regulatoria'  => 'Regulatoria entregada',
                    'especiales'   => 'P. Especiales entregado',
                    default        => 'Evaluaciones entregadas',
                },
        ]);
    }

    /** Área del modal → departamento del workflow (aplicaciones→laboratorio, marketing→mercadeo, regulatoria→calidad). */
    private function department(string $area): string
    {
        return match ($area) {
            'aplicaciones' => 'laboratorio',
            'marketing'    => 'mercadeo',
            'regulatoria'  => 'calidad',
            'especiales'   => 'especiales',
            default        => 'evaluaciones',
        };
    }

    private function adjuntos(Project $project, string $categoria): array
    {
        return $project->files()
            ->where('categoria', $categoria)
            ->orderBy('created_at')
            ->get(['id', 'nombre_original', 'size', 'ejecutivo', 'created_at'])
            ->map(fn ($f) => [
                'id'             => $f->id,
                'nombre'         => $f->nombre_original,
                'size'           => $f->size,
                'ejecutivo'      => $f->ejecutivo,
                'created_at'     => $f->created_at,
                'download_url'   => "/api/projects/{$project->id}/files/{$f->id}/download",
            ])
            ->all();
    }
}
