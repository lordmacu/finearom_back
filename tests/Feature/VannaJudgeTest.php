<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VannaJudgeTest extends TestCase
{
    private string $fixtureFile;

    private string $outPath;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $tmpDir = sys_get_temp_dir();
        $this->fixtureFile = $tmpDir . '/vanna-judge-fixture-' . uniqid() . '.json';
        $this->outPath = $tmpDir . '/vanna-judge-out-' . uniqid() . '.json';
        $this->reportPath = $tmpDir . '/vanna-judge-report-' . uniqid() . '.md';

        file_put_contents($this->fixtureFile, json_encode([
            ['question' => '¿cuántos clientes activos hay?', 'sql' => 'SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL'],
            ['question' => 'pero histórico con años', 'sql' => 'SELECT YEAR(created_at) AS anio, COUNT(*) FROM clients GROUP BY anio'],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        foreach ([$this->fixtureFile, $this->outPath, $this->reportPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_judge_keeps_only_correct_and_self_contained_candidates(): void
    {
        $fakeContent = json_encode([
            ['i' => 0, 'verdict' => 'CORRECTO', 'self_contained' => true, 'reason' => 'ok'],
            ['i' => 1, 'verdict' => 'INCORRECTO', 'self_contained' => false, 'reason' => 'fragmento'],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $fakeContent]],
                ],
            ], 200),
        ]);

        $this->artisan('vanna:judge', [
            '--file' => $this->fixtureFile,
            '--out' => $this->outPath,
            '--report' => $this->reportPath,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.deepseek.com/chat/completions');
        });

        $this->assertFileExists($this->outPath);
        $judged = json_decode(file_get_contents($this->outPath), true);
        $this->assertCount(1, $judged);
        $this->assertSame('SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL', $judged[0]['sql']);

        $this->assertFileExists($this->reportPath);
        $report = file_get_contents($this->reportPath);
        $this->assertStringContainsString('CORRECTO', $report);
        $this->assertStringContainsString('INCORRECTO', $report);
        $this->assertStringContainsString('¿cuántos clientes activos hay?', $report);
        $this->assertStringContainsString('pero histórico con años', $report);
    }
}
