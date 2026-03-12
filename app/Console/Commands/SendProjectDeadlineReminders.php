<?php

namespace App\Console\Commands;

use App\Mail\ProjectUrgencyAlertMail;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendProjectDeadlineReminders extends Command
{
    protected $signature   = 'projects:deadline-reminders {--dry-run : Solo lista los proyectos, no envía emails}';
    protected $description = 'Recordatorio diario a desarrolladores: proyectos con fecha_requerida en los próximos 2 días hábiles';

    /** Mapa de campo ejecutivo por departamento → para notificar al responsable del área activa */
    private array $deptExecutiveFields = [
        'Desarrollo'  => 'ejecutivo_desarrollo',
        'Laboratorio' => 'ejecutivo_laboratorio',
        'Mercadeo'    => 'ejecutivo_mercadeo',
        'Calidad'     => 'ejecutivo_calidad',
        'Especiales'  => 'ejecutivo_especiales',
    ];

    /** Qué departamentos activos tiene cada tipo de proyecto */
    private array $deptsByTipo = [
        'Colección'     => ['Laboratorio', 'Mercadeo', 'Calidad'],
        'Desarrollo'    => ['Desarrollo', 'Laboratorio', 'Mercadeo', 'Calidad'],
        'Fine Fragances' => ['Especiales', 'Mercadeo', 'Calidad'],
    ];

    public function handle(): int
    {
        $targetDate = $this->addBusinessDays(today(), 2);
        $today      = today();

        $this->info("Buscando proyectos con fecha_requerida entre {$today->toDateString()} y {$targetDate->toDateString()}...");

        $projects = Project::with(['client', 'ejecutivoUser'])
            ->whereNotNull('fecha_requerida')
            ->whereBetween('fecha_requerida', [$today->toDateString(), $targetDate->toDateString()])
            ->whereNotIn('estado_externo', ['Ganado', 'Perdido'])
            ->where('estado_interno', '!=', 'Entregado')
            ->get();

        if ($projects->isEmpty()) {
            $this->info('No hay proyectos con fechas próximas hoy.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$projects->count()} proyectos.");

        foreach ($projects as $project) {
            $recipients = collect();

            // 1. Ejecutivo asignado al proyecto
            if ($project->ejecutivoUser?->email) {
                $recipients->push($project->ejecutivoUser->email);
            }

            // 2. Responsable del área de desarrollo (ejecutivo_desarrollo)
            $devExecutive = $project->ejecutivo_desarrollo;
            if ($devExecutive) {
                $devUser = User::where('name', $devExecutive)->whereNotNull('email')->first();
                if ($devUser) {
                    $recipients->push($devUser->email);
                }
            }

            // 3. Responsables de los departamentos activos (pendientes) del proyecto
            $departamentos = $this->deptsByTipo[$project->tipo] ?? [];
            foreach ($departamentos as $dept) {
                $estadoField    = 'estado_' . strtolower($dept === 'Especiales' ? 'especiales' : $dept);
                $ejecutivoField = $this->deptExecutiveFields[$dept] ?? null;

                // Solo notificar si el departamento aún no terminó
                if (!$project->{$estadoField} && $ejecutivoField && $project->{$ejecutivoField}) {
                    $deptUser = User::where('name', $project->{$ejecutivoField})->whereNotNull('email')->first();
                    if ($deptUser) {
                        $recipients->push($deptUser->email);
                    }
                }
            }

            // 4. Siempre incluir admins con permiso project list
            $adminEmails = User::permission('project list')
                ->whereNotNull('email')
                ->pluck('email');
            $recipients = $recipients->merge($adminEmails)->unique()->values();

            $this->line("  • [{$project->id}] {$project->nombre} — vence {$project->fecha_requerida?->format('d/m/Y')} → {$recipients->implode(', ')}");

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
            $this->info('Recordatorios enviados.');
        } else {
            $this->info('[dry-run] No se enviaron emails.');
        }

        return self::SUCCESS;
    }

    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $result = $date->copy();
        $added  = 0;
        while ($added < $days) {
            $result->addDay();
            if (!in_array($result->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
                $added++;
            }
        }
        return $result;
    }
}
