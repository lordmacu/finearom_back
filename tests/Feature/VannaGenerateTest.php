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

    /** @var array<int, string> rutas temporales creadas por un test individual, borradas en tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Ruta temporal por defecto para --out: nunca tocar el archivo real
        // training/qsql-generated.json durante los tests.
        $this->generatedPath = sys_get_temp_dir() . '/qsql_generated_' . uniqid() . '.json';
        $this->tempFiles[] = $this->generatedPath;

        // qsql.json SÍ es leído por el comando por su ruta real (base_path),
        // así que se respalda y se restaura para no dejarlo mutado.
        $this->qsqlPath = base_path('training/qsql.json');
        if (is_file($this->qsqlPath)) {
            $this->qsqlBackup = file_get_contents($this->qsqlPath);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if ($this->qsqlBackup !== null) {
            file_put_contents($this->qsqlPath, $this->qsqlBackup);
        }

        parent::tearDown();
    }

    /**
     * Crea un archivo temporal con el contenido dado y lo registra para
     * limpieza automática en tearDown.
     */
    private function tempFile(string $contents, string $suffix = '.json'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vanna_') . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
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

        $this->artisan('vanna:generate', ['--count' => 2, '--out' => $this->generatedPath])->assertExitCode(0);

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

        $this->artisan('vanna:generate', ['--count' => 1, '--merge' => true, '--out' => $this->generatedPath])->assertExitCode(0);

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

        $this->artisan('vanna:generate', ['--count' => 1, '--out' => $this->generatedPath])->assertExitCode(0);

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

        $this->artisan('vanna:generate', ['--count' => 1, '--out' => $this->generatedPath])->assertExitCode(0);

        $pairs = json_decode(file_get_contents($this->generatedPath), true);
        $sqls = array_column($pairs, 'sql');
        $this->assertContains('SELECT COUNT(*) FROM products', $sqls);
    }

    public function test_generate_questions_mode_uses_real_questions_and_keeps_executing_sql(): void
    {
        $questionsPath = $this->tempFile(json_encode(
            ['cuántos clientes hay'],
            JSON_UNESCAPED_UNICODE
        ));

        $fakeContent = json_encode([
            ['question' => 'cuántos clientes hay', 'sql' => 'SELECT COUNT(*) FROM clients'],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:generate', [
            '--questions' => $questionsPath,
            '--out' => $this->generatedPath,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.deepseek.com/chat/completions');
        });

        $this->assertFileExists($this->generatedPath);
        $pairs = json_decode(file_get_contents($this->generatedPath), true);

        $this->assertCount(1, $pairs);
        $this->assertSame('cuántos clientes hay', $pairs[0]['question']);
        $this->assertSame('SELECT COUNT(*) FROM clients', $pairs[0]['sql']);
    }

    public function test_generate_questions_mode_drops_sql_that_does_not_execute(): void
    {
        $questionsPath = $this->tempFile(json_encode(
            ['cuántas filas tiene una tabla inexistente'],
            JSON_UNESCAPED_UNICODE
        ));

        $fakeContent = json_encode([
            ['question' => 'cuántas filas tiene una tabla inexistente', 'sql' => 'SELECT * FROM tabla_inexistente_xyz'],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:generate', [
            '--questions' => $questionsPath,
            '--out' => $this->generatedPath,
        ])->assertExitCode(0);

        $this->assertFileExists($this->generatedPath);
        $pairs = json_decode(file_get_contents($this->generatedPath), true);
        $this->assertCount(0, $pairs, 'un SQL que no ejecuta no debe quedar en el archivo de salida');
    }

    public function test_generate_questions_mode_accepts_object_form_and_ignores_count(): void
    {
        $questionsPath = $this->tempFile(json_encode(
            [['question' => 'cuántos productos hay']],
            JSON_UNESCAPED_UNICODE
        ));

        $fakeContent = json_encode([
            ['question' => 'cuántos productos hay', 'sql' => 'SELECT COUNT(*) FROM products'],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:generate', [
            '--questions' => $questionsPath,
            '--out' => $this->generatedPath,
            '--count' => 50,
        ])->assertExitCode(0);

        $pairs = json_decode(file_get_contents($this->generatedPath), true);
        $this->assertCount(1, $pairs);
        $this->assertSame('SELECT COUNT(*) FROM products', $pairs[0]['sql']);
    }
}
