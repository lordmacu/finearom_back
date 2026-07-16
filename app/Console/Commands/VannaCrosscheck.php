<?php

namespace App\Console\Commands;

use App\Services\Vanna\SqlCandidateValidator;
use App\Services\VannaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Validador diferencial: para cada par (pregunta, SQL cosechado) ya aceptado
 * por vanna:judge, genera SQL FRESCO para la misma pregunta con el sistema
 * actual (retrieval de Vanna + DeepSeek), ejecuta ambos en modo solo lectura
 * y compara sus resultados. Es una capa de calidad objetiva adicional al
 * juicio subjetivo del LLM: si el SQL cosechado y el SQL fresco coinciden en
 * su resultado, hay evidencia cruzada de que la pregunta está bien resuelta.
 */
class VannaCrosscheck extends Command
{
    protected $signature = 'vanna:crosscheck
        {--file=training/qsql-judged.json : Ruta al JSON de candidatos (question, sql) a corroborar}
        {--report=training/qsql-crosscheck-report.md : Ruta del reporte Markdown legible}';

    protected $description = 'Corrobora candidatos (pregunta, SQL cosechado) generando SQL fresco con el sistema actual, ejecutando ambos en modo solo lectura y comparando sus resultados';

    private const VERDICTS = ['COINCIDE', 'DIVERGE', 'FRESCO_FALLO', 'SIN_CONTEXTO'];

