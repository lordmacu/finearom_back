<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VannaJudge extends Command
{
    protected $signature = 'vanna:judge
        {--file=training/qsql-harvested.json : Ruta al JSON de candidatos (question, sql) a evaluar}
        {--out=training/qsql-judged.json : Ruta donde escribir los candidatos aceptados}
        {--report=training/qsql-judge-report.md : Ruta del reporte Markdown legible}';

    protected $description = 'Evalúa candidatos (pregunta, SQL) cosechados/generados contra las reglas de negocio actuales con un LLM (DeepSeek), conservando solo los correctos y autónomos';

    private const BATCH_SIZE = 8;

    private const VERDICTS = ['CORRECTO', 'DUDOSO', 'INCORRECTO'];

    public function handle(): int
    {
        $filePath = $this->resolvePath((string) $this->option('file'));
        $outPath = $this->resolvePath((string) $this->option('out'));
        $reportPath = $this->resolvePath((string) $this->option('report'));

        if (!is_file($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");
            return self::FAILURE;
        }

        $candidates = json_decode(file_get_contents($filePath), true);
        if (!is_array($candidates)) {
            $this->error("El archivo {$filePath} no contiene un array JSON válido.");
            return self::FAILURE;
        }

        $candidates = array_values($candidates);
        $total = count($candidates);

        $ddl = $this->readFile(base_path('training/ddl.sql'));
        $documentation = $this->readFile(base_path('training/documentation.md'));
        $goldPairs = $this->sampleGoldPairs($this->loadGoldPairs(), 6);

        $verdicts = [];
        foreach (array_chunk($candidates, self::BATCH_SIZE, true) as $batch) {
            $batchVerdicts = $this->judgeBatch($batch, $ddl, $documentation, $goldPairs);
            $verdicts += $batchVerdicts;
        }

        [$accepted, $reportGroups, $counts, $notSelfContained] = $this->classify($candidates, $verdicts);

        file_put_contents($outPath, json_encode($accepted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->writeReport($reportPath, $reportGroups, $total, $counts, $notSelfContained);

        $this->info(
            "{$total} candidatos: {$counts['CORRECTO']} correctos, {$counts['DUDOSO']} dudosos, "
            . "{$counts['INCORRECTO']} incorrectos, {$notSelfContained} no-autónomos"
        );
        $this->info("Aceptados en {$outPath}");
        $this->info("Reporte en {$reportPath}");

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

    private function readFile(string $path): string
    {
        return is_file($path) ? file_get_contents($path) : '';
    }

    /**
     * @return array<int, array{question?: string, sql?: string}>
     */
    private function loadGoldPairs(): array
    {
        $qsqlPath = base_path('training/qsql.json');
        if (!is_file($qsqlPath)) {
            return [];
        }

        return json_decode(file_get_contents($qsqlPath), true) ?? [];
    }

    /**
     * Toma una muestra determinística de `$limit` pares (question, sql)
     * repartidos a lo largo de todo el corpus (paso fijo), para que el juez
     * vea ejemplos representativos de distintas intenciones en vez de
     * siempre los mismos primeros N.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $pairs
     * @return array<int, array{question?: string, sql?: string}>
     */
    private function sampleGoldPairs(array $pairs, int $limit): array
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
     * Evalúa un lote (batch) de candidatos con DeepSeek. Retorna un mapa
     * índice_global => veredicto para todos los índices del batch, incluso
     * cuando la respuesta del modelo no pudo obtenerse o parsearse (en cuyo
     * caso se marcan como DUDOSO en vez de fallar el comando completo).
     *
     * @param  array<int, array{question?: string, sql?: string}>  $batch  índices preservados del arreglo original
     * @param  array<int, array{question?: string, sql?: string}>  $goldPairs
     * @return array<int, array{verdict: string, self_contained: bool, reason: string}>
     */
    private function judgeBatch(array $batch, string $ddl, string $documentation, array $goldPairs): array
    {
        $raw = $this->callDeepSeek($batch, $ddl, $documentation, $goldPairs);

        $fallback = [];
        foreach (array_keys($batch) as $idx) {
            $fallback[$idx] = [
                'verdict' => 'DUDOSO',
                'self_contained' => true,
                'reason' => 'juez no parseable',
            ];
        }

        if ($raw === null) {
            return $fallback;
        }

        $parsed = $this->parseVerdicts($raw);
        if ($parsed === []) {
            return $fallback;
        }

        $byIndex = [];
        foreach ($parsed as $item) {
            if (!is_array($item) || !isset($item['i'])) {
                continue;
            }
            $byIndex[(int) $item['i']] = $item;
        }

        $result = [];
        foreach (array_keys($batch) as $idx) {
            if (!isset($byIndex[$idx])) {
                $result[$idx] = $fallback[$idx];
                continue;
            }

            $item = $byIndex[$idx];
            $verdict = strtoupper(trim((string) ($item['verdict'] ?? 'DUDOSO')));
            if (!in_array($verdict, self::VERDICTS, true)) {
                $verdict = 'DUDOSO';
            }

            $selfContained = $item['self_contained'] ?? false;
            if (is_string($selfContained)) {
                $selfContained = filter_var($selfContained, FILTER_VALIDATE_BOOLEAN);
            }

            $result[$idx] = [
                'verdict' => $verdict,
                'self_contained' => (bool) $selfContained,
                'reason' => trim((string) ($item['reason'] ?? '')),
            ];
        }

        return $result;
    }

    /**
     * Llama a DeepSeek con el prompt de juicio para un batch de candidatos.
     * Retorna el contenido crudo del mensaje del modelo, o null si la
     * llamada falla (por HTTP o por excepción).
     *
     * @param  array<int, array{question?: string, sql?: string}>  $batch  índices preservados del arreglo original
     * @param  array<int, array{question?: string, sql?: string}>  $goldPairs
     */
    private function callDeepSeek(array $batch, string $ddl, string $documentation, array $goldPairs): ?string
    {
        $key = config('custom.deepseek_api_key');

        $goldBlock = implode("\n\n", array_map(
            function (array $p, int $i) {
                $question = $p['question'] ?? '';
                $sql = $p['sql'] ?? '';
                $n = $i + 1;
                return <<<EJ
### Ejemplo correcto {$n}
Pregunta: {$question}
SQL:
```sql
{$sql}
```
EJ;
            },
            $goldPairs,
            array_keys($goldPairs)
        ));

        $candidatesBlock = implode("\n\n", array_map(
            function (array $c, int $idx) {
                $question = $c['question'] ?? '';
                $sql = $c['sql'] ?? '';
                return <<<CAND
[{$idx}]
Pregunta: {$question}
SQL:
```sql
{$sql}
```
CAND;
            },
            $batch,
            array_keys($batch)
        ));

        $prompt = <<<PROMPT
Eres un juez de calidad experto en el negocio comercial de Finearom (pedidos, cartera, clientes, productos, pronósticos de venta). Tu tarea es evaluar candidatos de entrenamiento (pregunta, SQL) cosechados de conversaciones reales, para decidir cuáles son ejemplos correctos y autónomos aptos para un corpus de fine-tuning de texto-a-SQL.

## Esquema de la base de datos (MariaDB)
```sql
{$ddl}
```

## Reglas de negocio actuales (son la fuente de verdad — cualquier SQL que las contradiga es INCORRECTO, aunque haya ejecutado sin error)
{$documentation}

## Ejemplos correctos ya validados (pregunta → SQL exacto), como referencia de patrones idiomáticos correctos
{$goldBlock}

## Candidatos a evaluar
Cada candidato viene numerado entre corchetes [i] con su pregunta y su SQL tal como se cosecharon de una conversación real (pueden ser fragmentos de un chat de varios turnos).

{$candidatesBlock}

## Criterios de evaluación
Para cada candidato [i], decide:
- "self_contained": false si la pregunta NO se puede entender por sí sola, sin contexto previo de la conversación (ej. "cuántos USD suma?", "pero histórico con años", "cuál es el total?", "y del año pasado?" — son fragmentos de seguimiento que dependen de un turno anterior). true si la pregunta es una pregunta de negocio completa y autónoma.
- "verdict":
  - "INCORRECTO" si el SQL no responde correctamente la pregunta, o viola las reglas de negocio anteriores (columnas o joins equivocados, valores de estado desactualizados, mal uso de partials.order_id, manejo incorrecto de TRM, filtrar ejecutiva por nombre en vez de email, sintaxis no soportada por MariaDB, etc.).
  - "DUDOSO" si parece plausible pero no puedes confirmar con certeza que sea correcto.
  - "CORRECTO" únicamente si el SQL responde correcta e idiomáticamente la pregunta, siguiendo las reglas de negocio, Y la pregunta es autónoma.
- "reason": motivo breve (una frase corta).

Responde ÚNICAMENTE con un array JSON válido, sin texto adicional ni explicaciones, con esta forma exacta (un objeto por cada candidato recibido, usando el mismo índice "i"):
[
  {"i": 0, "verdict": "CORRECTO", "self_contained": true, "reason": "..."},
  ...
]
PROMPT;

        try {
            $response = Http::withHeaders([
                    'Authorization' => "Bearer {$key}",
                    'Content-Type' => 'application/json',
                ])
                ->timeout(120)
                ->post('https://api.deepseek.com/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.0,
                    'max_tokens' => 3000,
                    'stream' => false,
                ]);

            if (!$response->successful()) {
                Log::error('[VannaJudge] Error API DeepSeek: ' . $response->body());
                return null;
            }

            return (string) $response->json('choices.0.message.content', '');
        } catch (\Throwable $e) {
            Log::error('[VannaJudge] Excepción llamando a DeepSeek: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parsea el array JSON de veredictos desde la respuesta cruda del
     * modelo, tolerando fences de markdown (```json ... ```) o texto
     * alrededor del array. Retorna [] si no se pudo parsear nada.
     *
     * @return array<int, array{i?: int, verdict?: string, self_contained?: bool|string, reason?: string}>
     */
    private function parseVerdicts(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*(.+?)```/is', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Clasifica todos los candidatos según sus veredictos: arma la lista de
     * aceptados (CORRECTO && self_contained), los grupos para el reporte
     * (por veredicto) y los contadores del resumen.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $candidates
     * @param  array<int, array{verdict: string, self_contained: bool, reason: string}>  $verdicts
     * @return array{0: array<int, array{question: string, sql: string}>, 1: array<string, array<int, array{question: string, sql: string, self_contained: bool, reason: string}>>, 2: array<string, int>, 3: int}
     */
    private function classify(array $candidates, array $verdicts): array
    {
        $accepted = [];
        $counts = ['CORRECTO' => 0, 'DUDOSO' => 0, 'INCORRECTO' => 0];
        $notSelfContained = 0;
        $reportGroups = ['CORRECTO' => [], 'DUDOSO' => [], 'INCORRECTO' => []];

        foreach ($candidates as $idx => $candidate) {
            $v = $verdicts[$idx] ?? [
                'verdict' => 'DUDOSO',
                'self_contained' => true,
                'reason' => 'sin veredicto',
            ];

            $verdict = $v['verdict'];
            $selfContained = (bool) $v['self_contained'];

            $counts[$verdict]++;
            if (!$selfContained) {
                $notSelfContained++;
            }

            $question = (string) ($candidate['question'] ?? '');
            $sql = (string) ($candidate['sql'] ?? '');

            $reportGroups[$verdict][] = [
                'question' => $question,
                'sql' => $sql,
                'self_contained' => $selfContained,
                'reason' => $v['reason'],
            ];

            if ($verdict === 'CORRECTO' && $selfContained) {
                $accepted[] = ['question' => $question, 'sql' => $sql];
            }
        }

        return [$accepted, $reportGroups, $counts, $notSelfContained];
    }

    /**
     * Escribe el reporte Markdown legible con todos los candidatos
     * agrupados por veredicto.
     *
     * @param  array<string, array<int, array{question: string, sql: string, self_contained: bool, reason: string}>>  $reportGroups
     * @param  array<string, int>  $counts
     */
    private function writeReport(string $reportPath, array $reportGroups, int $total, array $counts, int $notSelfContained): void
    {
        $lines = [];
        $lines[] = '# Reporte de juicio — vanna:judge';
        $lines[] = '';
        $lines[] = "**{$total} candidatos**: {$counts['CORRECTO']} correctos, {$counts['DUDOSO']} dudosos, "
            . "{$counts['INCORRECTO']} incorrectos, {$notSelfContained} no-autónomos";
        $lines[] = '';

        foreach (['CORRECTO', 'DUDOSO', 'INCORRECTO'] as $verdict) {
            $items = $reportGroups[$verdict];
            $lines[] = "## {$verdict} (" . count($items) . ')';
            $lines[] = '';

            if ($items === []) {
                $lines[] = '_(ninguno)_';
                $lines[] = '';
                continue;
            }

            foreach ($items as $item) {
                $sqlTruncated = mb_strlen($item['sql']) > 200
                    ? mb_substr($item['sql'], 0, 200) . '…'
                    : $item['sql'];
                $sqlTruncated = str_replace("\n", ' ', $sqlTruncated);
                $autonoma = $item['self_contained'] ? 'sí' : 'no';

                $lines[] = "- **Pregunta**: {$item['question']}";
                $lines[] = "  - Autónoma: {$autonoma}";
                $lines[] = "  - Razón: {$item['reason']}";
                $lines[] = "  - SQL: `{$sqlTruncated}`";
                $lines[] = '';
            }
        }

        file_put_contents($reportPath, implode("\n", $lines));
    }
}
