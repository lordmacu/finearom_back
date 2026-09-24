<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectStatusHistory;
use App\Models\User;

class ProjectEngineerService
{
    public function __construct(
        private readonly ProjectMailService $projectMailService,
    ) {}

    /**
     * Asigna (o reasigna) el ingeniero de desarrollo. Con hilo de correo el
     * aviso sale enseguida como Re:; sin hilo se envía tras la creación
     * (ProjectController::sendCreation).
     */
    public function assign(Project $project, User $engineer, string $executive): Project
    {
        if ((int) $project->desarrollador_id === $engineer->id) {
            return $project;
        }

        $project->update(['desarrollador_id' => $engineer->id]);

        ProjectStatusHistory::create([
            'project_id'  => $project->id,
            'tipo'        => 'desarrollo',
            'descripcion' => "Ingeniero de desarrollo asignado: {$engineer->name}",
            'ejecutivo'   => $executive,
        ]);

        if ($project->email_thread_message_id) {
            $this->projectMailService->sendEngineerAssigned($project, $engineer);
        }

        return $project;
    }
}
