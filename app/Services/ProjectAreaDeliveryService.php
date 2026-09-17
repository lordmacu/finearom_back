<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAreaDeliveryLog;
use App\Models\ProjectFile;
use App\Models\ProjectStatusHistory;
use App\Models\User;
use App\Support\HtmlText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Entregas de las áreas con modal de notas + adjuntos (aplicaciones,
 * evaluaciones, marketing, regulatoria, especiales) como bitácora: cada
 * entrega se guarda aparte con sus archivos y su correo.
 *
 * - parcial: el área sigue en proceso.
 * - final: marca el área entregada (workflow).
 * - actualizacion: cualquier entrega después de la final.
 */
class ProjectAreaDeliveryService
{
    /**
     * Relación 1:1 donde se reflejan las últimas notas (null = tabla genérica
     * project_area_deliveries), columna de estado, categoría de archivos y
     * departamento del workflow.
     */
    public const AREAS = [
        'aplicaciones' => ['relation' => 'application',       'estado' => 'estado_laboratorio',  'departamento' => 'laboratorio',  'label' => 'Aplicaciones'],
        'evaluaciones' => ['relation' => 'evaluation',        'estado' => 'estado_evaluaciones', 'departamento' => 'evaluaciones', 'label' => 'Evaluaciones'],
        'marketing'    => ['relation' => 'marketingYCalidad', 'estado' => 'estado_mercadeo',     'departamento' => 'mercadeo',     'label' => 'Marketing'],
        'regulatoria'  => ['relation' => null,                'estado' => 'estado_calidad',      'departamento' => 'calidad',      'label' => 'Regulatoria'],
        'especiales'   => ['relation' => null,                'estado' => 'estado_especiales',   'departamento' => 'especiales',   'label' => 'P. Especiales'],
    ];

    public const MAX_TOTAL_B = 26214400; // 25 MB por correo

    public function __construct(
        private readonly ProjectWorkflowService $workflowService,
        private readonly ProjectMailService $projectMailService,
    ) {}

    /** Últimas notas, todos los adjuntos del área y la bitácora (más reciente primero). */
    public function show(Project $project, string $area): array
    {
        $logs = $project->deliveryLogs()
            ->where('area', $area)
            ->with('files')
            ->latest('id')
            ->get();

        return [
            'entregado'     => (bool) $project->{self::AREAS[$area]['estado']},
            'notas_entrega' => $project->notasEntrega($area),
            'adjuntos'      => $project->files()
                ->where('categoria', $area)
                ->orderBy('created_at')
                ->get()
                ->map(fn (ProjectFile $f) => $this->archivo($project, $f))
                ->all(),
            'entregas'      => $logs->map(fn (ProjectAreaDeliveryLog $log) => [
                'id'         => $log->id,
                'tipo'       => $log->tipo,
                'notas'      => $log->notas,
                'ejecutivo'  => $log->ejecutivo,
                'created_at' => $log->created_at?->toIso8601String(),
                'adjuntos'   => $log->files->map(fn (ProjectFile $f) => $this->archivo($project, $f))->all(),
            ])->all(),
        ];
    }

    /**
     * Registra una entrega. Devuelve null si los adjuntos superan el límite del correo.
     *
     * @param UploadedFile[] $adjuntos
     */
    public function deliver(Project $project, string $area, bool $parcial, ?string $notas, array $adjuntos, User $user): ?ProjectAreaDeliveryLog
    {
        $cfg = self::AREAS[$area];

        if (collect($adjuntos)->sum(fn (UploadedFile $f) => $f->getSize()) > self::MAX_TOTAL_B) {
            return null;
        }

        $yaEntregado = (bool) $project->{$cfg['estado']};
        $tipo = match (true) {
            $yaEntregado => ProjectAreaDeliveryLog::ACTUALIZACION,
            $parcial     => ProjectAreaDeliveryLog::PARCIAL,
            default      => ProjectAreaDeliveryLog::FINAL,
        };

        $notasAntes = $project->notasEntrega($area);

        $log = DB::transaction(function () use ($project, $area, $cfg, $tipo, $notas, $adjuntos, $user) {
            $log = $project->deliveryLogs()->create([
                'area'      => $area,
                'tipo'      => $tipo,
                'notas'     => $notas,
                'ejecutivo' => $user->name,
                'user_id'   => $user->id,
            ]);

            // Las pestañas del proyecto muestran las notas de la última entrega
            if (!HtmlText::isBlank($notas)) {
                if ($cfg['relation']) {
                    $subentidad = $project->{$cfg['relation']}()->firstOrNew(['project_id' => $project->id]);
                    $subentidad->notas_entrega = $notas;
                    $subentidad->save();
                } else {
                    $project->areaDeliveries()->updateOrCreate(['area' => $area], ['notas_entrega' => $notas]);
                }
            }

            foreach ($adjuntos as $archivo) {
                $nombreStorage = Str::uuid() . '.' . $archivo->getClientOriginalExtension();
                $path = Storage::disk('local')->putFileAs("project-files/{$project->id}", $archivo, $nombreStorage);

                ProjectFile::create([
                    'project_id'      => $project->id,
                    'nombre_original' => $archivo->getClientOriginalName(),
                    'nombre_storage'  => $nombreStorage,
                    'path'            => $path,
                    'mime_type'       => $archivo->getMimeType(),
                    'size'            => $archivo->getSize(),
                    'categoria'       => $area,
                    'delivery_log_id' => $log->id,
                    'ejecutivo'       => $user->name,
                ]);
            }

            return $log;
        });

        if ($tipo === ProjectAreaDeliveryLog::PARCIAL) {
            ProjectStatusHistory::create([
                'project_id'  => $project->id,
                'tipo'        => 'departamento',
                'descripcion' => "{$cfg['label']} registró una entrega parcial",
                'ejecutivo'   => $user->name,
            ]);
        } else {
            $this->workflowService->deliver($project, $cfg['departamento'], $user->name);
        }

        // Correo al hilo con las notas y los adjuntos de ESTA entrega (silencioso)
        $this->projectMailService->sendAreaDelivered($project->fresh(), $area, $log->load('files'), $notasAntes);

        return $log;
    }

    public function label(string $area): string
    {
        return self::AREAS[$area]['label'];
    }

    private function archivo(Project $project, ProjectFile $f): array
    {
        return [
            'id'           => $f->id,
            'nombre'       => $f->nombre_original,
            'size'         => $f->size,
            'ejecutivo'    => $f->ejecutivo,
            'created_at'   => $f->created_at,
            'download_url' => "/api/projects/{$project->id}/files/{$f->id}/download",
        ];
    }
}
