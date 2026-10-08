<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración crea las carpetas virtuales y pasa los envases (y su selección en
 * los proyectos) a `project_catalog_items` con tipo 'envase', conservando la
 * ruta de la foto, que sigue en disco.
 */
class UnifyEnvelopeTypesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);

        Schema::create('projects', function (Blueprint $t) {
            $t->id();
        });
        Schema::create('envelope_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('category', 100)->nullable();
            $t->string('photo_path')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('project_envelope_type', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('envelope_type_id');
            $t->timestamps();
        });
        Schema::create('project_catalog_items', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 20);
            $t->string('name', 100);
            $t->string('category', 100)->nullable();
            $t->string('photo_path')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('project_catalog_item_project', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('catalog_item_id');
            $t->timestamps();
            $t->unique(['project_id', 'catalog_item_id']);
        });
    }

    public function test_pasa_los_envases_y_su_seleccion_al_catalogo_unificado(): void
    {
        DB::table('projects')->insert([['id' => 1], ['id' => 2]]);
        DB::table('project_catalog_items')->insert(['tipo' => 'etiqueta', 'name' => 'Kraft', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('envelope_types')->insert([
            ['id' => 7, 'name' => 'Frasco 30ml', 'category' => 'Vidrio', 'photo_path' => 'envelope-photos/a.png', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'name' => 'Caja', 'category' => null, 'photo_path' => null, 'active' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('project_envelope_type')->insert([
            ['project_id' => 1, 'envelope_type_id' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => 2, 'envelope_type_id' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require database_path('migrations/2026_10_08_100000_create_project_catalog_folders_and_unify_envelope_types.php'))->up();

        $frasco = DB::table('project_catalog_items')->where('tipo', 'envase')->where('name', 'Frasco 30ml')->first();
        $caja   = DB::table('project_catalog_items')->where('tipo', 'envase')->where('name', 'Caja')->first();
        $this->assertSame('envelope-photos/a.png', $frasco->photo_path);
        $this->assertNull($frasco->folder_id);
        $this->assertEquals(0, $caja->active);
        $this->assertSame(1, DB::table('project_catalog_items')->where('tipo', 'etiqueta')->count());

        $this->assertSame([$frasco->id], DB::table('project_catalog_item_project')->where('project_id', 1)->pluck('catalog_item_id')->all());
        $this->assertSame([$caja->id], DB::table('project_catalog_item_project')->where('project_id', 2)->pluck('catalog_item_id')->all());
        $this->assertTrue(Schema::hasTable('project_catalog_folders'));
    }
}
