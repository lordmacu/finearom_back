<?php

namespace Tests\Unit;

use App\Services\VannaService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VannaServiceTest extends TestCase
{
    public function test_returns_null_when_disabled(): void
    {
        Config::set('custom.vanna_enabled', false);
        Http::fake();
        $this->assertNull(app(VannaService::class)->retrieve('hola'));
        Http::assertNothingSent();
    }

    public function test_returns_null_on_error(): void
    {
        Config::set('custom.vanna_enabled', true);
        Http::fake(['*/retrieve' => Http::response('boom', 500)]);
        $this->assertNull(app(VannaService::class)->retrieve('hola'));
    }

    public function test_maps_ok_response(): void
    {
        Config::set('custom.vanna_enabled', true);
        Http::fake(['*/retrieve' => Http::response([
            'examples' => [['question' => 'q', 'sql' => 'SELECT 1']],
            'ddl' => ['CREATE TABLE x'],
            'documentation' => ['regla'],
        ])]);
        $out = app(VannaService::class)->retrieve('hola');
        $this->assertSame('SELECT 1', $out['examples'][0]['sql']);
        $this->assertSame(['CREATE TABLE x'], $out['ddl']);
    }
}
