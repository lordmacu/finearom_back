<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Config; // Importar Config


class BackupDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Handle the job.
     */
    public function handle()
    {
      
        $dbHost = config('database.connections.mysql.host');
        $dbUsername = config('database.connections.mysql.username');
        $dbPassword = config('database.connections.mysql.password');
        $dbName = config('database.connections.mysql.database');
        // Ruta para almacenar el backup en storage/app/backups
        $backupPath = storage_path('app/backups/');
        if (!file_exists($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        // Crear el nombre del archivo de backup con timestamp
        $fileName = 'backup_' . Carbon::now()->setTimezone('America/Bogota')->format('Y-m-d_H-i-s') . '.sql';


        // Comando para generar el backup
        $process = new Process([
            'mysqldump',
            '--user=' . $dbUsername,
            '--password=' . $dbPassword,
            '--host=' . $dbHost,
            $dbName,
            '--result-file=' . $backupPath . $fileName,
        ]);

        $process->run();

        if ($process->isSuccessful()) {
            echo "Backup completed successfully";
        } else {
            echo "Backup failed";
        }

        // Eliminar archivos viejos
        $this->deleteOldBackups($backupPath);
    }

    /**
     * Elimina los backups que tienen más de una semana.
     */
    private function deleteOldBackups($backupPath)
    {
        $files = glob($backupPath . '*.sql');

        foreach ($files as $file) {
            if (filemtime($file) < Carbon::now()->subWeek()->timestamp) {
                unlink($file);
            }
        }
    }
}
