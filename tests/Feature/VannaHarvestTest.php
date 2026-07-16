<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VannaHarvestTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $qsqlBackup = null;

    private string $qsqlPath;

    private ?string $harvestedBackup = null;

    private string $harvestedPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qsqlPath = base_path('training/qsql.json');
        if (is_file($this->qsqlPath)) {
            $this->qsqlBackup = file_get_contents($this->qsqlPath);
        }

        $this->harvestedPath = base_path('training/qsql-harvested.json');
        if (is_file($this->harvestedPath)) {
            $this->harvestedBackup = file_get_contents($this->harvestedPath);
        }
    }

    protected function tearDown(): void
    {
        // Restaurar los archivos reales de entrenamiento tal cual estaban, sin
        // importar si el test los modificó (--merge, o el propio harvest que
        // siempre reescribe qsql-harvested.json) o si nunca existieron.
        if ($this->qsqlBackup !== null) {
            file_put_contents($this->qsqlPath, $this->qsqlBackup);
        } elseif (is_file($this->qsqlPath)) {
            unlink($this->qsqlPath);
        }

        if ($this->harvestedBackup !== null) {
            file_put_contents($this->harvestedPath, $this->harvestedBackup);
        } elseif (is_file($this->harvestedPath)) {
            unlink($this->harvestedPath);
        }

        parent::tearDown();
    }

    public function test_harvest_keeps_only_executable_sql(): void
    {
        $user = User::factory()->create();
        ChatSession::create([
            'user_id'      => $user->id,
            'thread_id'    => 'test-thread',
            'period_label' => 'Julio 2026',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'messages' => [
                ['role' => 'user', 'content' => 'cuantos clientes hay'],
                ['role' => 'assistant', 'content' => "Aquí:\n<pre><code class=\"language-sql\">\nSELECT COUNT(*) FROM clients\n</code></pre>"],
                ['role' => 'user', 'content' => 'algo roto'],
                ['role' => 'assistant', 'content' => "<pre><code class=\"language-sql\">SELECT * FROM tabla_que_no_existe_xyz</code></pre>"],
                ['role' => 'user', 'content' => 'clientes con dias vencidos'],
                ['role' => 'assistant', 'content' => "<pre><code class=\"language-sql\">SELECT COUNT(*) FROM clients WHERE id &gt; 0</code></pre>"],
            ],
        ]);

        $this->artisan('vanna:harvest')->assertExitCode(0);

        $path = base_path('training/qsql-harvested.json');
        $this->assertFileExists($path);
        $pairs = json_decode(file_get_contents($path), true);
        $sqls = array_column($pairs, 'sql');
        $this->assertContains('SELECT COUNT(*) FROM clients', $sqls);
        $this->assertNotContains('SELECT * FROM tabla_que_no_existe_xyz', $sqls); // no ejecutó → descartado

        $decoded = array_filter($sqls, fn ($sql) => str_contains($sql, 'WHERE id'));
        $this->assertNotEmpty($decoded, 'debe conservar el SQL con entidad decodificada');
        foreach ($decoded as $sql) {
            $this->assertStringContainsString('>', $sql);
            $this->assertStringNotContainsString('&gt;', $sql);
        }
    }

    public function test_harvest_discards_unsafe_delete_statement(): void
    {
        $user = User::factory()->create();
        ChatSession::create([
            'user_id'      => $user->id,
            'thread_id'    => 'test-thread-unsafe',
            'period_label' => 'Julio 2026',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'messages' => [
                ['role' => 'user', 'content' => 'borra los clientes inactivos'],
                ['role' => 'assistant', 'content' => "<pre><code class=\"language-sql\">DELETE FROM clients WHERE active = 0</code></pre>"],
            ],
        ]);

        $this->artisan('vanna:harvest')->assertExitCode(0);

        $path = base_path('training/qsql-harvested.json');
        $pairs = json_decode(file_get_contents($path), true);
        $sqls = array_column($pairs, 'sql');
        $this->assertNotContains('DELETE FROM clients WHERE active = 0', $sqls, 'un DELETE nunca debe pasar el guard de seguridad');
    }

    public function test_harvest_skips_sql_duplicated_in_qsql_json(): void
    {
        $existingSql = 'SELECT COUNT(*) FROM clients WHERE nit IS NOT NULL';
        file_put_contents($this->qsqlPath, json_encode([
            ['question' => 'cuantos clientes tienen nit', 'sql' => $existingSql],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $user = User::factory()->create();
        ChatSession::create([
            'user_id'      => $user->id,
            'thread_id'    => 'test-thread-dedup',
            'period_label' => 'Julio 2026',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'messages' => [
                ['role' => 'user', 'content' => 'cuantos clientes tienen nit'],
                ['role' => 'assistant', 'content' => '<pre><code class="language-sql">' . $existingSql . '</code></pre>'],
            ],
        ]);

        $this->artisan('vanna:harvest')->assertExitCode(0);

        $path = base_path('training/qsql-harvested.json');
        $pairs = json_decode(file_get_contents($path), true);
        $sqls = array_column($pairs, 'sql');
        $this->assertNotContains($existingSql, $sqls, 'un SQL ya presente en qsql.json no debe cosecharse de nuevo');
    }

    public function test_harvest_merge_appends_new_pair_to_qsql_json(): void
    {
        $seed = [
            ['question' => 'pregunta semilla', 'sql' => 'SELECT 1 AS uno'],
        ];
        file_put_contents($this->qsqlPath, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $newSql = 'SELECT COUNT(*) FROM clients WHERE nit IS NOT NULL';
        $user = User::factory()->create();
        ChatSession::create([
            'user_id'      => $user->id,
            'thread_id'    => 'test-thread-merge',
            'period_label' => 'Julio 2026',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'messages' => [
                ['role' => 'user', 'content' => 'cuantos clientes tienen nit'],
                ['role' => 'assistant', 'content' => '<pre><code class="language-sql">' . $newSql . '</code></pre>'],
            ],
        ]);

        $this->artisan('vanna:harvest', ['--merge' => true])->assertExitCode(0);

        // Nota: la base de datos de test comparte instancia con datos reales de
        // desarrollo (ChatSession no se filtra por esta prueba), así que el
        // harvest puede cosechar pares adicionales de otras sesiones reales.
        // Por eso no se asume un conteo exacto: solo que el seed se conserva
        // y el nuevo par se agregó.
        $merged = json_decode(file_get_contents($this->qsqlPath), true);
        $sqls = array_column($merged, 'sql');
        $this->assertContains('SELECT 1 AS uno', $sqls, 'el seed original debe conservarse');
        $this->assertContains($newSql, $sqls, 'el nuevo par cosechado debe agregarse con --merge');
        $this->assertGreaterThanOrEqual(2, count($merged));
    }
}
