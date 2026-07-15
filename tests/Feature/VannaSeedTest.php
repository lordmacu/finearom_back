<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VannaSeedTest extends TestCase
{
    public function test_seed_posts_training_items_to_sidecar(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'trained' => 0]),
            '*/train'  => Http::response(['ok' => true, 'ids' => ['x']]),
        ]);

        $this->artisan('vanna:seed')->assertExitCode(0);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/train'));
    }
}
