<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectMarketingVariant;
use App\Models\ProjectMarketingVariantReference;
use App\Models\ProjectStatusHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ProjectPotentialTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('prospects', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->timestamps();
        });

        $this->givePermissions(['project potential list', 'project potential edit']);
    }

    private function projectConReferencia(string $ejecutivo = 'María Ortega', array $attrs = []): ProjectMarketingVariantReference
    {
        $project = Project::create(array_merge([
            'nombre'             => 'Proyecto Potencial',
            'tipo'               => 'Desarrollo',
            'nombre_prospecto'   => 'Prospecto P',
            'ejecutivo'          => $ejecutivo,
            'estado_externo'     => 'En espera',
            'fecha_creacion'     => today(),
            'potencial_anual_kg' => 500,
            'potencial_anual_usd' => 9000,
        ], $attrs));

        $variant = ProjectMarketingVariant::create(['project_id' => $project->id, 'nombre' => 'Var 1']);

        return ProjectMarketingVariantReference::create([
            'variant_id' => $variant->id,
            'referencia' => 'Ref A',
            'codigo'     => 'C-1',
            'precio'     => 12.5,
        ]);
    }

    public function test_lista_solo_los_proyectos_de_la_ejecutiva_con_sus_referencias(): void
    {
        $this->projectConReferencia('María Ortega');
        $this->projectConReferencia('Otra Ejecutiva');

        $res = $this->getJson('/api/project-potential?ejecutivo=' . urlencode('María Ortega'))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Desarrollo', $res->json('data.0.tipo'));
        $this->assertSame('Prospecto P', $res->json('data.0.cliente'));
        $this->assertEquals(500, $res->json('data.0.potencial_anual_kg'));
        $this->assertSame('Ref A', $res->json('data.0.referencias.0.referencia'));
        $this->assertSame('C-1', $res->json('data.0.referencias.0.codigo'));
        $this->assertEquals(12.5, $res->json('data.0.referencias.0.precio'));
        $this->assertEquals(500, $res->json('meta.total_potencial_kg'));
        $this->assertEquals(9000, $res->json('data.0.potencial_anual_usd'));
        $this->assertEquals(9000, $res->json('meta.total_potencial_usd'));
    }

    public function test_muestra_el_origen_proactivo_reactivo_u_homologacion(): void
    {
        $this->projectConReferencia('María Ortega', ['nombre' => 'P1', 'proactivo' => true]);
        $this->projectConReferencia('María Ortega', ['nombre' => 'P2', 'proactivo' => false]);
        $this->projectConReferencia('María Ortega', ['nombre' => 'P3', 'proactivo' => true, 'homologacion' => true]);

        $origenes = collect($this->getJson('/api/project-potential?ejecutivo=' . urlencode('María Ortega'))->json('data'))
            ->pluck('origen', 'nombre');

        $this->assertSame(['P1' => 'proactivo', 'P2' => 'reactivo', 'P3' => 'homologacion'], $origenes->sortKeys()->all());
    }

    public function test_edita_el_potencial_en_usd_sin_tocar_los_kg(): void
    {
        $project = $this->projectConReferencia()->variant->project;

        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_usd' => 12345.67])
            ->assertOk()
            ->assertJsonPath('data.potencial_anual_usd', 12345.67)
            ->assertJsonPath('data.potencial_anual_kg', 500);

        $this->assertEquals(12345.67, $project->fresh()->potencial_anual_usd);
        $this->assertStringContainsString('Potencial anual (USD)', ProjectStatusHistory::first()->descripcion);
    }

    public function test_exige_al_menos_un_potencial(): void
    {
        $project = $this->projectConReferencia()->variant->project;

        $this->patchJson("/api/project-potential/projects/{$project->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['potencial_anual_usd', 'potencial_anual_kg']);
    }

    public function test_filtra_por_estado_externo(): void
    {
        $this->projectConReferencia('María Ortega');
        $this->projectConReferencia('María Ortega', ['estado_externo' => 'Ganado']);

        $res = $this->getJson('/api/project-potential?ejecutivo=' . urlencode('María Ortega') . '&estado_externo=Ganado')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Ganado', $res->json('data.0.estado_externo'));
    }

    public function test_exige_la_ejecutiva(): void
    {
        $this->getJson('/api/project-potential')->assertStatus(422)->assertJsonValidationErrors('ejecutivo');
    }

    public function test_lista_las_ejecutivas(): void
    {
        $this->projectConReferencia('María Ortega');
        $this->projectConReferencia('Ana Pérez');

        $this->getJson('/api/project-potential/ejecutivas')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['Ana Pérez', 'María Ortega']]);
    }

    public function test_edita_el_potencial_real_del_proyecto_y_lo_registra_en_el_historial(): void
    {
        $project = $this->projectConReferencia()->variant->project;

        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_kg' => 750.5])->assertOk();

        $this->assertEquals(750.5, $project->fresh()->potencial_anual_kg);
        $this->assertStringContainsString('Potencial anual (Kg)', ProjectStatusHistory::first()->descripcion);
    }

    public function test_guardar_el_mismo_valor_no_ensucia_el_historial(): void
    {
        $ref = $this->projectConReferencia();

        $this->patchJson("/api/project-potential/projects/{$ref->variant->project_id}", ['potencial_anual_kg' => 500])->assertOk();

        $this->assertSame(0, ProjectStatusHistory::count());
    }

    public function test_sin_permiso_de_edicion_solo_puede_consultar(): void
    {
        $ref = $this->projectConReferencia();
        $this->givePermissions(['project potential list']);

        $this->getJson('/api/project-potential?ejecutivo=' . urlencode('María Ortega'))->assertOk();
        $this->patchJson("/api/project-potential/projects/{$ref->variant->project_id}", ['potencial_anual_kg' => 1])->assertForbidden();
    }

    public function test_el_precio_de_las_referencias_no_se_edita_desde_el_modulo(): void
    {
        $ref = $this->projectConReferencia();

        $this->patchJson("/api/project-potential/references/{$ref->id}", ['precio' => 30])->assertNotFound();
        $this->assertEquals(12.5, $ref->fresh()->precio);
    }

    public function test_sin_permiso_no_puede_consultar(): void
    {
        $this->givePermissions(['project list']);

        $this->getJson('/api/project-potential?ejecutivo=X')->assertForbidden();
    }
}
