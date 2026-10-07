<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración renombra solo los requisitos de Marketing EXACTOS que ofrecía la
 * pantalla y deja intactos los valores viejos importados con otra escritura.
 */
class RenameMarketingRequisitosMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);

        Schema::create('project_marketing', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->text('marketing')->nullable();
        });
        Schema::create('time_marketing', function (Blueprint $t) {
            $t->id();
            $t->integer('grupo');
            $t->string('solicitud');
            $t->decimal('valor', 8, 2);
            $t->timestamps();
        });
    }

    private function migracion(): object
    {
        return require database_path('migrations/2026_10_06_100000_rename_marketing_requisitos.php');
    }

    public function test_renombra_solo_los_valores_exactos_y_deja_los_demas(): void
    {
        DB::table('project_marketing')->insert([
            ['project_id' => 1, 'marketing' => json_encode(['Pirámide Olfativa', 'Caja', 'Dummie Digital'], JSON_UNESCAPED_UNICODE)],
            ['project_id' => 2, 'marketing' => json_encode(['Pirámide olfativa', 'Dummie digital', 'Investigación De Mercado'], JSON_UNESCAPED_UNICODE)],
            ['project_id' => 3, 'marketing' => null],
            ['project_id' => 4, 'marketing' => json_encode(['Pirámide Olfativa'])], // escapado como lo guarda Eloquent
        ]);
        foreach (['Pirámide Olfativa', 'Dummie Digital', 'Investigación De Mercado', 'Caja'] as $s) {
            foreach ([1, 2, 3, 4] as $g) {
                DB::table('time_marketing')->insert(['solicitud' => $s, 'grupo' => $g, 'valor' => $g / 2, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $this->migracion()->up();

        $m = fn (int $p) => json_decode(DB::table('project_marketing')->where('project_id', $p)->value('marketing'), true);
        $this->assertSame(['Diagramación de pirámides', 'Caja', 'Render digital'], $m(1));
        $this->assertSame(['Pirámide olfativa', 'Dummie digital', 'Investigación De Mercado'], $m(2), 'variantes viejas y Investigación quedan igual');
        $this->assertNull(DB::table('project_marketing')->where('project_id', 3)->value('marketing'));
        $this->assertSame(['Diagramación de pirámides'], $m(4));

        $solicitudes = DB::table('time_marketing')->distinct()->pluck('solicitud')->all();
        $this->assertEqualsCanonicalizing(['Diagramación de pirámides', 'Render digital', 'Caja', 'Etiquetas para aplicación'], $solicitudes);
        $this->assertEquals(
            DB::table('time_marketing')->where('solicitud', 'Caja')->orderBy('grupo')->pluck('valor')->all(),
            DB::table('time_marketing')->where('solicitud', 'Etiquetas para aplicación')->orderBy('grupo')->pluck('valor')->all(),
            'los tiempos de la opción nueva arrancan iguales a Caja'
        );
    }

    public function test_es_idempotente_y_reversible_en_los_nombres(): void
    {
        DB::table('project_marketing')->insert(['project_id' => 1, 'marketing' => json_encode(['Pirámide Olfativa'], JSON_UNESCAPED_UNICODE)]);
        DB::table('time_marketing')->insert(['solicitud' => 'Caja', 'grupo' => 1, 'valor' => 0.5, 'created_at' => now(), 'updated_at' => now()]);

        $migracion = $this->migracion();
        $migracion->up();
        $migracion->up();
        $this->assertSame(1, DB::table('time_marketing')->where('solicitud', 'Etiquetas para aplicación')->count());

        $migracion->down();
        $this->assertSame(['Pirámide Olfativa'], json_decode(DB::table('project_marketing')->value('marketing'), true));
    }
}
