<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Support\MarketingVariantPermissions;
use Illuminate\Support\Facades\DB;

class MarketingVariantReferencesTest extends ProjectFieldsTestCase
{
    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto Referencias', 'fecha_creacion' => today()]);
    }

    private function variante(Project $project, string $nombre = 'Variante 1'): int
    {
        return $this->postJson("/api/projects/{$project->id}/marketing-variants", [
            'nombre' => $nombre, 'claims' => 'Hidratante', 'color_etiqueta' => '#ff0000',
        ])->json('data.id');
    }

    private function referencia(array $extra = []): array
    {
        return array_merge([
            'referencia' => 'Ref A',
            'codigo'     => 'C-1',
            'aplicacion' => 'Jabón',
            'dosis'      => 2.5,
        ], $extra);
    }

    public function test_sincroniza_tres_referencias(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $data = $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [
                $this->referencia(['referencia' => 'Ref A']),
                $this->referencia(['referencia' => 'Ref B']),
                $this->referencia(['referencia' => 'Ref C']),
            ],
        ])->assertOk()->json('data');

        $this->assertSame(['Ref A', 'Ref B', 'Ref C'], array_column($data['references'], 'referencia'));
        $this->assertSame(3, DB::table('project_marketing_variant_references')->count());
    }

    public function test_reemplaza_las_referencias_anteriores(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia(['referencia' => 'Vieja']), $this->referencia()],
        ])->assertOk();

        $data = $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia(['referencia' => 'Nueva'])],
        ])->assertOk()->json('data');

        $this->assertCount(1, $data['references']);
        $this->assertSame('Nueva', $data['references'][0]['referencia']);
    }

    public function test_acepta_un_array_vacio_y_deja_la_variante_viva(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia()],
        ])->assertOk();

        $data = $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [],
        ])->assertOk()->json('data');

        $this->assertSame([], $data['references']);
        $this->assertSame('Variante 1', $data['nombre']);
    }

    public function test_no_toca_el_nombre_claims_ni_color_de_la_variante(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $data = $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'nombre'      => 'PISADO',
            'claims'      => 'PISADOS',
            'referencias' => [$this->referencia()],
        ])->assertOk()->json('data');

        $this->assertSame('Variante 1', $data['nombre']);
        $this->assertSame('Hidratante', $data['claims']);
        $this->assertSame('#ff0000', $data['color_etiqueta']);
    }

    public function test_rechaza_una_cuarta_referencia(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => array_fill(0, 4, $this->referencia()),
        ])->assertStatus(422)->assertJsonValidationErrors('referencias');
    }

    public function test_valida_la_dosis_de_cada_referencia(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia(), $this->referencia(['dosis' => 234])],
        ])->assertStatus(422)->assertJsonValidationErrors('referencias.1.dosis');
    }

    public function test_rechaza_una_referencia_que_no_es_un_array(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => ['hola'],
        ])->assertStatus(422)->assertJsonValidationErrors('referencias.0');
    }

    public function test_no_sincroniza_referencias_de_otro_proyecto(): void
    {
        $project = $this->project();
        $otro    = Project::create(['nombre' => 'Otro', 'fecha_creacion' => today()]);
        $id      = $this->variante($project);

        $this->putJson("/api/projects/{$otro->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia()],
        ])->assertStatus(404);
    }

    public function test_comercial_no_puede_sincronizar_referencias(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->givePermissions(['project list', MarketingVariantPermissions::COMMERCIAL]);

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia()],
        ])->assertStatus(403);
    }

    public function test_marketing_no_puede_sincronizar_referencias(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->givePermissions(['project list']);

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia()],
        ])->assertStatus(403);
    }

    public function test_desarrollo_si_puede_sincronizar_referencias(): void
    {
        $project = $this->project();
        $id      = $this->variante($project);

        $this->givePermissions(['project list', MarketingVariantPermissions::TECHNICAL]);

        $data = $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}/references", [
            'referencias' => [$this->referencia(['referencia' => 'De Desarrollo'])],
        ])->assertOk()->json('data');

        $this->assertSame('De Desarrollo', $data['references'][0]['referencia']);
    }
}
