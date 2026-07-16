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

    public function test_skips_when_already_trained_and_not_fresh(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'trained' => 5]),
        ]);

        $this->artisan('vanna:seed')->assertExitCode(0);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/train'));
    }

    public function test_fresh_forces_seed_even_when_trained(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'trained' => 5]),
            '*/reset'  => Http::response(['ok' => true, 'trained' => 0]),
            '*/train'  => Http::response(['ok' => true, 'ids' => ['x']]),
        ]);

        $this->artisan('vanna:seed', ['--fresh' => true])->assertExitCode(0);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/train'));
    }

    public function test_returns_failure_when_a_train_call_fails(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'trained' => 0]),
            '*/train'  => Http::response(['message' => 'error'], 500),
        ]);

        $this->artisan('vanna:seed')->assertExitCode(1);
    }

    public function test_fresh_calls_reset_before_train(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'trained' => 5]),
            '*/reset'  => Http::response(['ok' => true, 'trained' => 0]),
            '*/train'  => Http::response(['ok' => true, 'ids' => ['x']]),
        ]);

        $this->artisan('vanna:seed', ['--fresh' => true])->assertExitCode(0);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/reset'));

        $urls = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->url())
            ->filter(fn ($url) => str_contains($url, '/reset') || str_contains($url, '/train'))
            ->values();

        $resetIndex = $urls->search(fn ($url) => str_contains($url, '/reset'));
        $firstTrainIndex = $urls->search(fn ($url) => str_contains($url, '/train'));

        $this->assertNotFalse($resetIndex, 'El request a /reset no fue enviado.');
        $this->assertNotFalse($firstTrainIndex, 'Ningun request a /train fue enviado.');
        $this->assertLessThan($firstTrainIndex, $resetIndex, '/reset debe enviarse antes que /train.');
    }

    public function test_does_not_call_reset_without_fresh(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'trained' => 0]),
            '*/train'  => Http::response(['ok' => true, 'ids' => ['x']]),
        ]);

        $this->artisan('vanna:seed')->assertExitCode(0);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/reset'));
    }

    public function test_aborts_without_seeding_when_reset_fails(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'trained' => 5]),
            '*/reset'  => Http::response(['message' => 'error'], 500),
        ]);

        $this->artisan('vanna:seed', ['--fresh' => true])->assertExitCode(1);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/train'));
    }
}
