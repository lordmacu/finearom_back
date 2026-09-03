<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;

class ProductTypeCatalogTest extends ProjectFieldsTestCase
{
    private int $personalCare;
    private int $homeCare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personalCare = DB::table('product_categories')->insertGetId([
            'name' => 'Personal Care', 'slug' => 'personal_care', 'active' => true,
        ]);
        $this->homeCare = DB::table('product_categories')->insertGetId([
            'name' => 'Home Care', 'slug' => 'home_care', 'active' => true,
        ]);

        DB::table('project_product_types')->insert([
            ['nombre' => 'Jabón líquido',      'product_category_id' => $this->personalCare, 'grupo' => 'Corporal', 'active' => true],
            ['nombre' => 'Shampoo',            'product_category_id' => $this->personalCare, 'grupo' => 'Capilar',  'active' => true],
            ['nombre' => 'Detergente líquido', 'product_category_id' => $this->homeCare,     'grupo' => 'Laundry',  'active' => true],
            ['nombre' => 'Talco',              'product_category_id' => $this->personalCare, 'grupo' => 'Corporal', 'active' => false],
            ['nombre' => 'Sin categoría',      'product_category_id' => null,                'grupo' => null,       'active' => true],
        ]);
    }

    public function test_filtra_los_tipos_por_categoria(): void
    {
        $res = $this->getJson("/api/project-catalogs/product-types?category_id={$this->homeCare}")
            ->assertOk();

        $nombres = collect($res->json('data'))->pluck('nombre')->all();

        $this->assertSame(['Detergente líquido'], $nombres);
    }

    public function test_excluye_los_tipos_inactivos(): void
    {
        $res = $this->getJson("/api/project-catalogs/product-types?category_id={$this->personalCare}")
            ->assertOk();

        $nombres = collect($res->json('data'))->pluck('nombre')->all();

        $this->assertNotContains('Talco', $nombres, 'Un tipo inactivo no debe ofrecerse para proyectos nuevos.');
    }

    public function test_devuelve_el_grupo_de_cada_tipo(): void
    {
        $res = $this->getJson("/api/project-catalogs/product-types?category_id={$this->personalCare}")
            ->assertOk();

        $porNombre = collect($res->json('data'))->keyBy('nombre');

        $this->assertSame('Corporal', $porNombre['Jabón líquido']['grupo']);
        $this->assertSame('Capilar', $porNombre['Shampoo']['grupo']);
    }

    /** Sin filtro sigue devolviendo todo: la pantalla de catálogos lo necesita. */
    public function test_sin_category_id_devuelve_todos(): void
    {
        $res = $this->getJson('/api/project-catalogs/product-types')->assertOk();

        $this->assertCount(5, $res->json('data'));
    }

    public function test_crea_un_tipo_con_categoria_grupo_y_estado(): void
    {
        $this->givePermissions(['project list', 'project catalog manage']);

        $res = $this->postJson('/api/project-catalogs/product-types', [
            'nombre'              => 'Bruma capilar',
            'product_category_id' => $this->personalCare,
            'grupo'               => 'Capilar',
            'active'              => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('project_product_types', [
            'id'                  => $res->json('data.id'),
            'nombre'              => 'Bruma capilar',
            'product_category_id' => $this->personalCare,
            'grupo'               => 'Capilar',
        ]);
    }

    public function test_puede_desactivar_un_tipo_sin_borrarlo(): void
    {
        $this->givePermissions(['project list', 'project catalog manage']);

        $id = DB::table('project_product_types')
            ->where('nombre', 'Shampoo')->value('id');

        $this->putJson("/api/project-catalogs/product-types/{$id}", [
            'nombre'              => 'Shampoo',
            'product_category_id' => $this->personalCare,
            'grupo'               => 'Capilar',
            'active'              => false,
        ])->assertOk();

        $this->assertDatabaseHas('project_product_types', ['id' => $id, 'active' => false]);

        $nombres = collect(
            $this->getJson("/api/project-catalogs/product-types?category_id={$this->personalCare}")->json('data')
        )->pluck('nombre');

        $this->assertNotContains('Shampoo', $nombres);
    }
}
