<?php

namespace Tests\Feature\Projects;

use App\Models\Process;
use App\Models\ProjectCatalogItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Catálogos de diseños de etiqueta y pirámides: se administran como envases y
 * en el proyecto se eligen con el buscador.
 */
class ProjectCatalogItemsTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->givePermissions(['project list', 'project edit', 'project send creation', 'envelope type manage']);
    }

    private function crear(string $tipo, string $name, array $extra = []): int
    {
        return $this->post('/api/admin/project-catalog-items', array_merge(['tipo' => $tipo, 'name' => $name], $extra), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
    }

    public function test_administrar_items_con_foto_por_tipo(): void
    {
        $id = $this->crear('etiqueta', 'Etiqueta minimal', ['category' => 'Premium', 'photo' => UploadedFile::fake()->image('e.png')]);
        $this->crear('piramide', 'Pirámide cítrica');

        $this->getJson('/api/admin/project-catalog-items/etiqueta')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Etiqueta minimal');
        Storage::disk('local')->assertExists(ProjectCatalogItem::find($id)->photo_path);
        $this->get("/api/project-catalog-items/item/{$id}/photo")->assertOk();

        $this->putJson("/api/admin/project-catalog-items/{$id}", ['name' => 'Etiqueta minimalista'])->assertOk();
        $this->assertSame('Etiqueta minimalista', ProjectCatalogItem::find($id)->name);

        $foto = ProjectCatalogItem::find($id)->photo_path;
        $this->deleteJson("/api/admin/project-catalog-items/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($foto);
    }

    public function test_sin_permiso_de_envases_no_se_administra(): void
    {
        $this->givePermissions(['project list']);
        $this->postJson('/api/admin/project-catalog-items', ['tipo' => 'etiqueta', 'name' => 'X'])->assertForbidden();
    }

    public function test_el_buscador_del_proyecto_trae_solo_activos_del_tipo(): void
    {
        $this->crear('etiqueta', 'Kraft natural', ['category' => 'Eco']);
        $this->crear('etiqueta', 'Kraft oculta', ['active' => 0]);
        $this->crear('piramide', 'Kraft pirámide');

        $this->getJson('/api/project-catalog-items/etiqueta?search=kraft')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kraft natural');
        $this->getJson('/api/project-catalog-items/etiqueta?search=eco')->assertJsonCount(1, 'data');
    }

    public function test_elegir_items_en_el_proyecto_no_toca_el_otro_catalogo_y_va_en_el_correo(): void
    {
        $project = $this->project();
        $e1 = $this->crear('etiqueta', 'Etiqueta A');
        $e2 = $this->crear('etiqueta', 'Etiqueta B');
        $p1 = $this->crear('piramide', 'Pirámide floral');

        $this->putJson("/api/projects/{$project->id}/catalog-items/piramide", ['item_ids' => [$p1]])->assertOk();
        // Un id de otro catálogo se ignora
        $this->putJson("/api/projects/{$project->id}/catalog-items/etiqueta", ['item_ids' => [$e1, $e2, $p1]])
            ->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/projects/{$project->id}/catalog-items/etiqueta", ['item_ids' => [$e2]])->assertOk();

        $this->assertEqualsCanonicalizing([$e2, $p1], $project->catalogItems()->pluck('project_catalog_items.id')->all());

        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);
        $this->template('project_created', 'Nuevo proyecto #|project_id|');
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Etiqueta B', $html);
        $this->assertStringContainsString('Pirámide floral', $html);
        $this->assertStringNotContainsString('Etiqueta A', $html);
    }
}
