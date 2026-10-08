<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectCatalogFolder;
use App\Models\ProjectCatalogItem;
use Illuminate\Support\Facades\Storage;

/**
 * Carpetas virtuales (solo en base de datos) de los catálogos de envases,
 * diseños de etiqueta y pirámides.
 */
class ProjectCatalogFoldersTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->givePermissions(['project list', 'project edit', 'project send creation', 'envelope type manage']);
    }

    private function carpeta(string $tipo, string $name, ?int $padre = null): int
    {
        return $this->postJson('/api/admin/project-catalog-folders', ['tipo' => $tipo, 'name' => $name, 'parent_id' => $padre])
            ->assertCreated()->json('data.id');
    }

    private function item(string $tipo, string $name, ?int $folder = null, array $extra = []): int
    {
        return $this->postJson('/api/admin/project-catalog-items', array_merge(['tipo' => $tipo, 'name' => $name, 'folder_id' => $folder], $extra))
            ->assertCreated()->json('data.id');
    }

    public function test_carpetas_anidadas_y_navegacion_con_migas_de_pan(): void
    {
        $raiz = $this->carpeta('etiqueta', 'Premium');
        $hija = $this->carpeta('etiqueta', 'Minimal', $raiz);
        $this->item('etiqueta', 'Suelta');
        $this->item('etiqueta', 'En minimal', $hija);

        $this->getJson('/api/admin/project-catalog-items/etiqueta/browse')
            ->assertOk()
            ->assertJsonCount(1, 'folders')->assertJsonPath('folders.0.name', 'Premium')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Suelta')
            ->assertJsonPath('breadcrumb', []);

        $this->getJson("/api/admin/project-catalog-items/etiqueta/browse?folder_id={$hija}")
            ->assertOk()
            ->assertJsonCount(0, 'folders')
            ->assertJsonPath('data.0.name', 'En minimal')
            ->assertJsonPath('breadcrumb.0.name', 'Premium')
            ->assertJsonPath('breadcrumb.1.name', 'Minimal');
    }

    public function test_lista_plana_de_carpetas_con_su_ruta(): void
    {
        $a = $this->carpeta('envase', 'Vidrio');
        $this->carpeta('envase', 'Frascos', $a);
        $this->carpeta('etiqueta', 'Otra');

        $this->getJson('/api/admin/project-catalog-folders/envase')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.path', 'Vidrio')
            ->assertJsonPath('data.1.path', 'Vidrio / Frascos');
    }

    public function test_las_carpetas_no_se_mezclan_entre_catalogos(): void
    {
        $envases = $this->carpeta('envase', 'Vidrio');
        $this->carpeta('piramide', 'Cítricas');

        $this->getJson('/api/admin/project-catalog-items/piramide/browse')->assertJsonCount(1, 'folders')->assertJsonPath('folders.0.name', 'Cítricas');
        $this->getJson("/api/admin/project-catalog-items/piramide/browse?folder_id={$envases}")->assertNotFound();
        $this->postJson('/api/admin/project-catalog-folders', ['tipo' => 'piramide', 'name' => 'X', 'parent_id' => $envases])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/admin/project-catalog-items', ['tipo' => 'piramide', 'name' => 'Y', 'folder_id' => $envases])
            ->assertStatus(422)->assertJsonValidationErrors('folder_id');
    }

    public function test_mover_carpeta_e_impedir_ciclos(): void
    {
        $a = $this->carpeta('envase', 'A');
        $b = $this->carpeta('envase', 'B', $a);
        $c = $this->carpeta('envase', 'C', $b);

        $this->putJson("/api/admin/project-catalog-folders/{$a}", ['name' => 'A', 'parent_id' => $a])->assertStatus(422);
        $this->putJson("/api/admin/project-catalog-folders/{$a}", ['name' => 'A', 'parent_id' => $c])->assertStatus(422);

        $this->putJson("/api/admin/project-catalog-folders/{$c}", ['name' => 'C renombrada', 'parent_id' => $a])->assertOk();
        $this->assertSame($a, ProjectCatalogFolder::find($c)->parent_id);
        $this->assertSame('C renombrada', ProjectCatalogFolder::find($c)->name);
    }

    public function test_mover_un_item_a_otra_carpeta(): void
    {
        $carpeta = $this->carpeta('envase', 'Vidrio');
        $id = $this->item('envase', 'Frasco 30ml');

        $this->putJson("/api/admin/project-catalog-items/{$id}", ['name' => 'Frasco 30ml', 'folder_id' => $carpeta])->assertOk();
        $this->assertSame($carpeta, ProjectCatalogItem::find($id)->folder_id);

        $this->putJson("/api/admin/project-catalog-items/{$id}", ['name' => 'Frasco 30ml', 'folder_id' => null])->assertOk();
        $this->assertNull(ProjectCatalogItem::find($id)->folder_id);
    }

    public function test_solo_se_elimina_una_carpeta_vacia(): void
    {
        $padre = $this->carpeta('etiqueta', 'Padre');
        $hija  = $this->carpeta('etiqueta', 'Hija', $padre);
        $this->item('etiqueta', 'Dentro', $hija);

        $this->deleteJson("/api/admin/project-catalog-folders/{$padre}")->assertStatus(422);
        $this->deleteJson("/api/admin/project-catalog-folders/{$hija}")->assertStatus(422);

        ProjectCatalogItem::query()->delete();
        $this->deleteJson("/api/admin/project-catalog-folders/{$hija}")->assertOk();
        $this->deleteJson("/api/admin/project-catalog-folders/{$padre}")->assertOk();
        $this->assertSame(0, ProjectCatalogFolder::count());
    }

    public function test_el_proyecto_navega_las_carpetas_y_solo_ve_activos(): void
    {
        $carpeta = $this->carpeta('piramide', 'Cítricas');
        $this->item('piramide', 'Limón', $carpeta);
        $this->item('piramide', 'Oculta', $carpeta, ['active' => 0]);

        $this->getJson("/api/project-catalog-items/piramide/browse?folder_id={$carpeta}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Limón');

        // Buscando se mira todo el catálogo, sin importar la carpeta
        $this->getJson('/api/project-catalog-items/piramide/browse?search=lim')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(0, 'folders');
    }

    public function test_sin_permiso_no_se_administran_carpetas(): void
    {
        $this->givePermissions(['project list']);
        $this->postJson('/api/admin/project-catalog-folders', ['tipo' => 'envase', 'name' => 'X'])->assertForbidden();
    }

    public function test_elegir_envases_en_el_proyecto_no_toca_etiquetas_ni_piramides(): void
    {
        $project  = $this->project();
        $envase   = $this->item('envase', 'Frasco');
        $etiqueta = $this->item('etiqueta', 'Kraft');

        $this->putJson("/api/projects/{$project->id}/catalog-items/etiqueta", ['item_ids' => [$etiqueta]])->assertOk();
        $this->putJson("/api/projects/{$project->id}", ['nombre' => $project->nombre, 'envelope_type_ids' => [$envase]])->assertOk();

        $this->assertSame([$envase], $project->envelopeTypes()->pluck('project_catalog_items.id')->all());
        $this->assertEqualsCanonicalizing([$envase, $etiqueta], $project->catalogItems()->pluck('project_catalog_items.id')->all());

        $this->putJson("/api/projects/{$project->id}", ['nombre' => $project->nombre, 'envelope_type_ids' => []])->assertOk();
        $this->assertSame([$etiqueta], $project->catalogItems()->pluck('project_catalog_items.id')->all());
    }
}
