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
        $schedule->command('stats:generate-daily')
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

        // ⭐ Emails de pronóstico a ejecutivas — corre cada minuto; el comando
        //    compara la hora actual con `forecast_email_config.send_hour` y sólo
        //    dispara cuando coincide HH:mm y el día pertenece a `schedule_rules`.
        $schedule->command('forecast:send-emails')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->timezone('America/Bogota')
            ->onFailure(fn() => \Log::error('Error enviando emails de pronóstico'));

        // ⭐ Sync de ventas Siigo - 4 veces al día (8am, 11am, 2pm, 5pm Colombia)
        // Sincroniza desde el primer día del mes actual hasta el día actual
        foreach (['08:00', '11:00', '14:00', '17:00'] as $time) {
            $schedule->command('siigo:sync-sales')
                ->dailyAt($time)
                ->timezone('America/Bogota')
                ->withoutOverlapping()
                ->runInBackground()
                ->onSuccess(fn () => \Log::info("Siigo sync-sales completado ({$time})"))
                ->onFailure(fn () => \Log::error("Error en Siigo sync-sales ({$time})"));
        }

        // ⭐ Sync de cartera Siigo - Todos los lunes a las 8 AM (Bogota)
        // Pasa desde=primer dia del mes y hasta=dia actual al ejecutarse
        $schedule->call(function () {
            $desde = \Carbon\Carbon::now('America/Bogota')->startOfMonth()->toDateString();
            $hasta = \Carbon\Carbon::now('America/Bogota')->toDateString();
            \Illuminate\Support\Facades\Artisan::call('siigo:sync-cartera', [
                '--desde' => $desde,
                '--hasta' => $hasta,
                '--dias-mora' => -270,
                '--dias-cobro' => 10,
            ]);
        })->name('siigo-sync-cartera-lunes')
          ->weeklyOn(1, '08:00')
          ->timezone('America/Bogota')
          ->withoutOverlapping()
          ->onSuccess(fn () => \Log::info('Siigo sync-cartera completado (lunes 8am)'))
          ->onFailure(fn () => \Log::error('Error en Siigo sync-cartera (lunes 8am)'));

        // ⭐ Sync de recaudos Siigo - Todos los lunes a las 8 AM (Bogota)
        // Pasa desde=primer dia del mes y hasta=mes actual al ejecutarse
        $schedule->call(function () {
            $desde = \Carbon\Carbon::now('America/Bogota')->startOfMonth()->format('Y-m');
            $hasta = \Carbon\Carbon::now('America/Bogota')->format('Y-m');
            \Illuminate\Support\Facades\Artisan::call('siigo:sync-recaudos', [
                '--desde' => $desde,
                '--hasta' => $hasta,
            ]);
        })->name('siigo-sync-recaudos-lunes')
          ->weeklyOn(1, '08:00')
          ->timezone('America/Bogota')
          ->withoutOverlapping()
          ->onSuccess(fn () => \Log::info('Siigo sync-recaudos completado (lunes 8am)'))
          ->onFailure(fn () => \Log::error('Error en Siigo sync-recaudos (lunes 8am)'));

        // Ejecutar dispatch de emails de cartera cada 30 minutos
        $schedule->command('emails:dispatch')->everyMinute();

        // Google Tasks: alertas de proyectos próximos a vencer - 8:00 AM
        $schedule->command('google-tasks:near-deadline')
            ->dailyAt('08:00')
            ->timezone('America/Bogota');

        // Alertas de proyectos urgentes (≤ 2 días hábiles a fecha_requerida) - 7:30 AM
        // $schedule->command('projects:urgency-alerts')
        //     ->dailyAt('07:30')
        //     ->timezone('America/Bogota')
        //     ->onSuccess(fn () => \Log::info('Alertas de proyectos urgentes enviadas'))
        //     ->onFailure(fn () => \Log::error('Error al enviar alertas de proyectos urgentes'));

        // Recordatorios diarios a desarrolladores y áreas con proyectos por vencer - 8:30 AM
        // $schedule->command('projects:deadline-reminders')
        //     ->dailyAt('08:30')
        //     ->timezone('America/Bogota')
        //     ->onSuccess(fn () => \Log::info('Recordatorios de fecha límite enviados'))
        //     ->onFailure(fn () => \Log::error('Error al enviar recordatorios de fecha límite'));
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
