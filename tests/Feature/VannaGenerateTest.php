<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VannaGenerateTest extends TestCase
{
    use DatabaseTransactions;

    private string $generatedPath;

    private ?string $qsqlBackup = null;

    private string $qsqlPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generatedPath = base_path('training/qsql-generated.json');
        if (is_file($this->generatedPath)) {
            unlink($this->generatedPath);
        }

        $this->qsqlPath = base_path('training/qsql.json');
        if (is_file($this->qsqlPath)) {
            $this->qsqlBackup = file_get_contents($this->qsqlPath);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->generatedPath)) {
            unlink($this->generatedPath);
        }

        if ($this->qsqlBackup !== null) {
            file_put_contents($this->qsqlPath, $this->qsqlBackup);
        }

        parent::tearDown();
    }

    public function test_generate_keeps_only_candidates_that_execute(): void
    {
        $fakeContent = json_encode([
            ['question' => '¿cuántos clientes hay?', 'sql' => 'SELECT COUNT(*) FROM clients'],
            ['question' => '¿cuántas filas tiene una tabla inexistente?', 'sql' => 'SELECT * FROM tabla_inexistente_xyz'],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ], 200),
        ]);

        $this->artisan('vanna:generate', ['--count' => 2])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.deepseek.com/chat/completions');
        });

        $this->assertFileExists($this->generatedPath);
        $pairs = json_decode(file_get_contents($this->generatedPath), true);
        $sqls = array_column($pairs, 'sql');

        $this->assertContains('SELECT COUNT(*) FROM clients', $sqls);
        $this->assertNotContains('SELECT * FROM tabla_inexistente_xyz', $sqls);
        $this->assertCount(1, $pairs);
    }

    public function test_generate_merge_appends_accepted_pairs_to_qsql_json(): void
    {
        $seed = [
            ['question' => 'pregunta semilla', 'sql' => 'SELECT 1 AS uno'],
        ];
        file_put_contents($this->qsqlPath, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $fakeContent = json_encode([
            ['question' => '¿cuántos clientes tienen nit?', 'sql' => 'SELECT COUNT(*) FROM clients WHERE nit IS NOT NULL'],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:generate', ['--count' => 1, '--merge' => true])->assertExitCode(0);

        $merged = json_decode(file_get_contents($this->qsqlPath), true);
        $sqls = array_column($merged, 'sql');
        $this->assertContains('SELECT 1 AS uno', $sqls);
        $this->assertContains('SELECT COUNT(*) FROM clients WHERE nit IS NOT NULL', $sqls);
    }

    public function test_generate_dedups_against_existing_qsql_json(): void
    {
        $existingSql = 'SELECT COUNT(*) FROM clients';
        file_put_contents($this->qsqlPath, json_encode([
            ['question' => 'cuantos clientes hay', 'sql' => $existingSql],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $fakeContent = json_encode([
            ['question' => 'cuantos clientes hay en total', 'sql' => $existingSql],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:generate', ['--count' => 1])->assertExitCode(0);

        $pairs = json_decode(file_get_contents($this->generatedPath), true);
        $this->assertCount(0, $pairs, 'un SQL ya presente en qsql.json no debe generarse como nuevo');
    }

    public function test_generate_parses_markdown_fenced_json(): void
    {
        $fakeContent = "Aquí está el resultado:\n```json\n" . json_encode([
            ['question' => 'cuantos productos hay', 'sql' => 'SELECT COUNT(*) FROM products'],
        ], JSON_UNESCAPED_UNICODE) . "\n```";

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:generate', ['--count' => 1])->assertExitCode(0);

        $pairs = json_decode(file_get_contents($this->generatedPath), true);
        $sqls = array_column($pairs, 'sql');
        $this->assertContains('SELECT COUNT(*) FROM products', $sqls);
    }
}
