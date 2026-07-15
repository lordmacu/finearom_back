<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VannaSeed extends Command
{
    protected $signature = 'vanna:seed {--fresh : Sembrar aunque el store ya tenga datos}';
    protected $description = 'Carga los datos de entrenamiento (qsql, docs, ddl) al sidecar Vanna';

    public function handle(): int
    {
        $base = rtrim(config('custom.vanna_url', env('VANNA_URL', 'http://127.0.0.1:8000')), '/');

        $health = Http::timeout(10)->get("$base/health");
        if (!$health->successful()) {
            $this->error("Sidecar no responde en $base");
            return self::FAILURE;
        }
        if (($health->json('trained', 0) > 0) && !$this->option('fresh')) {
            $this->info('El store ya tiene datos. Usa --fresh para re-sembrar.');
            return self::SUCCESS;
        }

        $dir = base_path('training');

        $total = 0;
        $failures = 0;
        $skipped = 0;

        // DDL
        if (is_file("$dir/ddl.sql")) {
            foreach (array_filter(array_map('trim', explode(';', file_get_contents("$dir/ddl.sql")))) as $ddl) {
                $total++;
                $response = Http::timeout(30)->post("$base/train", ['ddl' => $ddl . ';']);
                if (!$response->successful()) {
                    $failures++;
                }
            }
        }
        // Documentation (un doc por párrafo separado por línea en blanco)
        if (is_file("$dir/documentation.md")) {
            foreach (preg_split('/\n\s*\n/', trim(file_get_contents("$dir/documentation.md"))) as $doc) {
                if ($doc === '') continue;
                $total++;
                $response = Http::timeout(30)->post("$base/train", ['documentation' => $doc]);
                if (!$response->successful()) {
                    $failures++;
                }
            }
        }
        // Question-SQL pairs
        if (is_file("$dir/qsql.json")) {
            foreach (json_decode(file_get_contents("$dir/qsql.json"), true) ?? [] as $pair) {
                if (!isset($pair['question'], $pair['sql'])) {
                    $skipped++;
                    continue;
                }
                $total++;
                $response = Http::timeout(30)->post("$base/train", ['question' => $pair['question'], 'sql' => $pair['sql']]);
                if (!$response->successful()) {
                    $failures++;
                }
            }
        }

        if ($skipped > 0) {
            $this->warn("$skipped items de qsql.json inválidos (faltan question/sql), omitidos.");
        }

        if ($failures > 0) {
            $this->error("Seed con errores: $failures de $total items fallaron.");
            return self::FAILURE;
        }

        $this->info("Seed completado: $total items.");
        return self::SUCCESS;
    }
}
