<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class MarketingVariantReferencesTest extends ProjectFieldsTestCase
{
    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto Variantes', 'fecha_creacion' => today()]);
    }

    private function referencia(array $extra = []): array
    {
        return array_merge([
            'referencia'     => 'Ref A',
            'codigo'         => 'C-1',
            'aplicacion'     => 'Jabón',
            'dosis'          => 2.5,
            'color_etiqueta' => '#000000',
            'claims'         => 'Hidratante',
        ], $extra);
    }

    public function test_crea_una_variante_con_tres_referencias(): void
    {
        $project = $this->project();

        $res = $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => [
                $this->referencia(['referencia' => 'Ref A']),
                $this->referencia(['referencia' => 'Ref B']),
                $this->referencia(['referencia' => 'Ref C']),
            ],
        ])->assertStatus(201);

        $this->assertSame('Variante 1', $res->json('data.nombre'));
        $this->assertCount(3, $res->json('data.references'));
        $this->assertSame(3, DB::table('project_marketing_variant_references')->count());
    }

    public function test_rechaza_una_cuarta_referencia(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => array_fill(0, 4, $this->referencia()),
        ])->assertStatus(422)->assertJsonValidationErrors('referencias');
    }

    public function test_exige_al_menos_una_referencia(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('referencias');
    }

    public function test_valida_la_dosis_de_cada_referencia(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => [
                $this->referencia(),
                $this->referencia(['dosis' => 234]),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('referencias.1.dosis');
    }

    public function test_actualizar_reemplaza_las_referencias(): void
    {
        $project = $this->project();

        $id = $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => [$this->referencia(['referencia' => 'Vieja']), $this->referencia()],
        ])->json('data.id');

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}", [
            'nombre'      => 'Variante 1 bis',
            'referencias' => [$this->referencia(['referencia' => 'Nueva'])],
        ])->assertOk();

        $refs = DB::table('project_marketing_variant_references')->where('variant_id', $id)->get();
        $this->assertCount(1, $refs);
        $this->assertSame('Nueva', $refs->first()->referencia);
    }

    public function test_borrar_la_variante_borra_sus_referencias(): void
    {
        $project = $this->project();

        $id = $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => [$this->referencia(), $this->referencia()],
        ])->json('data.id');

        $this->deleteJson("/api/projects/{$project->id}/marketing-variants/{$id}")->assertOk();

        $this->assertSame(0, DB::table('project_marketing_variant_references')->where('variant_id', $id)->count());
    }

    public function test_el_listado_trae_las_referencias_en_orden(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre'      => 'Variante 1',
            'referencias' => [
                $this->referencia(['referencia' => 'Primera']),
                $this->referencia(['referencia' => 'Segunda']),
            ],
        ])->assertStatus(201);

        $data = $this->getJson("/api/projects/{$project->id}/marketing-variants")->assertOk()->json('data');

        $this->assertSame(
            ['Primera', 'Segunda'],
            array_column($data[0]['references'], 'referencia')
        );
    }
}
