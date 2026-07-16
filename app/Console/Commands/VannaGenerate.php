<?php

namespace App\Console\Commands;

use App\Services\Vanna\SqlCandidateValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VannaGenerate extends Command
{
    protected $signature = 'vanna:generate
        {--count=15 : Cantidad de ejemplos nuevos a solicitar al LLM (ignorado si se pasa --questions)}
        {--merge : Mezclar candidatos aceptados a qsql.json}
        {--questions= : Ruta a un JSON con preguntas REALES (array de strings o de {"question": "..."}) para las que generar SQL fresco, en vez de inventar preguntas}
        {--out=training/qsql-generated.json : Ruta del JSON de salida con los candidatos aceptados}';

    protected $description = 'Genera ejemplos pregunta→SQL con un LLM (DeepSeek) y los valida ejecutándolos en modo solo lectura antes de aceptarlos. Sin --questions inventa preguntas nuevas; con --questions genera SQL fresco para preguntas reales dadas';

    /** Preguntas reales por lote al pedir SQL fresco vía --questions (evita truncar la respuesta del modelo). */
    private const QUESTIONS_BATCH_SIZE = 6;

    public function __construct(
        private readonly SqlCandidateValidator $validator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $outPath = $this->resolvePath((string) $this->option('out'));

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

        $docPath = base_path('training/documentation.md');
        $documentation = is_file($docPath) ? file_get_contents($docPath) : '';

        $fewShotPairs = $this->sampleFewShotPairs($existingPairs, 18);

        $questionsOption = (string) $this->option('questions');
        if ($questionsOption !== '') {
            return $this->handleRealQuestions($questionsOption, $outPath, $qsqlPath, $existingPairs, $seen, $ddl, $documentation, $fewShotPairs);
        }

        return $this->handleInventedQuestions($outPath, $qsqlPath, $existingPairs, $seen, $ddl, $documentation, $fewShotPairs);
    }

    /**
     * Modo original: le pide a DeepSeek que INVENTE `--count` preguntas nuevas
     * (no cubiertas por el corpus) junto con su SQL.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $existingPairs
     * @param  array<string, bool>  $seen
     * @param  array<int, array{question?: string, sql?: string}>  $fewShotPairs
     */
    private function handleInventedQuestions(string $outPath, string $qsqlPath, array $existingPairs, array $seen, string $ddl, string $documentation, array $fewShotPairs): int
    {
        $count = max(1, (int) $this->option('count'));

        $existingQuestions = array_map(
            fn (array $p) => $p['question'] ?? '',
            $existingPairs
        );

        $raw = $this->callDeepSeek($ddl, $documentation, $fewShotPairs, $existingQuestions, $count);
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

        $this->writeAccepted($outPath, $accepted);

        $nuevos = count($accepted);
        $this->info("{$generated} generados, {$validated} validaron (ejecutan), {$nuevos} nuevos tras dedup");
        $this->info("Guardado en {$outPath}");

        $this->maybeMerge($qsqlPath, $existingPairs, $accepted);

        return self::SUCCESS;
    }

    /**
     * Modo `--questions`: usa preguntas REALES (minadas de chats de producción)
     * como objetivos y le pide a DeepSeek que genere el SQL fresco (con la
     * lógica/esquema ACTUAL) para cada una, en lotes de
     * {@see self::QUESTIONS_BATCH_SIZE} para evitar truncar la respuesta.
     * `--count` se ignora en este modo.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $existingPairs
     * @param  array<string, bool>  $seen
     * @param  array<int, array{question?: string, sql?: string}>  $fewShotPairs
     */
    private function handleRealQuestions(string $questionsOption, string $outPath, string $qsqlPath, array $existingPairs, array $seen, string $ddl, string $documentation, array $fewShotPairs): int
    {
        $questionsPath = $this->resolvePath($questionsOption);
        if (!is_file($questionsPath)) {
            $this->error("Archivo de preguntas no encontrado: {$questionsPath}");
            return self::FAILURE;
        }

        $questions = $this->loadQuestions($questionsPath);
        if (empty($questions)) {
            $this->error("El archivo {$questionsPath} no contiene preguntas válidas.");
            return self::FAILURE;
        }

        $accepted = [];
        $executedQuestions = [];

        foreach (array_chunk($questions, self::QUESTIONS_BATCH_SIZE) as $batch) {
            $raw = $this->callDeepSeekForQuestions($ddl, $documentation, $fewShotPairs, $batch);
            if ($raw === null) {
                continue;
            }

            foreach ($this->parseCandidates($raw) as $candidate) {
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

                $executedQuestions[$question] = true;

                $key = $this->validator->normalize($sql);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $accepted[] = ['question' => $question, 'sql' => $sql];
            }
        }

        $this->writeAccepted($outPath, $accepted);

        $total = count($questions);
        $conSql = count($executedQuestions);
        $nuevos = count($accepted);
        $this->info("{$total} preguntas, {$conSql} con SQL que ejecuta, {$nuevos} nuevos tras dedup");
        $this->info("Guardado en {$outPath}");

        $sinCobertura = array_values(array_diff($questions, array_keys($executedQuestions)));
        if (!empty($sinCobertura)) {
            $this->warn(count($sinCobertura) . ' preguntas SIN SQL que ejecute (necesidades reales aún no cubiertas):');
            foreach (array_slice($sinCobertura, 0, 5) as $q) {
                $this->line("  - {$q}");
            }
            if (count($sinCobertura) > 5) {
                $this->line('  ... y ' . (count($sinCobertura) - 5) . ' más.');
            }
        }

        $this->maybeMerge($qsqlPath, $existingPairs, $accepted);

        return self::SUCCESS;
    }

    /**
     * Resuelve una ruta de opción: si ya es absoluta se usa tal cual (útil en
     * tests con rutas temporales), si no se resuelve relativa a la raíz del
     * proyecto igual que el resto de comandos vanna:*.
     */
    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $isAbsolute = str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    /**
     * Carga preguntas reales desde un JSON que puede ser un array de strings
     * o un array de objetos `{"question": "..."}`. Descarta vacíos y duplicados
     * exactos, preservando el orden de aparición.
     *
     * @return array<int, string>
     */
    private function loadQuestions(string $path): array
    {
        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $questions = [];
        foreach ($decoded as $item) {
            $question = is_array($item) ? trim((string) ($item['question'] ?? '')) : trim((string) $item);
            if ($question === '' || in_array($question, $questions, true)) {
                continue;
            }
            $questions[] = $question;
        }

        return $questions;
    }

    /**
     * Escribe el array de candidatos aceptados en `$outPath` como JSON legible.
     *
     * @param  array<int, array{question: string, sql: string}>  $accepted
     */
    private function writeAccepted(string $outPath, array $accepted): void
    {
        file_put_contents(
            $outPath,
            json_encode($accepted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Si se pasó `--merge`, agrega los candidatos aceptados a `qsql.json`.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $existingPairs
     * @param  array<int, array{question: string, sql: string}>  $accepted
     */
    private function maybeMerge(string $qsqlPath, array $existingPairs, array $accepted): void
    {
        if (!$this->option('merge')) {
            return;
        }

        $merged = array_merge($existingPairs, $accepted);
        file_put_contents($qsqlPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Mezclados a qsql.json (total ' . count($merged) . '). Corre vanna:seed --fresh.');
    }

    /**
     * Toma una muestra determinística de `$limit` pares (question, sql) completos
     * del corpus existente, repartidos a lo largo de todo el arreglo (paso fijo)
     * en vez de tomar siempre los primeros N, para variar de intención cubierta.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $pairs
     * @return array<int, array{question?: string, sql?: string}>
     */
    private function sampleFewShotPairs(array $pairs, int $limit): array
    {
        $total = count($pairs);
        if ($total === 0) {
            return [];
        }

        $step = max(1, intdiv($total, $limit));

        $sample = [];
        for ($i = 0; $i < $total && count($sample) < $limit; $i += $step) {
            $sample[] = $pairs[$i];
        }

        return $sample;
    }

    /**
     * Llama a DeepSeek pidiendo `$count` preguntas de negocio NUEVAS con su SQL
     * (MariaDB, solo SELECT), en JSON estricto. El prompt incluye el DDL completo,
     * las reglas de negocio de documentation.md y ejemplos completos (question,SQL)
     * del corpus, para que el modelo copie los patrones reales de joins/columnas
     * en vez de adivinarlos. Retorna el contenido crudo del mensaje del modelo,
     * o null si la llamada falla.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $fewShotPairs
     * @param  array<int, string>  $existingQuestions
     */
    private function callDeepSeek(string $ddl, string $documentation, array $fewShotPairs, array $existingQuestions, int $count): ?string
    {
        $key = config('custom.deepseek_api_key');

        $examplesBlock = implode("\n\n", array_map(
            function (array $p, int $i) {
                $question = $p['question'] ?? '';
                $sql = $p['sql'] ?? '';
                $n = $i + 1;
                return <<<EJ
### Ejemplo {$n}
Pregunta: {$question}
SQL:
```sql
{$sql}
```
EJ;
            },
            $fewShotPairs,
            array_keys($fewShotPairs)
        ));

        $existing = implode("\n", array_map(fn (string $q) => "- {$q}", array_filter($existingQuestions)));

        $prompt = <<<PROMPT
Eres un analista de datos experto en el negocio comercial de Finearom (pedidos, cartera, clientes, productos, pronósticos de venta).

## Esquema de la base de datos (MariaDB)
```sql
{$ddl}
```

## Reglas de negocio y convenciones SQL de Finearom (léelas y respétalas estrictamente: dialecto MariaDB, gotchas de collation, cascada de TRM, resolución de ejecutiva por email, soft delete de partials, ONLY_FULL_GROUP_BY, anti-duplicación de forecasts, etc.)
{$documentation}

## Ejemplos reales validados (pregunta → SQL exacto). Sigue estos mismos patrones de joins, nombres de columnas, CTEs y agregaciones — NO inventes columnas ni relaciones que no aparezcan aquí o en el esquema
{$examplesBlock}

## Preguntas que YA existen en el corpus de entrenamiento (no las repitas, ni generes variaciones triviales de las mismas)
{$existing}

## Tarea
Genera exactamente {$count} preguntas de negocio NUEVAS y DISTINTAS entre sí, en español, que un analista comercial de Finearom haría, cubriendo intenciones que NO estén ya cubiertas por la lista anterior (por ejemplo: cartera vencida, cumplimiento de pronóstico, clientes inactivos, rentabilidad por producto, tiempos de despacho, rezago de órdenes, comparativas entre ejecutivas, etc. — lo que aplique según el esquema y las reglas de negocio).

Para cada pregunta, escribe la consulta SQL en dialecto MariaDB que la responde correctamente, siguiendo EXACTAMENTE los patrones de columnas/joins/CTEs de los ejemplos y las reglas de negocio anteriores:
- SOLO sentencias SELECT (o WITH ... SELECT) de solo lectura.
- NO uses FULL JOIN (no existe en MariaDB) ni ninguna sintaxis no soportada por MariaDB (ver reglas de negocio arriba).
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
                    'max_tokens'  => 4000,
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
     * Llama a DeepSeek pidiendo el SQL FRESCO (con la lógica/esquema actual)
     * para un lote de preguntas REALES dadas, en JSON estricto. A diferencia
     * de {@see self::callDeepSeek()}, aquí NO se inventan preguntas: el modelo
     * debe devolver exactamente las preguntas recibidas, cada una con su SQL.
     * Usa temperatura baja (0.1) porque se busca precisión, no creatividad.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $fewShotPairs
     * @param  array<int, string>  $questions
     */
    private function callDeepSeekForQuestions(string $ddl, string $documentation, array $fewShotPairs, array $questions): ?string
    {
        $key = config('custom.deepseek_api_key');

        $examplesBlock = implode("\n\n", array_map(
            function (array $p, int $i) {
                $question = $p['question'] ?? '';
                $sql = $p['sql'] ?? '';
                $n = $i + 1;
                return <<<EJ
### Ejemplo {$n}
Pregunta: {$question}
SQL:
```sql
{$sql}
```
EJ;
            },
            $fewShotPairs,
            array_keys($fewShotPairs)
        ));

        $questionsBlock = implode("\n", array_map(
            fn (string $q, int $i) => ($i + 1) . ". {$q}",
            $questions,
            array_keys($questions)
        ));

        $prompt = <<<PROMPT
Eres un analista de datos experto en el negocio comercial de Finearom (pedidos, cartera, clientes, productos, pronósticos de venta).

## Esquema de la base de datos (MariaDB)
```sql
{$ddl}
```

## Reglas de negocio y convenciones SQL de Finearom (léelas y respétalas estrictamente: dialecto MariaDB, gotchas de collation, cascada de TRM, resolución de ejecutiva por email, soft delete de partials, ONLY_FULL_GROUP_BY, anti-duplicación de forecasts, etc.)
{$documentation}

## Ejemplos reales validados (pregunta → SQL exacto). Sigue estos mismos patrones de joins, nombres de columnas, CTEs y agregaciones — NO inventes columnas ni relaciones que no aparezcan aquí o en el esquema
{$examplesBlock}

## Tarea
Estas son preguntas REALES que analistas comerciales de Finearom hicieron (minadas de chats de producción). Para CADA una, escribe la consulta SQL en dialecto MariaDB que la responde correctamente con la lógica de negocio ACTUAL (arriba), siguiendo EXACTAMENTE los patrones de columnas/joins/CTEs de los ejemplos:

{$questionsBlock}

Reglas del SQL:
- SOLO sentencias SELECT (o WITH ... SELECT) de solo lectura.
- NO uses FULL JOIN (no existe en MariaDB) ni ninguna sintaxis no soportada por MariaDB (ver reglas de negocio arriba).
- NO uses DROP, DELETE, UPDATE, INSERT, ALTER, CREATE, TRUNCATE, REPLACE, GRANT, EXECUTE, INTO OUTFILE/DUMPFILE, ni LOAD_FILE.

Responde ÚNICAMENTE con un array JSON válido, con UN elemento por cada pregunta recibida (mismo texto exacto de la pregunta, sin modificarla), sin texto adicional ni explicaciones, con esta forma exacta:
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
                    'temperature' => 0.1,
                    'max_tokens'  => 4000,
                    'stream'      => false,
                ]);

            if (!$response->successful()) {
                Log::error('[VannaGenerate] Error API DeepSeek (questions): ' . $response->body());
                return null;
            }

            return (string) $response->json('choices.0.message.content', '');
        } catch (\Throwable $e) {
            Log::error('[VannaGenerate] Excepción llamando a DeepSeek (questions): ' . $e->getMessage());
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
