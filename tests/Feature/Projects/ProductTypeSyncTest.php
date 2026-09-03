<?php

namespace Tests\Feature\Projects;

use App\Support\ProductTypeCatalog;
use App\Support\ProductTypeSynchronizer;
use Illuminate\Support\Facades\DB;

class ProductTypeSyncTest extends ProjectFieldsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['Personal Care', 'personal_care'],
            ['Home Care', 'home_care'],
            ['Air Care', 'air_care'],
            ['Fine Fragrance', 'fine_fragrance'],
        ] as [$name, $slug]) {
            DB::table('product_categories')->insert(['name' => $name, 'slug' => $slug, 'active' => true]);
        }
    }

    private function legacy(string $nombre): int
    {
        return DB::table('project_product_types')->insertGetId(['nombre' => $nombre, 'categoria' => 4]);
    }

    public function test_siembra_los_73_tipos_de_la_lista(): void
    {
        (new ProductTypeSynchronizer())->run();

        $this->assertSame(73, DB::table('project_product_types')->whereNotNull('product_category_id')->count());
    }

    public function test_fusiona_el_legacy_conservando_su_id(): void
    {
        $id = $this->legacy('GEL DE DUCHA');

        (new ProductTypeSynchronizer())->run();

        $fila = DB::table('project_product_types')->find($id);
        $this->assertSame('Gel de ducha', $fila->nombre, 'El legacy debe renombrarse, no duplicarse.');
        $this->assertSame('Corporal', $fila->grupo);
        $this->assertSame(1, DB::table('project_product_types')->where('nombre', 'Gel de ducha')->count());
    }

    public function test_conserva_el_legacy_sin_equivalente_y_le_pone_categoria(): void
    {
        $id = $this->legacy('SPLASH');

        (new ProductTypeSynchronizer())->run();

        $fila = DB::table('project_product_types')->find($id);
        $this->assertSame('SPLASH', $fila->nombre);
        $this->assertNotNull($fila->product_category_id, 'Sin categoría quedaría fuera del select filtrado.');
        $this->assertEquals(1, $fila->active);
    }

    public function test_no_duplica_si_se_corre_dos_veces(): void
    {
        $this->legacy('GEL DE DUCHA');

        (new ProductTypeSynchronizer())->run();
        $primera = DB::table('project_product_types')->count();

        (new ProductTypeSynchronizer())->run();

        $this->assertSame($primera, DB::table('project_product_types')->count());
    }

    public function test_rellena_la_categoria_de_los_proyectos_desde_su_tipo(): void
    {
        $id = $this->legacy('GEL DE DUCHA');
        $projectId = DB::table('projects')->insertGetId(['nombre' => 'P', 'product_id' => $id]);

        (new ProductTypeSynchronizer())->run();

        $personalCare = DB::table('product_categories')->where('slug', 'personal_care')->value('id');
        $this->assertEquals($personalCare, DB::table('projects')->find($projectId)->product_category_id);
    }

    public function test_no_pisa_la_categoria_de_un_proyecto_que_ya_la_tiene(): void
    {
        $id = $this->legacy('GEL DE DUCHA');
        $homeCare = DB::table('product_categories')->where('slug', 'home_care')->value('id');
        $projectId = DB::table('projects')->insertGetId([
            'nombre' => 'P', 'product_id' => $id, 'product_category_id' => $homeCare,
        ]);

        (new ProductTypeSynchronizer())->run();

        $this->assertEquals($homeCare, DB::table('projects')->find($projectId)->product_category_id);
    }
}
