<?php

namespace App\Services;

use App\Models\Project;
use Carbon\Carbon;

class ProjectWorkflowService
{
    /**
     * Registra el resultado comercial del proyecto (Ganado o Perdido).
     *
     * $status: 'Ganado' | 'Perdido'
     */
    public function setExternalStatus(Project $project, string $status, string $executive): void
    {
        $project->estado_externo      = $status;
        $project->fecha_externo       = Carbon::today();
        $project->ejecutivo_externo   = $executive;
        $project->save();
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

        $this->checkAndUpdateInternalStatus($project);
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
            $project->save();
        }
    }
}
