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

        // DDL
        if (is_file("$dir/ddl.sql")) {
            foreach (array_filter(array_map('trim', explode(';', file_get_contents("$dir/ddl.sql")))) as $ddl) {
                Http::timeout(30)->post("$base/train", ['ddl' => $ddl . ';']);
            }
        }
        // Documentation (un doc por párrafo separado por línea en blanco)
        if (is_file("$dir/documentation.md")) {
            foreach (preg_split('/\n\s*\n/', trim(file_get_contents("$dir/documentation.md"))) as $doc) {
                if ($doc !== '') Http::timeout(30)->post("$base/train", ['documentation' => $doc]);
            }
        }
        // Question-SQL pairs
        if (is_file("$dir/qsql.json")) {
            foreach (json_decode(file_get_contents("$dir/qsql.json"), true) ?? [] as $pair) {
                Http::timeout(30)->post("$base/train", ['question' => $pair['question'], 'sql' => $pair['sql']]);
            }
        }

        $this->info('Seed completado.');
        return self::SUCCESS;
    }
}
