<?php

namespace Tests\Feature\Projects;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Los proyectos con datos de muestra de aceite quedan con "¿Requiere aceites?" en sí; el resto en no. */
class RequiereAceitesMigrationTest extends TestCase
{
    public function test_marca_si_solo_a_los_proyectos_que_ya_tienen_muestra(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->boolean('requiere_aceites')->default(false);
        });
        Schema::create('project_samples', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->decimal('cantidad', 10, 2)->nullable();
            $t->integer('cantidad_copias')->nullable();
            $t->text('observaciones')->nullable();
        });
        foreach (['Con cantidad', 'Con copias', 'Con nota', 'Muestra vacía', 'Sin muestra'] as $n) {
            DB::table('projects')->insert(['nombre' => $n]);
        }
        $id = fn ($n) => DB::table('projects')->where('nombre', $n)->value('id');
        foreach ([
            ['project_id' => $id('Con cantidad'), 'cantidad' => 30],
            ['project_id' => $id('Con copias'), 'cantidad_copias' => 2],
            ['project_id' => $id('Con nota'), 'observaciones' => 'Sellar con teflón'],
            ['project_id' => $id('Muestra vacía'), 'cantidad' => 0, 'observaciones' => ''],
        ] as $fila) {
            DB::table('project_samples')->insert($fila);
        }

        (require database_path('migrations/2026_10_20_100001_mark_requiere_aceites_with_existing_sample.php'))->up();

        $marcados = DB::table('projects')->where('requiere_aceites', true)->orderBy('nombre')->pluck('nombre')->all();
        $this->assertSame(['Con cantidad', 'Con copias', 'Con nota'], $marcados);
    }
}
