<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectGoogleTaskConfig;
use App\Models\ProjectNotification;
use App\Models\ProjectStatusHistory;
use App\Models\User;
use Carbon\Carbon;

class ProjectWorkflowService
{
    public function __construct(
        private readonly GoogleTaskService $googleTaskService,
    ) {}

    /**
     * Dispara Google Tasks para un trigger, silencioso si falla o no está configurado.
     */
    private function fireGoogleTasks(Project $project, string $trigger, string $title): void
    {
        try {
            $config = ProjectGoogleTaskConfig::where('project_id', $project->id)
                ->where('trigger', $trigger)
                ->first();

            if ($config && !empty($config->user_ids)) {
                $this->googleTaskService->createTaskForUsers(
                    userIds: $config->user_ids,
                    title: $title,
                    notes: "Proyecto: {$project->nombre}",
                    dueDate: $project->fecha_calculada,
                );
            }
        } catch (\Throwable $e) {
            // Nunca romper el flujo del proyecto por un fallo de Google Tasks
        }
    }
    /**
     * Registra el resultado comercial del proyecto (Ganado o Perdido).
     *
     * $status: 'Ganado' | 'Perdido'
     */
    public function setExternalStatus(Project $project, string $status, string $executive, ?string $razonPerdida = null): void
    {
        $project->estado_externo      = $status;
        $project->fecha_externo       = Carbon::today();
        $project->ejecutivo_externo   = $executive;
        $project->razon_perdida       = $status === 'Perdido' ? $razonPerdida : null;
        $project->save();

        $descripcion = "Estado comercial → {$status}";
        if ($status === 'Perdido' && $razonPerdida) {
            $descripcion .= ": {$razonPerdida}";
        }
        ProjectStatusHistory::create([
            'project_id'  => $project->id,
            'tipo'        => 'externo',
            'descripcion' => $descripcion,
            'ejecutivo'   => $executive,
        ]);

        // Google Tasks: notificar cambio de estado (silencioso)
        $this->fireGoogleTasks($project, 'on_status_change', "Revisar estado: {$status} — {$project->nombre}");

        $notifData = [
            'project_id'     => $project->id,
            'project_nombre' => $project->nombre,
            'tipo_proyecto'  => $project->tipo,
        ];

        if ($status === 'Ganado') {
            $users = User::permission('project list')->get();
            foreach ($users as $user) {
                ProjectNotification::notify(
                    userId: $user->id,
                    tipo: 'proyecto_ganado',
                    titulo: 'Proyecto ganado',
                    mensaje: "El proyecto \"{$project->nombre}\" ha sido marcado como Ganado.",
                    data: $notifData,
                    projectId: $project->id,
                );
            }
        } elseif ($status === 'Perdido') {
            // Usar ejecutivo_id si existe, sino buscar por nombre como fallback
            $ejecutivoUserId = $project->ejecutivo_id
                ?? User::where('name', $project->ejecutivo)->value('id');

            if ($ejecutivoUserId) {
                ProjectNotification::notify(
                    userId: $ejecutivoUserId,
                    tipo: 'proyecto_perdido',
                    titulo: 'Proyecto perdido',
                    mensaje: "El proyecto \"{$project->nombre}\" ha sido marcado como Perdido" . ($razonPerdida ? ": {$razonPerdida}" : '.'),
                    data: array_merge($notifData, ['razon' => $razonPerdida]),
                    projectId: $project->id,
                );
            }
        }
    }

