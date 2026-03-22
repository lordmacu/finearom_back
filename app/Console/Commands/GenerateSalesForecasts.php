<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateSalesForecasts extends Command
{
    protected $signature   = 'sales:forecast';
    protected $description = 'Genera pronósticos de ventas (Holt-Winters, Croston, Theta, XGBoost) para todos los clientes y productos';

    public function handle(): int
    {
        $this->info('[sales:forecast] Iniciando generación de pronósticos...');

        $script = base_path('scripts/generate_forecasts.py');
        $python = $this->resolvePython();

        $this->line("Python: $python");
        $this->line("Script: $script");

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open("$python $script", $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->error('No se pudo iniciar el proceso Python.');
            return self::FAILURE;
        }

        fclose($pipes[0]);

        // Leer output línea a línea para mostrar progreso en tiempo real
        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $this->line(trim($line));
            }
        }

        $stderr   = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->error("El script Python falló (exit code $exitCode):");
            $this->error($stderr);
            return self::FAILURE;
        }

        $this->info('[sales:forecast] Completado exitosamente.');
        return self::SUCCESS;
    }

    private function resolvePython(): string
    {
        foreach (['python3', 'python'] as $bin) {
            $path = trim(shell_exec("which $bin 2>/dev/null") ?? '');
            if ($path) return $path;
        }
        return 'python3';
    }
}
