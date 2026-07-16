<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VannaSeed extends Command
{
    protected $signature = 'vanna:seed
        {--fresh : Sembrar aunque el store ya tenga datos}
        {--if-changed : Sembrar solo si el corpus cambió desde la última siembra (para el deploy)}';
    protected $description = 'Carga los datos de entrenamiento (qsql, docs, ddl) al sidecar Vanna';

    /** Dónde se recuerda el hash del corpus ya sembrado. */
    private const HASH_FILE = 'app/.vanna_corpus_hash';

    /**
     * Hash del corpus en disco. Es la identidad de lo que está sembrado: si cambia,
     * el índice quedó viejo y hay que re-sembrar.
     */
    private function corpusHash(): string
    {
        $dir = base_path('training');
        $partes = [];
        foreach (['ddl.sql', 'documentation.md', 'qsql.json'] as $f) {
            $partes[] = is_file("$dir/$f") ? file_get_contents("$dir/$f") : '';
        }

        return hash('sha256', implode("\0", $partes));
    }

    public function handle(): int
    {
        $base = rtrim(config('custom.vanna_url', env('VANNA_URL', 'http://127.0.0.1:8000')), '/');

        // --if-changed: el deploy lo llama SIEMPRE; solo siembra si el corpus cambió.
        // Existe porque el corpus viaja por git con el backend pero el índice vive en el
        // sidecar: cuando sembrar era manual, el índice se quedó viejo días enteros sin
        // ninguna señal de error, sirviendo ejemplos que ya no existían.
        $hash = $this->corpusHash();
        $hashPath = storage_path(self::HASH_FILE);
        if ($this->option('if-changed')) {
            $previo = is_file($hashPath) ? trim(file_get_contents($hashPath)) : null;
            if ($previo === $hash) {
                $this->info('Corpus sin cambios desde la última siembra — no se re-siembra.');
                return self::SUCCESS;
            }
            $this->info('El corpus cambió — re-sembrando el índice...');
        }

        $health = Http::timeout(10)->get("$base/health");
        if (!$health->successful()) {
            $this->error("Sidecar no responde en $base");
            return self::FAILURE;
        }
        if (($health->json('trained', 0) > 0) && !$this->option('fresh') && !$this->option('if-changed')) {
            $this->info('El store ya tiene datos. Usa --fresh para re-sembrar.');
            return self::SUCCESS;
        }

        // --if-changed también limpia: sembrar encima dejaría los vectores del corpus viejo
        // conviviendo con los nuevos (ejemplos borrados seguirían recuperándose).
        if ($this->option('fresh') || $this->option('if-changed')) {
            $reset = Http::timeout(30)->post("$base/reset");
            if (!$reset->successful()) {
                $this->error("No se pudo limpiar el store antes de re-sembrar ($base/reset falló). Abortando sin sembrar sobre datos sin limpiar.");
                return self::FAILURE;
            }
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
            // No se guarda el hash: el índice quedó incompleto y el próximo deploy debe reintentar.
            $this->error("Seed con errores: $failures de $total items fallaron.");
            return self::FAILURE;
        }

        // Solo tras un seed COMPLETO: el hash es la prueba de que el índice corresponde al corpus.
        if (@file_put_contents($hashPath, $hash) === false) {
            $this->warn("Sembrado OK pero no se pudo escribir $hashPath — el próximo deploy re-sembrará de más (inofensivo).");
        }

        $this->info("Seed completado: $total items.");
        return self::SUCCESS;
    }
}
