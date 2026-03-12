<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // ⭐ Fetch TRM diaria desde API externa - 9:00 AM (Colombia)
        $schedule->command('trm:fetch-daily')
            ->dailyAt('09:00')
            ->timezone('America/Bogota')
            ->onSuccess(function () {
                \Log::info('TRM diaria obtenida exitosamente');
            })
            ->onFailure(function () {
                \Log::error('Error al obtener TRM diaria');
            });

        // ⭐ Generar estadísticas de órdenes - 10:00 AM (después del TRM que llega a las 9AM)
        $schedule->command('stats:daily-dispatch')
            ->dailyAt('10:00')
            ->timezone('America/Bogota')
            ->onSuccess(function () {
                \Log::info('Estadísticas diarias generadas exitosamente');
            })
            ->onFailure(function () {
                \Log::error('Error al generar estadísticas diarias');
            });

        // ⭐ Backup de base de datos - 2:00 AM
        $schedule->job(new \App\Jobs\BackupDatabaseJob)
            ->dailyAt('02:00')
            ->timezone('America/Bogota')
            ->onSuccess(function () {
                \Log::info('Backup de base de datos realizado exitosamente');
            })
            ->onFailure(function () {
                \Log::error('Error al realizar backup de base de datos');
            });

        // Ejecutar dispatch de emails de cartera cada 30 minutos
        $schedule->command('emails:dispatch')->everyMinute();

        // Google Tasks: alertas de proyectos próximos a vencer - 8:00 AM
        $schedule->command('google-tasks:near-deadline')
            ->dailyAt('08:00')
            ->timezone('America/Bogota');

        // Alertas de proyectos urgentes (≤ 2 días hábiles a fecha_requerida) - 7:30 AM
        $schedule->command('projects:urgency-alerts')
            ->dailyAt('07:30')
            ->timezone('America/Bogota')
            ->onSuccess(fn () => \Log::info('Alertas de proyectos urgentes enviadas'))
            ->onFailure(fn () => \Log::error('Error al enviar alertas de proyectos urgentes'));

        // Recordatorios diarios a desarrolladores y áreas con proyectos por vencer - 8:30 AM
        $schedule->command('projects:deadline-reminders')
            ->dailyAt('08:30')
            ->timezone('America/Bogota')
            ->onSuccess(fn () => \Log::info('Recordatorios de fecha límite enviados'))
            ->onFailure(fn () => \Log::error('Error al enviar recordatorios de fecha límite'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
