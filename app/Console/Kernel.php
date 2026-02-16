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

        // ⭐ Generar estadísticas de órdenes - 6:00 AM (antes del TRM)
        $schedule->command('statistics:generate-daily')
            ->dailyAt('06:00')
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
