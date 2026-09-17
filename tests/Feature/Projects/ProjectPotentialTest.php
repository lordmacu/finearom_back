<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectMarketingVariant;
use App\Models\ProjectMarketingVariantReference;
use App\Models\ProjectPotentialReference;
use App\Models\ProjectStatusHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

    /** Proyecto con una variante y referencias [nombre => precio]. */
    private function proyecto(string $ejecutivo = 'María Ortega', array $attrs = [], array $refs = ['Ref A' => 12.5, 'Ref B' => 20]): Project
    {
        $project = Project::create(array_merge([
            'nombre'           => 'Proyecto Potencial',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto P',
            'ejecutivo'        => $ejecutivo,
            'estado_externo'   => 'En espera',
            'fecha_creacion'   => today(),
        ], $attrs));

        $variant = ProjectMarketingVariant::create(['project_id' => $project->id, 'nombre' => 'Var 1']);
        $i = 0;
        foreach ($refs as $nombre => $precio) {
            ProjectMarketingVariantReference::create([
                'variant_id' => $variant->id,
                'referencia' => $nombre,
                'codigo'     => 'C-' . ++$i,
                'precio'     => $precio,
                'orden'      => $i,
            ]);
        }

        return $project;
    }

    private function refId(Project $project, string $nombre): int
    {
        return (int) DB::table('project_marketing_variant_references')->where('referencia', $nombre)
            ->whereIn('variant_id', DB::table('project_marketing_variants')->where('project_id', $project->id)->pluck('id'))
            ->value('id');
    }

    private function seleccion(int $referenceId, array $extra = []): array
    {
        return array_merge([
            'reference_id'          => $referenceId,
            'kg_anio'               => 100,
            'fecha_primer_despacho' => '2026-11-01',
            'venta_anio_usd'        => 500,
            'frecuencia_compra'     => 'trimestral',
            'seguimiento'           => 'Panel consumidor',
            'estado'                => 'abierto',
            'probabilidad'          => 'alta',
        ], $extra);
    }

    private function guardar(Project $project, array $selecciones)
    {
        return $this->putJson("/api/project-potential/projects/{$project->id}/selections", ['selecciones' => $selecciones]);
    }

    public function test_lista_los_proyectos_de_la_ejecutiva_y_marca_las_referencias_seleccionadas(): void
    {
        $project = $this->proyecto();
        $this->proyecto('Otra Ejecutiva');
        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref B'))])->assertOk();

        $res = $this->getJson('/api/project-potential?ejecutivo=' . urlencode('María Ortega'))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $refs = collect($res->json('data.0.referencias'))->keyBy('referencia');
        $this->assertFalse($refs['Ref A']['seleccionada']);
        $this->assertTrue($refs['Ref B']['seleccionada']);
        $this->assertSame('C-2', $refs['Ref B']['codigo']);
        $this->assertEquals(20, $refs['Ref B']['precio']);
        $this->assertSame(1, $res->json('meta.total_seleccionadas'));
        $this->assertEquals(2000, $res->json('meta.total_potencial_usd'));
        $this->assertEquals(100, $res->json('meta.total_potencial_kg'));
    }

    public function test_el_listado_trae_el_plan_de_despachos_del_anio_y_los_totales_por_mes(): void
    {
        $project = $this->proyecto();
        // Ref B: 1200 Kg/año × 20 USD, trimestral desde junio 2026
        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref B'), [
            'kg_anio' => 1200, 'frecuencia_compra' => 'trimestral', 'fecha_primer_despacho' => '2026-06-01',
        ])])->assertOk();

        $res = $this->getJson('/api/project-potential?anio=2026&ejecutivo=' . urlencode('María Ortega'))->assertOk();

        $refs = collect($res->json('data.0.referencias'))->keyBy('referencia');
        $this->assertNull($refs['Ref A']['plan']);
        $this->assertSame(3, $refs['Ref B']['plan']['despachos']);
        $this->assertEquals(300, $refs['Ref B']['plan']['meses'][5]['kg']);
        $this->assertEquals(18000, $refs['Ref B']['plan']['total_usd']);
        $this->assertSame(2026, $res->json('meta.anio'));
        $this->assertEquals(900, $res->json('meta.total_anio_kg'));
        $this->assertEquals(18000, $res->json('meta.total_anio_usd'));
        $this->assertEquals(6000, $res->json('meta.meses.8.usd'));
        $this->assertEquals(0, $res->json('meta.meses.0.kg'));

        $siguiente = $this->getJson('/api/project-potential?anio=2027&ejecutivo=' . urlencode('María Ortega'))->assertOk();
        $this->assertEquals(1200, $siguiente->json('meta.total_anio_kg'));
    }

    public function test_el_detalle_calcula_el_plan_para_el_anio_pedido(): void
    {
        $project = $this->proyecto();
        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref A'), [
            'kg_anio' => 600, 'frecuencia_compra' => 'semestral', 'fecha_primer_despacho' => '2026-09-01',
        ])])->assertOk();

        $res = $this->getJson("/api/project-potential/projects/{$project->id}?anio=2027")->assertOk();

        $this->assertSame(2027, $res->json('data.anio'));
        $plan = collect($res->json('data.referencias'))->firstWhere('referencia', 'Ref A')['plan'];
        $this->assertEquals([3 => 300, 9 => 300], collect($plan['meses'])->filter(fn ($m) => $m['kg'] > 0)->pluck('kg', 'mes')->all());
    }

    public function test_filtra_por_estado_externo(): void
    {
        $this->proyecto();
        $this->proyecto('María Ortega', ['estado_externo' => 'Ganado']);

        $res = $this->getJson('/api/project-potential?ejecutivo=' . urlencode('María Ortega') . '&estado_externo=Ganado')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Ganado', $res->json('data.0.estado_externo'));
    }

    public function test_muestra_el_origen_proactivo_reactivo_u_homologacion(): void
    {
        $this->proyecto('María Ortega', ['nombre' => 'P1', 'proactivo' => true]);
        $this->proyecto('María Ortega', ['nombre' => 'P2', 'proactivo' => false]);
        $this->proyecto('María Ortega', ['nombre' => 'P3', 'proactivo' => true, 'homologacion' => true]);

        $origenes = collect($this->getJson('/api/project-potential?ejecutivo=' . urlencode('María Ortega'))->json('data'))
            ->pluck('origen', 'nombre');

        $this->assertSame(['P1' => 'proactivo', 'P2' => 'reactivo', 'P3' => 'homologacion'], $origenes->sortKeys()->all());
    }

    public function test_exige_la_ejecutiva(): void
    {
        $this->getJson('/api/project-potential')->assertStatus(422)->assertJsonValidationErrors('ejecutivo');
    }

    public function test_lista_las_ejecutivas(): void
    {
        $this->proyecto('María Ortega');
        $this->proyecto('Ana Pérez');

        $this->getJson('/api/project-potential/ejecutivas')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['Ana Pérez', 'María Ortega']]);
    }

    public function test_el_detalle_trae_todas_las_referencias_y_el_segmento(): void
    {
        $categoria = DB::table('product_categories')->insertGetId(['name' => 'Home Care', 'slug' => 'home-care']);
        $project   = $this->proyecto('María Ortega', ['product_category_id' => $categoria]);

        $res = $this->getJson("/api/project-potential/projects/{$project->id}")->assertOk();

        $this->assertSame('Home Care', $res->json('data.segmento'));
        $this->assertCount(2, $res->json('data.referencias'));
        $this->assertNull($res->json('data.referencias.0.potencial'));
    }

    public function test_guarda_las_seleccionadas_con_su_potencial_y_recalcula_el_del_proyecto(): void
    {
        $project = $this->proyecto();

        $res = $this->guardar($project, [
            $this->seleccion($this->refId($project, 'Ref A'), ['kg_anio' => 1000]),   // 1000 × 12,5
            $this->seleccion($this->refId($project, 'Ref B'), ['kg_anio' => 200, 'estado' => 'ganado']), // 200 × 20
        ])->assertOk();

        $refs = collect($res->json('data.referencias'))->keyBy('referencia');
        $this->assertEquals(12500, $refs['Ref A']['potencial']['potencial_anual_usd']);
        $this->assertSame('trimestral', $refs['Ref A']['potencial']['frecuencia_compra']);
        $this->assertSame('2026-11-01', $refs['Ref A']['potencial']['fecha_primer_despacho']);
        $this->assertSame('ganado', $refs['Ref B']['potencial']['estado']);

        $project->refresh();
        $this->assertEquals(1200, $project->potencial_anual_kg);
        $this->assertEquals(16500, $project->potencial_anual_usd);
        $this->assertStringContainsString('2 referencia(s) seleccionada(s)', ProjectStatusHistory::first()->descripcion);
        // El estado de la referencia no toca el del proyecto
        $this->assertSame('En espera', $project->estado_externo);
    }

    public function test_deseleccionar_borra_los_datos_y_sin_seleccion_el_potencial_queda_vacio(): void
    {
        $project = $this->proyecto();
        $a = $this->refId($project, 'Ref A');
        $b = $this->refId($project, 'Ref B');
        $this->guardar($project, [$this->seleccion($a), $this->seleccion($b)])->assertOk();

        $this->guardar($project, [$this->seleccion($b, ['kg_anio' => 50])])->assertOk();
        $this->assertSame([$b], ProjectPotentialReference::pluck('reference_id')->all());
        $this->assertEquals(50, $project->fresh()->potencial_anual_kg);

        $this->guardar($project, [])->assertOk();
        $this->assertSame(0, ProjectPotentialReference::count());
        $this->assertNull($project->fresh()->potencial_anual_kg);
        $this->assertNull($project->fresh()->potencial_anual_usd);
    }

    public function test_rechaza_referencias_de_otro_proyecto_y_valores_invalidos(): void
    {
        $project = $this->proyecto();
        $otro    = $this->proyecto('Otra', ['nombre' => 'Otro'], ['Ajena' => 5]);

        $this->guardar($project, [$this->seleccion($this->refId($otro, 'Ajena'))])
            ->assertStatus(422)->assertJsonValidationErrors('selecciones');

        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref A'), [
            'kg_anio' => -1, 'estado' => 'x', 'probabilidad' => 'segura', 'frecuencia_compra' => 'diaria',
        ])])->assertStatus(422)->assertJsonValidationErrors([
            'selecciones.0.kg_anio', 'selecciones.0.estado', 'selecciones.0.probabilidad', 'selecciones.0.frecuencia_compra',
        ]);

        $this->assertSame(0, ProjectPotentialReference::count());
    }

    public function test_sin_permiso_de_edicion_solo_puede_consultar(): void
    {
        $project = $this->proyecto();
        $this->givePermissions(['project potential list']);

        $this->getJson("/api/project-potential/projects/{$project->id}")->assertOk();
        $this->guardar($project, [])->assertForbidden();
    }

    public function test_sin_permiso_no_puede_consultar(): void
    {
        $this->givePermissions(['project list']);

        $this->getJson('/api/project-potential?ejecutivo=X')->assertForbidden();
    }

    public function test_el_precio_y_el_potencial_no_se_editan_a_mano_desde_el_modulo(): void
    {
        $project = $this->proyecto();
        $ref     = $this->refId($project, 'Ref A');

        $this->patchJson("/api/project-potential/references/{$ref}", ['precio' => 30])->assertNotFound();
        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_kg' => 1])->assertStatus(405);
        $this->assertNull($project->fresh()->potencial_anual_kg);
    }
}
