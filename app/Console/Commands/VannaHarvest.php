<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VannaHarvest extends Command
{
    protected $signature = 'vanna:harvest {--merge : Mezclar candidatos aceptados a qsql.json}';
    protected $description = 'Cosecha pares pregunta→SQL del historial de chat; conserva solo SQL que ejecuta';

    private array $forbidden = ['DROP ', 'DELETE ', 'UPDATE ', 'INSERT ', 'ALTER ', 'CREATE ', 'TRUNCATE '];

    public function handle(): int
    {
        $existing = [];
        $qsqlPath = base_path('training/qsql.json');
        if (is_file($qsqlPath)) {
            foreach (json_decode(file_get_contents($qsqlPath), true) ?? [] as $p) {
                $existing[$this->norm($p['sql'])] = true;
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

                $key = $this->norm($sql);
                if (isset($seen[$key])) continue;
                if (!$this->isSafeSelect($sql)) continue;
                if (!$this->executes($sql)) continue;

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
        if (preg_match('/```sql\s*(.+?)```/is', $content, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function isSafeSelect(string $sql): bool
    {
        if (!preg_match('/^\s*(WITH|SELECT)\s/is', $sql)) return false;
        foreach ($this->forbidden as $f) {
            if (stripos($sql, $f) !== false) return false;
        }
        return true;
    }

    private function executes(string $sql): bool
    {
        try {
            DB::select('SELECT * FROM (' . rtrim($sql, "; \n\t") . ') AS _harvest_probe LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function norm(string $sql): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($sql)));
    }
}
