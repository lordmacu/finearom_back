<?php

namespace App\Console\Commands;

use App\Services\Vanna\SqlCandidateValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VannaGenerate extends Command
{
    protected $signature = 'vanna:generate {--count=15 : Cantidad de ejemplos nuevos a solicitar al LLM} {--merge : Mezclar candidatos aceptados a qsql.json}';

    protected $description = 'Genera NUEVOS ejemplos pregunta→SQL con un LLM (DeepSeek) y los valida ejecutándolos en modo solo lectura antes de aceptarlos';

    public function __construct(
        private readonly SqlCandidateValidator $validator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));

        $qsqlPath = base_path('training/qsql.json');
        $existingPairs = is_file($qsqlPath)
            ? (json_decode(file_get_contents($qsqlPath), true) ?? [])
            : [];

        $seen = [];
        foreach ($existingPairs as $p) {
            if (isset($p['sql'])) {
                $seen[$this->validator->normalize($p['sql'])] = true;
            }
        }

        $ddlPath = base_path('training/ddl.sql');
        $ddl = is_file($ddlPath) ? file_get_contents($ddlPath) : '';

        $sampleQuestions = array_map(
            fn (array $p) => $p['question'] ?? '',
            array_slice($existingPairs, 0, 12)
        );

        $raw = $this->callDeepSeek($ddl, $sampleQuestions, $count);
        if ($raw === null) {
            $this->error('No se pudo obtener respuesta de DeepSeek.');
            return self::FAILURE;
        }

        $candidates = $this->parseCandidates($raw);
        $generated = count($candidates);

        $validated = 0;
        $accepted = [];

        foreach ($candidates as $candidate) {
            $question = trim($candidate['question'] ?? '');
            $sql = trim($candidate['sql'] ?? '');
            if (!$question || !$sql) {
                continue;
            }

            if (!$this->validator->isSafeSelect($sql)) {
                continue;
            }
            if (!$this->validator->executesReadOnly($sql)) {
                continue;
            }

            $validated++;

            $key = $this->validator->normalize($sql);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $accepted[] = ['question' => $question, 'sql' => $sql];
        }

        file_put_contents(
            base_path('training/qsql-generated.json'),
            json_encode($accepted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $nuevos = count($accepted);
        $this->info("{$generated} generados, {$validated} validaron (ejecutan), {$nuevos} nuevos tras dedup");
        $this->info('Guardado en training/qsql-generated.json');

        if ($this->option('merge')) {
            $merged = array_merge($existingPairs, $accepted);
            file_put_contents($qsqlPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Mezclados a qsql.json (total ' . count($merged) . '). Corre vanna:seed --fresh.');
        }

        return self::SUCCESS;
    }

    /**
     * Llama a DeepSeek pidiendo `$count` preguntas de negocio NUEVAS con su SQL
     * (MariaDB, solo SELECT), en JSON estricto. Retorna el contenido crudo del
     * mensaje del modelo, o null si la llamada falla.
     *
     * @param  array<int, string>  $sampleQuestions
     */
    private function callDeepSeek(string $ddl, array $sampleQuestions, int $count): ?string
    {
        $key = config('custom.deepseek_api_key');

        $samples = implode("\n", array_map(fn (string $q) => "- {$q}", array_filter($sampleQuestions)));

        $prompt = <<<PROMPT
Eres un analista de datos experto en el negocio comercial de Finearom (pedidos, cartera, clientes, productos).

Este es el esquema de la base de datos (MariaDB):
```sql
{$ddl}
```

Estas son preguntas de negocio que YA existen en el corpus de entrenamiento (para que NO las repitas, ni generes variaciones triviales de las mismas):
{$samples}

Genera exactamente {$count} preguntas de negocio NUEVAS y DISTINTAS entre sí, en español, que un analista comercial de Finearom haría, cubriendo intenciones que NO estén ya cubiertas por la lista anterior (por ejemplo: cartera vencida, cumplimiento de pronóstico, clientes inactivos, rentabilidad por producto, tiempos de despacho, etc. — lo que aplique según el esquema).

Para cada pregunta, escribe la consulta SQL en dialecto MariaDB que la responde correctamente:
- SOLO sentencias SELECT (o WITH ... SELECT) de solo lectura.
- NO uses FULL JOIN (no existe en MariaDB) ni ninguna sintaxis no soportada por MariaDB.
- NO uses DROP, DELETE, UPDATE, INSERT, ALTER, CREATE, TRUNCATE, REPLACE, GRANT, EXECUTE, INTO OUTFILE/DUMPFILE, ni LOAD_FILE.

Responde ÚNICAMENTE con un array JSON válido, sin texto adicional ni explicaciones, con esta forma exacta:
[
  {"question": "...", "sql": "..."},
  ...
]
PROMPT;

        try {
            $response = Http::withHeaders([
                    'Authorization' => "Bearer {$key}",
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(120)
                ->post('https://api.deepseek.com/chat/completions', [
                    'model'       => 'deepseek-chat',
                    'messages'    => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.4,
                    'stream'      => false,
                ]);

            if (!$response->successful()) {
                Log::error('[VannaGenerate] Error API DeepSeek: ' . $response->body());
                return null;
            }

            return (string) $response->json('choices.0.message.content', '');
        } catch (\Throwable $e) {
            Log::error('[VannaGenerate] Excepción llamando a DeepSeek: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parsea el array JSON de candidatos desde la respuesta cruda del modelo,
     * tolerando fences de markdown (```json ... ```) o texto alrededor del
     * array.
     *
     * @return array<int, array{question?: string, sql?: string}>
     */
    private function parseCandidates(string $raw): array
    {
        $raw = trim($raw);

        // Quitar fences de markdown si están presentes.
        if (preg_match('/```(?:json)?\s*(.+?)```/is', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Respaldo: extraer el primer array JSON `[ ... ]` dentro del texto.
        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
