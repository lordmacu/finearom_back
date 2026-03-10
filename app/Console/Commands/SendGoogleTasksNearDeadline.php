<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectGoogleTaskConfig;
use App\Services\GoogleTaskService;
use Illuminate\Console\Command;

class SendGoogleTasksNearDeadline extends Command
{
    protected $signature   = 'google-tasks:near-deadline {--days=3 : Días antes del vencimiento}';
    protected $description = 'Crea tareas Google Tasks para proyectos próximos a vencer';

    public function __construct(
        private readonly GoogleTaskService $googleTaskService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days       = (int) $this->option('days');
        $targetDate = today()->addDays($days)->toDateString();

        $this->info("Buscando proyectos con fecha_calculada = {$targetDate}...");

        $projectIds = ProjectGoogleTaskConfig::where('trigger', 'near_deadline')
            ->pluck('project_id');

        if ($projectIds->isEmpty()) {
            $this->info('No hay proyectos con configuración near_deadline.');
            return self::SUCCESS;
        }

        $projects = Project::whereIn('id', $projectIds)
            ->where('fecha_calculada', $targetDate)
            ->where('estado_interno', 'En proceso')
            ->get();

        if ($projects->isEmpty()) {
            $this->info("No hay proyectos que venzan en {$days} días.");
            return self::SUCCESS;
        }

        $this->info("Procesando {$projects->count()} proyectos...");

        foreach ($projects as $project) {
            $config = ProjectGoogleTaskConfig::where('project_id', $project->id)
                ->where('trigger', 'near_deadline')
                ->first();

            if (!$config || empty($config->user_ids)) {
                continue;
            }

            try {
                $this->googleTaskService->createTaskForUsers(
                    userIds: $config->user_ids,
                    title: "⚠️ Vence en {$days} días: {$project->nombre}",
                    notes: "El proyecto \"{$project->nombre}\" vence el {$targetDate}.\nTipo: {$project->tipo}",
                    dueDate: $project->fecha_calculada,
                );
                $this->line("  ✓ Proyecto #{$project->id}: {$project->nombre}");
            } catch (\Throwable $e) {
                $this->warn("  ✗ Proyecto #{$project->id}: {$e->getMessage()}");
            }
        }

        $this->info('Listo.');
        return self::SUCCESS;
    }
}
