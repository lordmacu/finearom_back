<?php

namespace Tests\Feature\Projects;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Los proyectos que ya existían quedan con "¿Requiere aplicaciones?" en sí; los nuevos arrancan en no. */
class RequiereAplicacionesMigrationTest extends TestCase
{
    public function test_marca_si_a_los_existentes_y_los_nuevos_arrancan_en_no(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->boolean('requiere_piramides')->default(false);
        });
        DB::table('projects')->insert([['nombre' => 'Viejo 1'], ['nombre' => 'Viejo 2']]);

        (require database_path('migrations/2026_10_16_100000_add_requiere_aplicaciones_to_projects_table.php'))->up();

        $this->assertSame(2, DB::table('projects')->where('requiere_aplicaciones', true)->count());

        DB::table('projects')->insert(['nombre' => 'Nuevo']);
        $this->assertEquals(0, DB::table('projects')->where('nombre', 'Nuevo')->value('requiere_aplicaciones'));
    }
}
