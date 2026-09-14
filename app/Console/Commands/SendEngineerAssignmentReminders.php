<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ProjectMailService;
use Illuminate\Console\Command;

class SendEngineerAssignmentReminders extends Command
{
    protected $signature   = 'projects:engineer-reminders {--dry-run : Solo lista los proyectos, no envía emails}';
    protected $description = 'Recordatorio diario a la ejecutiva: proyectos con más de 24 h sin ingeniero de desarrollo asignado';

    public function handle(ProjectMailService $mailService): int
    {
        // Ventana de 30 días: los proyectos legacy (de antes de la asignación de
        // ingeniero) nunca tuvieron ingeniero que asignar y recordarlos sería
        // spam masivo en el primer run. Solo proyectos recientes sin ingeniero.
        $query = Project::with('ejecutivoUser')
            ->whereNull('desarrollador_id')
            ->where('fecha_creacion', '<=', now()->subDay())
            ->where('fecha_creacion', '>=', today()->subDays(30))
            ->whereNotIn('estado_externo', ['Ganado', 'Perdido'])
            ->where('estado_interno', '!=', 'Entregado');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No hay proyectos pendientes de asignación de ingeniero.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$total} proyectos sin ingeniero asignado.");

        $sent = 0;
        foreach ($query->cursor() as $project) {
            $this->line("- #{$project->id} {$project->nombre} (creado {$project->fecha_creacion?->format('d/m/Y')}, ejecutivo: {$project->ejecutivo})");

            if ($this->option('dry-run')) {
                continue;
            }

            if ($mailService->sendEngineerReminder($project)) {
                $sent++;
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry-run: no se enviaron correos.'
            : "Recordatorios enviados: {$sent} de {$total}.");

        return self::SUCCESS;
    }
}