    public function __construct(
        private readonly SqlCandidateValidator $validator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filePath = $this->resolvePath((string) $this->option('file'));
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

        $ddl = $this->readFile(base_path('training/ddl.sql'));
        $documentation = $this->readFile(base_path('training/documentation.md'));

        $results = [];
        foreach ($candidates as $candidate) {
            $question = trim((string) ($candidate['question'] ?? ''));
            $sql = trim((string) ($candidate['sql'] ?? ''));
            if ($question === '' || $sql === '') {
                continue;
            }

            $results[] = $this->crosscheckCandidate($question, $sql, $ddl, $documentation);
        }

        $counts = array_fill_keys(self::VERDICTS, 0);
        foreach ($results as $r) {
            $counts[$r['verdict']]++;
        }

        $this->writeReport($reportPath, $results, $counts);

        $total = count($results);
        $this->info(
            "{$total} evaluados: {$counts['COINCIDE']} coinciden, {$counts['DIVERGE']} divergen, "
            . "{$counts['FRESCO_FALLO']} fresco-falló, {$counts['SIN_CONTEXTO']} sin-contexto"
        );
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
     * Procesa un candidato completo: obtiene el contexto de retrieval,
     * genera el SQL fresco, ejecuta ambos SQL en modo solo lectura y
     * clasifica el resultado. Nunca lanza excepción — cualquier fallo
     * inesperado se clasifica como FRESCO_FALLO con una nota explicativa.
     *
     * @return array{
     *   question: string,
     *   harvested_sql: string,
     *   fresh_sql: ?string,
     *   verdict: string,
     *   harvested: array{ok: bool, rows: array, error: ?string},
     *   fresh: ?array{ok: bool, rows: array, error: ?string},
     *   sig_harvested?: array,
     *   sig_fresh?: array,
     *   note: string
     * }
     */
    private function crosscheckCandidate(string $question, string $harvestedSql, string $ddl, string $documentation): array
    {
        try {
            try {
                $context = app(VannaService::class)->retrieve($question);
            } catch (\Throwable $e) {
                $context = null;
            }

            $harvestedExec = $this->tryExecute($harvestedSql);

            if ($context === null) {
                return [
                    'question' => $question,
                    'harvested_sql' => $harvestedSql,
                    'fresh_sql' => null,
                    'verdict' => 'SIN_CONTEXTO',
                    'harvested' => $harvestedExec,
                    'fresh' => null,
                    'note' => 'VannaService::retrieve() devolvió null (sidecar no disponible); no se generó SQL fresco.',
                ];
            }

            $freshSql = $this->generateFreshSql($question, $ddl, $documentation, $context['examples'] ?? []);

            if ($freshSql === null) {
                return [
                    'question' => $question,
                    'harvested_sql' => $harvestedSql,
                    'fresh_sql' => null,
                    'verdict' => 'FRESCO_FALLO',
                    'harvested' => $harvestedExec,
                    'fresh' => null,
                    'note' => 'No se pudo generar/extraer SQL fresco desde DeepSeek.',
                ];
            }

            $freshExec = $this->tryExecute($freshSql);

            if (!$freshExec['ok']) {
                return [
                    'question' => $question,
                    'harvested_sql' => $harvestedSql,
                    'fresh_sql' => $freshSql,
                    'verdict' => 'FRESCO_FALLO',
                    'harvested' => $harvestedExec,
                    'fresh' => $freshExec,
                    'note' => 'El SQL fresco no ejecutó: ' . ($freshExec['error'] ?? ''),
                ];
            }

            if (!$harvestedExec['ok']) {
                return [
                    'question' => $question,
                    'harvested_sql' => $harvestedSql,
                    'fresh_sql' => $freshSql,
                    'verdict' => 'DIVERGE',
                    'harvested' => $harvestedExec,
                    'fresh' => $freshExec,
                    'note' => 'El SQL cosechado ya no ejecuta (no se puede corroborar): ' . ($harvestedExec['error'] ?? ''),
                ];
            }

            $sigHarvested = $this->buildSignature($harvestedExec['rows']);
            $sigFresh = $this->buildSignature($freshExec['rows']);
            $match = $this->compareSignatures($sigHarvested, $sigFresh);

            return [
                'question' => $question,
                'harvested_sql' => $harvestedSql,
                'fresh_sql' => $freshSql,
                'verdict' => $match ? 'COINCIDE' : 'DIVERGE',
                'harvested' => $harvestedExec,
                'fresh' => $freshExec,
                'sig_harvested' => $sigHarvested,
                'sig_fresh' => $sigFresh,
                'note' => '',
            ];
        } catch (\Throwable $e) {
            Log::error('[VannaCrosscheck] Excepción procesando candidato: ' . $e->getMessage());

            return [
                'question' => $question,
                'harvested_sql' => $harvestedSql,
                'fresh_sql' => null,
                'verdict' => 'FRESCO_FALLO',
                'harvested' => ['ok' => false, 'rows' => [], 'error' => null],
                'fresh' => null,
                'note' => 'Excepción inesperada: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Ejecuta un SQL envuelto como subconsulta de solo lectura (misma
     * convención que SqlCandidateValidator::executesReadOnly, pero
     * conservando las filas para poder construir la firma de comparación).
     *
     * @return array{ok: bool, rows: array, error: ?string}
     */
    private function tryExecute(string $sql): array
    {
        if (!$this->validator->isSafeSelect($sql)) {
            return ['ok' => false, 'rows' => [], 'error' => 'El SQL no es un SELECT/WITH de solo lectura seguro.'];
        }

        try {
            $rows = DB::select('SELECT * FROM ( ' . rtrim($sql, "; \n\t") . ' ) AS _x LIMIT 200');
            return ['ok' => true, 'rows' => $rows, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Llama a DeepSeek para generar SQL fresco que responda `$question` con
     * el sistema actual: un mensaje "system" con las reglas de negocio, el
     * esquema completo y los ejemplos de retrieval como few-shot, y un
     * mensaje "user" con la pregunta tal cual. Extrae y retorna el SQL de la
     * respuesta, o null si la llamada falla o no se pudo extraer SQL.
     *
     * @param  array<int, array{question?: string, sql?: string}>  $examples
     */
    private function generateFreshSql(string $question, string $ddl, string $documentation, array $examples): ?string
    {
        $key = config('custom.deepseek_api_key');

        $examplesBlock = implode("\n\n", array_map(
            function (array $ex, int $i) {
                $q = $ex['question'] ?? '';
                $s = $ex['sql'] ?? '';
                $n = $i + 1;
                return <<<EJ
### Ejemplo {$n}
Pregunta: {$q}
SQL:
```sql
{$s}
```
EJ;
            },
            $examples,
            array_keys($examples)
        ));

        $systemPrompt = <<<PROMPT
Eres un generador experto de SQL para el negocio comercial de Finearom (pedidos, cartera, clientes, productos, pronósticos de venta).

## Esquema de la base de datos (MariaDB)
```sql
{$ddl}
```

## Reglas de negocio y convenciones SQL de Finearom (dialecto MariaDB, gotchas de collation, cascada de TRM, resolución de ejecutiva por email, soft delete de partials, ONLY_FULL_GROUP_BY, etc.)
{$documentation}

## Ejemplos relevantes (pregunta → SQL exacto), sigue estos mismos patrones de joins, columnas y agregaciones
{$examplesBlock}

## Tarea
Genera SOLO una consulta MariaDB SELECT (sin explicación, sin fences) que responda la pregunta, siguiendo estas reglas y patrones.
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
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $question],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 2000,
                    'stream' => false,
                ]);

            if (!$response->successful()) {
                Log::error('[VannaCrosscheck] Error API DeepSeek: ' . $response->body());
                return null;
            }

            $raw = (string) $response->json('choices.0.message.content', '');
            return $this->extractFreshSql($raw);
        } catch (\Throwable $e) {
            Log::error('[VannaCrosscheck] Excepción llamando a DeepSeek: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extrae el SQL de la respuesta cruda del modelo: quita fences de
     * markdown si están presentes y se queda desde el primer SELECT/WITH en
     * adelante. Retorna null si no se encontró nada parecido a SQL.
     */
    private function extractFreshSql(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:sql)?\s*(.+?)```/is', $raw, $m)) {
            $raw = trim($m[1]);
        }

        if (preg_match('/(WITH|SELECT)\b.*/is', $raw, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    /**
     * Construye una firma comparable de un resultado: si es exactamente 1
     * fila × 1 columna numérica, captura ese valor escalar; si no, captura
     * la forma (cantidad de filas y columnas).
     *
     * @return array{type: string, value?: float, row_count: int, col_count: int}
     */
    private function buildSignature(array $rows): array
    {
        $rowCount = count($rows);

        if ($rowCount === 1) {
            $cols = (array) $rows[0];
            if (count($cols) === 1) {
                $value = reset($cols);
                if (is_numeric($value)) {
                    return ['type' => 'scalar', 'value' => (float) $value, 'row_count' => 1, 'col_count' => 1];
                }
            }
        }

        $colCount = $rowCount > 0 ? count((array) $rows[0]) : 0;

        return ['type' => 'shape', 'row_count' => $rowCount, 'col_count' => $colCount];
    }

    /**
     * Compara dos firmas: si ambas son escalares, compara con tolerancia del
     * 1%; si ambas son "shape", compara la cantidad de filas; si los tipos
     * difieren, se considera divergencia.
     *
     * @param  array{type: string, value?: float, row_count: int, col_count: int}  $a
     * @param  array{type: string, value?: float, row_count: int, col_count: int}  $b
     */
    private function compareSignatures(array $a, array $b): bool
    {
        if ($a['type'] === 'scalar' && $b['type'] === 'scalar') {
            $va = $a['value'];
            $vb = $b['value'];

            if ($va == 0.0 && $vb == 0.0) {
                return true;
            }

            $tolerance = max(abs($va), abs($vb)) * 0.01;
            return abs($va - $vb) <= $tolerance;
        }

        if ($a['type'] === 'shape' && $b['type'] === 'shape') {
            return $a['row_count'] === $b['row_count'];
        }

        return false;
    }

    /**
     * Escribe el reporte Markdown legible con todos los candidatos
     * agrupados por veredicto.
     *
     * @param  array<int, array>  $results
     * @param  array<string, int>  $counts
     */
    private function writeReport(string $reportPath, array $results, array $counts): void
    {
        $lines = [];
        $lines[] = '# Reporte de crosscheck — vanna:crosscheck';
        $lines[] = '';
        $total = count($results);
        $lines[] = "**{$total} evaluados**: {$counts['COINCIDE']} coinciden, {$counts['DIVERGE']} divergen, "
            . "{$counts['FRESCO_FALLO']} fresco-falló, {$counts['SIN_CONTEXTO']} sin-contexto";
        $lines[] = '';

        foreach (self::VERDICTS as $verdict) {
            $items = array_values(array_filter($results, fn (array $r) => $r['verdict'] === $verdict));
            $lines[] = "## {$verdict} (" . count($items) . ')';
            $lines[] = '';

            if ($items === []) {
                $lines[] = '_(ninguno)_';
                $lines[] = '';
                continue;
            }

            foreach ($items as $item) {
                $lines[] = "- **Pregunta**: {$item['question']}";
                $lines[] = "  - Veredicto: {$item['verdict']}";
                $lines[] = '  - ' . $this->formatFilasLine($item);
                if ($item['note'] !== '') {
                    $lines[] = "  - Nota: {$item['note']}";
                }
                $lines[] = '  - SQL cosechado: `' . $this->truncate($item['harvested_sql']) . '`';
                $lines[] = '  - SQL fresco: `' . $this->truncate($item['fresh_sql'] ?? '(no generado)') . '`';
                $lines[] = '  - Muestra cosechado (primeras 2 filas):';
                $lines[] = '    ```';
                foreach ($this->sampleRows($item['harvested']['rows'] ?? []) as $rowLine) {
                    $lines[] = "    {$rowLine}";
                }
                $lines[] = '    ```';
                $lines[] = '';
            }
        }

        file_put_contents($reportPath, implode("\n", $lines));
    }

    private function formatFilasLine(array $item): string
    {
        $harvestedRows = $item['harvested']['ok'] ? count($item['harvested']['rows']) : 0;
        $freshRows = ($item['fresh'] !== null && $item['fresh']['ok']) ? count($item['fresh']['rows']) : 0;

        $line = "Filas: cosechado={$harvestedRows} vs fresco={$freshRows}";

        if (isset($item['sig_harvested'], $item['sig_fresh'])
            && $item['sig_harvested']['type'] === 'scalar'
            && $item['sig_fresh']['type'] === 'scalar'
        ) {
            $line .= " (escalar: {$item['sig_harvested']['value']} vs {$item['sig_fresh']['value']})";
        }

        return $line;
    }

    private function truncate(string $sql): string
    {
        $sql = str_replace("\n", ' ', $sql);
        return mb_strlen($sql) > 300 ? mb_substr($sql, 0, 300) . '…' : $sql;
    }

    /**
     * @return array<int, string>
     */
    private function sampleRows(array $rows): array
    {
        $out = [];
        foreach (array_slice($rows, 0, 2) as $row) {
            $out[] = json_encode((array) $row, JSON_UNESCAPED_UNICODE);
        }

        return $out === [] ? ['(sin filas)'] : $out;
    }
}
