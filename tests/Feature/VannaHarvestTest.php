<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VannaHarvestTest extends TestCase
{
    use DatabaseTransactions;

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
}
