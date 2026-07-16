<?php

namespace Tests\Feature;

use App\Services\VannaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VannaCrosscheckTest extends TestCase
{
    use DatabaseTransactions;

    private string $fixtureFile;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $tmpDir = sys_get_temp_dir();
        $this->fixtureFile = $tmpDir . '/vanna-crosscheck-fixture-' . uniqid() . '.json';
        $this->reportPath = $tmpDir . '/vanna-crosscheck-report-' . uniqid() . '.md';

        file_put_contents($this->fixtureFile, json_encode([
            ['question' => '¿cuántos clientes hay?', 'sql' => 'SELECT COUNT(*) FROM clients'],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        foreach ([$this->fixtureFile, $this->reportPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_crosscheck_marks_matching_scalar_results_as_coincide(): void
    {
        $this->mock(VannaService::class, function ($m) {
            $m->shouldReceive('retrieve')->andReturn([
                'examples' => [],
                'ddl' => [],
                'documentation' => [],
            ]);
        });

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'SELECT COUNT(*) FROM clients']],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:crosscheck', [
            '--file' => $this->fixtureFile,
            '--report' => $this->reportPath,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.deepseek.com/chat/completions');
        });

        $this->assertFileExists($this->reportPath);
        $report = file_get_contents($this->reportPath);
        $this->assertStringContainsString('¿cuántos clientes hay?', $report);
        $this->assertMatchesRegularExpression('/## COINCIDE \(1\)/', $report);
        $this->assertDoesNotMatchRegularExpression('/## DIVERGE \(1\)/', $report);
    }
}