    /**
     * Marca un departamento como entregado y evalúa si el proyecto
     * debe pasar a estado_interno = 'Entregado'.
     *
     * $department: 'desarrollo' | 'laboratorio' | 'mercadeo' | 'calidad' | 'especiales'
     */
    public function deliver(Project $project, string $department, string $executive): void
    {
        $project->{"estado_{$department}"}    = true;
        $project->{"fecha_{$department}"}     = Carbon::today();
        $project->{"ejecutivo_{$department}"} = $executive;
        $project->save();

        $labels = [
            'desarrollo'  => 'Desarrollo',
            'laboratorio' => 'Laboratorio',
            'mercadeo'    => 'Mercadeo',
            'calidad'     => 'Calidad',
            'especiales'  => 'P. Especiales',
        ];
        ProjectStatusHistory::create([
            'project_id'  => $project->id,
            'tipo'        => 'departamento',
            'descripcion' => ($labels[$department] ?? $department) . ' marcó como entregado',
            'ejecutivo'   => $executive,
        ]);

        $this->checkAndUpdateInternalStatus($project);

        // Notificar a todos los usuarios con permiso de entregar proyectos
        $label   = $labels[$department] ?? $department;
        $users   = User::permission('project deliver')->get();
        foreach ($users as $user) {
            ProjectNotification::notify(
                userId: $user->id,
                tipo: 'departamento_entregado',
                titulo: "{$label} entregó el proyecto",
                mensaje: "El departamento {$label} marcó como entregado el proyecto \"{$project->nombre}\".",
                data: [
                    'project_id'     => $project->id,
                    'project_nombre' => $project->nombre,
                    'tipo_proyecto'  => $project->tipo,
                    'departamento'   => $department,
                    'ejecutivo'      => $executive,
                ],
                projectId: $project->id,
            );
        }
    }

    /**
     * Reabre un proyecto cerrado, reseteando estados a En espera / En proceso.
     */
    public function reabrir(Project $project, string $executive): void
    {
        $project->estado_externo    = 'En espera';
        $project->estado_interno    = 'En proceso';
        $project->fecha_externo     = null;
        $project->ejecutivo_externo = null;
        $project->razon_perdida     = null;
        foreach (['desarrollo', 'laboratorio', 'mercadeo', 'calidad', 'especiales'] as $dept) {
            $project->{"estado_{$dept}"}    = false;
            $project->{"fecha_{$dept}"}     = null;
            $project->{"ejecutivo_{$dept}"} = null;
        }
        $project->fecha_entrega    = null;
        $project->dias_diferencia  = null;
        $project->save();

        ProjectStatusHistory::create([
            'project_id'  => $project->id,
            'tipo'        => 'interno',
            'descripcion' => 'Proyecto reabierto — estados de departamentos resetados',
            'ejecutivo'   => $executive,
        ]);
    }

    /**
     * Evalúa si todos los departamentos requeridos para el tipo de proyecto
     * ya entregaron y, de ser así, marca el proyecto como Entregado.
     */
    public function checkAndUpdateInternalStatus(Project $project): void
    {
        $cumplido = match ($project->tipo) {
            'Colección'      => $project->estado_laboratorio
                             && $project->estado_mercadeo
                             && $project->estado_calidad,

            'Desarrollo'     => $project->estado_desarrollo
                             && $project->estado_laboratorio
                             && $project->estado_mercadeo
                             && $project->estado_calidad,

            'Fine Fragances' => $project->estado_especiales
                             && $project->estado_mercadeo
                             && $project->estado_calidad,

            default => false,
        };

        if ($cumplido) {
            $project->estado_interno  = 'Entregado';
            $project->fecha_entrega   = Carbon::today();

            if ($project->fecha_calculada) {
                // Positivo = tarde, negativo = antes de la fecha calculada
                // Se usan días hábiles para coincidir con la lógica de fecha_calculada
                $project->dias_diferencia = Carbon::today()->diffInWeekdays(
                    Carbon::parse($project->fecha_calculada),
                    false
                ) * -1;
            }

            $project->save();

            ProjectStatusHistory::create([
                'project_id'  => $project->id,
                'tipo'        => 'interno',
                'descripcion' => 'Proyecto entregado — todos los departamentos completados',
                'ejecutivo'   => null,
            ]);
        }
    }
}
