<?php

namespace App\Console\Commands;

use App\Mail\ProjectUrgencyAlertMail;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendProjectUrgencyAlerts extends Command
{
    protected $signature   = 'projects:urgency-alerts {--dry-run : Solo lista los proyectos, no envía emails}';
    protected $description = 'Envía alertas por email para proyectos con fecha_requerida a ≤ 2 días hábiles';

    public function handle(): int
    {
        $targetDate = $this->getBusinessDayTarget(2);
        $today      = today();

        $this->info("Buscando proyectos con fecha_requerida entre {$today->toDateString()} y {$targetDate->toDateString()}...");

        $projects = Project::with(['client', 'ejecutivoUser'])
            ->whereNotNull('fecha_requerida')
            ->whereBetween('fecha_requerida', [$today->toDateString(), $targetDate->toDateString()])
            ->whereNotIn('estado_externo', ['Ganado', 'Perdido'])
            ->where('estado_interno', '!=', 'Entregado')
            ->get();

        if ($projects->isEmpty()) {
            $this->info('No hay proyectos urgentes hoy.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$projects->count()} proyectos urgentes.");

        // Destinatarios: solo usuarios internos (@finearom.com) con permiso project list
        $adminEmails = User::permission('project list')
            ->whereNotNull('email')
            ->where('email', 'like', '%@finearom.com')
            ->pluck('email')
            ->unique()
            ->values()
            ->toArray();

        foreach ($projects as $project) {
            $recipients = collect($adminEmails);

            // Agregar ejecutivo asignado si tiene email
            if ($project->ejecutivoUser?->email) {
                $recipients->push($project->ejecutivoUser->email);
            }

            $recipients = $recipients->unique()->values();

            $this->line("  • [{$project->id}] {$project->nombre} — {$project->fecha_requerida?->format('d/m/Y')} → {$recipients->implode(', ')}");

            if ($this->option('dry-run')) {
                continue;
            }

            foreach ($recipients as $email) {
                try {
                    Mail::to($email)->send(new ProjectUrgencyAlertMail($project));
                } catch (\Throwable $e) {
                    $this->warn("    ✗ No se pudo enviar a {$email}: {$e->getMessage()}");
                }
            }
        }

        if (!$this->option('dry-run')) {
            $this->info('Alertas enviadas.');
        } else {
            $this->info('[dry-run] No se enviaron emails.');
        }

        return self::SUCCESS;
    }

    /**
     * Calcula la fecha objetivo sumando N días hábiles desde hoy.
     */
    private function getBusinessDayTarget(int $businessDays): Carbon
    {
        $date  = today();
        $added = 0;

        while ($added < $businessDays) {
            $date->addDay();
            if (!in_array($date->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
                $added++;
            }
        }

        return $date;
    }
}
