<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectMarketing;
use Illuminate\Http\UploadedFile;

class MarketingFormTest extends ProjectFieldsTestCase
{
    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto Mkt', 'fecha_creacion' => today()]);
    }

    public function test_guarda_calidad_como_array(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/marketing", [
            'calidad' => ['MSDS', 'Certificados Alergenos'],
        ])->assertOk();

        $this->assertSame(
            ['MSDS', 'Certificados Alergenos'],
            ProjectMarketing::where('project_id', $project->id)->first()->calidad
        );
    }

    /**
     * Los arrays de archivos los administra el controller de uploads, no el
     * formulario: aunque el form mande una copia vieja, no debe pisarlos.
     */
    public function test_guardar_el_formulario_no_pisa_los_archivos_subidos(): void
    {
        $project = $this->project();

        // El usuario abre el tab: el form arranca con los arrays vacíos.
        $formEnMemoria = [
            'marca'                => 'Marca A',
            'benchmark_examples'   => [],
            'catalog_etiquetas'    => [],
            'catalog_piramides'    => [],
            'lista_presentaciones' => [],
        ];

        // Sube un archivo.
        $this->post("/api/projects/{$project->id}/marketing-upload", [
            'file'  => UploadedFile::fake()->image('etiqueta.png'),
            'field' => 'catalog_etiquetas',
        ])->assertStatus(201);

        // Guarda el formulario con la copia vieja.
        $this->putJson("/api/projects/{$project->id}/marketing", $formEnMemoria)->assertOk();

        $marketing = ProjectMarketing::where('project_id', $project->id)->first();
        $this->assertCount(
            1,
            $marketing->catalog_etiquetas ?? [],
            'Guardar el formulario borró del array el archivo recién subido (el archivo queda huérfano en disco).'
        );
    }

    public function test_crea_lista_y_borra_variantes_de_marketing(): void
    {
        $project = $this->project();

        $create = $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'referencia' => 'REF-1',
            'codigo'     => 'C-1',
            'dosis'      => 1.5,
        ])->assertStatus(201);

        $variantId = $create->json('data.id');

        $this->getJson("/api/projects/{$project->id}/marketing-variants")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$variantId}", [
            'referencia' => 'REF-2',
        ])->assertOk()->assertJsonPath('data.referencia', 'REF-2');

        $this->deleteJson("/api/projects/{$project->id}/marketing-variants/{$variantId}")->assertOk();

        $this->getJson("/api/projects/{$project->id}/marketing-variants")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_no_deja_tocar_una_variante_de_otro_proyecto(): void
    {
        $a = $this->project();
        $b = $this->project();

        $variantId = $this->postJson("/api/projects/{$a->id}/marketing-variants", [
            'referencia' => 'REF-A',
        ])->json('data.id');

        $this->putJson("/api/projects/{$b->id}/marketing-variants/{$variantId}", [
            'referencia' => 'HACK',
        ])->assertStatus(404);
    }

    public function test_el_proyecto_expone_las_relaciones_nuevas(): void
    {
        $project = $this->project();
        $this->postJson("/api/projects/{$project->id}/marketing-variants", ['referencia' => 'REF-1']);

        $project->load(['marketingVariants', 'envelopeType']);

        $this->assertCount(1, $project->marketingVariants);
        $this->assertTrue($project->relationLoaded('envelopeType'));
    }
}
