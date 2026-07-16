<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use App\Services\Vanna\SqlCandidateValidator;
use Illuminate\Console\Command;

class VannaHarvest extends Command
{
    protected $signature = 'vanna:harvest {--merge : Mezclar candidatos aceptados a qsql.json}';
    protected $description = 'Cosecha pares pregunta→SQL del historial de chat; conserva solo SQL que ejecuta';

    public function __construct(
        private readonly SqlCandidateValidator $validator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $existing = [];
        $qsqlPath = base_path('training/qsql.json');
        if (is_file($qsqlPath)) {
            foreach (json_decode(file_get_contents($qsqlPath), true) ?? [] as $p) {
                $existing[$this->validator->normalize($p['sql'])] = true;
            }
        }

        $accepted = [];
        $seen = $existing;

        foreach (ChatSession::query()->cursor() as $session) {
            $messages = $session->messages ?? [];
            for ($i = 0; $i < count($messages) - 1; $i++) {
                if (($messages[$i]['role'] ?? '') !== 'user') continue;
                if (($messages[$i + 1]['role'] ?? '') !== 'assistant') continue;

                $question = trim($messages[$i]['content'] ?? '');
                $sql = $this->extractSql($messages[$i + 1]['content'] ?? '');
                if (!$question || !$sql) continue;

                $key = $this->validator->normalize($sql);
                if (isset($seen[$key])) continue;
                if (!$this->validator->isSafeSelect($sql)) continue;
                if (!$this->validator->executesReadOnly($sql)) continue;

                $seen[$key] = true;
                $accepted[] = ['question' => $question, 'sql' => $sql];
            }
        }

        file_put_contents(
            base_path('training/qsql-harvested.json'),
            json_encode($accepted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->info(count($accepted) . ' ejemplos nuevos validados → training/qsql-harvested.json');

        if ($this->option('merge')) {
            $merged = array_merge(json_decode(file_get_contents($qsqlPath), true) ?? [], $accepted);
            file_put_contents($qsqlPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Mezclados a qsql.json (total ' . count($merged) . '). Corre vanna:seed --fresh.');
        }
        return self::SUCCESS;
    }

    private function extractSql(string $content): ?string
    {
        // El historial real de chat guarda el SQL como HTML (lo que emite
        // MonthlyReportController::parseStructuredResponse() para el frontend),
        // así que se intenta primero ese formato y se cae al markdown como respaldo.
        if (preg_match('/<code[^>]*class="[^"]*language-sql[^"]*"[^>]*>(.+?)<\/code>/is', $content, $m)) {
            $sql = $m[1];
        } elseif (preg_match('/```sql\s*(.+?)```/is', $content, $m)) {
            $sql = $m[1];
        } else {
            return null;
        }

        return trim(html_entity_decode($sql, ENT_QUOTES | ENT_HTML5));
    }
}
