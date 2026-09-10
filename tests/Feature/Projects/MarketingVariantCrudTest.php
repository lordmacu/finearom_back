<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class MarketingVariantCrudTest extends ProjectFieldsTestCase
{
    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto Variantes', 'fecha_creacion' => today()]);
    }

    public function test_crea_una_variante_con_nombre_claims_y_color(): void
    {
        $project = $this->project();

        $data = $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'         => 'Variante 1',
            'claims'         => 'Hidratante y sin alérgenos',
            'color_etiqueta' => '#ff0000',
        ])->assertStatus(201)->json('data');

        $this->assertSame('Variante 1', $data['nombre']);
        $this->assertSame('Hidratante y sin alérgenos', $data['claims']);
        $this->assertSame('#ff0000', $data['color_etiqueta']);
        $this->assertSame([], $data['references']);
    }

    public function test_la_variante_nace_sin_referencias(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre' => 'Variante 1',
        ])->assertStatus(201);

        $data = $this->getJson("/api/projects/{$project->id}/marketing-variants")->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame([], $data[0]['references']);
    }

    public function test_ignora_las_referencias_que_lleguen_en_el_payload(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => [['referencia' => 'Ref A', 'codigo' => 'C-1']],
        ])->assertStatus(201);

        $this->assertSame(0, DB::table('project_marketing_variant_references')->count());
    }

    public function test_actualiza_nombre_claims_y_color(): void
    {
        $project = $this->project();

        $id = $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre' => 'Variante 1', 'claims' => 'Viejo', 'color_etiqueta' => '#000000',
        ])->json('data.id');

        $data = $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}", [
            'nombre' => 'Variante 1 bis', 'claims' => 'Nuevo', 'color_etiqueta' => '#00ff7b',
        ])->assertOk()->json('data');

        $this->assertSame('Variante 1 bis', $data['nombre']);
        $this->assertSame('Nuevo', $data['claims']);
        $this->assertSame('#00ff7b', $data['color_etiqueta']);
    }

    public function test_borrar_la_variante_borra_sus_referencias(): void
    {
        $project = $this->project();

        $id = $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre' => 'Variante 1',
        ])->json('data.id');

        DB::table('project_marketing_variant_references')->insert([
            'variant_id' => $id, 'referencia' => 'Ref A', 'orden' => 0,
        ]);

        $this->deleteJson("/api/projects/{$project->id}/marketing-variants/{$id}")->assertOk();

        $this->assertSame(0, DB::table('project_marketing_variant_references')->where('variant_id', $id)->count());
        $this->assertSame(0, DB::table('project_marketing_variants')->where('id', $id)->count());
    }

    public function test_valida_el_largo_del_color(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'         => 'Variante 1',
            'color_etiqueta' => str_repeat('x', 60),
        ])->assertStatus(422)->assertJsonValidationErrors('color_etiqueta');
    }
}
