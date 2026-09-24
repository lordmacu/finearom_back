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
            'estado_externo'   => 'Sin definir',
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
        // Suma informativa de las seleccionadas (100 Kg × 20 USD); el potencial del proyecto no cambia
        $this->assertEquals(['kg' => 100, 'usd' => 2000], $res->json('data.0.suma_referencias'));
        $this->assertNull($res->json('data.0.potencial_anual_kg'));
    }

    public function test_el_listado_trae_el_plan_de_despachos_del_anio_y_los_totales_por_mes(): void
    {
        $project = $this->proyecto();
        // Ref B: 1200 Kg/año × 20 USD, trimestral desde junio 2026
        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref B'), [
            'kg_anio' => 1200, 'frecuencia_compra' => 'trimestral', 'fecha_primer_despacho' => '2026-06-01',
        ])])->assertOk();

        $res = $this->getJson('/api/project-potential?anios[]=2027&anios[]=2026&ejecutivo=' . urlencode('María Ortega'))->assertOk();

        $refs = collect($res->json('data.0.referencias'))->keyBy('referencia');
        $this->assertSame([], $refs['Ref A']['planes']);
        [$plan2026, $plan2027] = $refs['Ref B']['planes'];
        $this->assertSame(2026, $plan2026['anio']);
        $this->assertSame(3, $plan2026['despachos']);
        $this->assertEquals(300, $plan2026['meses'][5]['kg']);
        $this->assertEquals(18000, $plan2026['total_usd']);
        $this->assertSame(4, $plan2027['despachos']);
        // Años ordenados y con su venta estimada
        $this->assertSame([2026, 2027], $res->json('meta.anios'));
        $this->assertEquals(['anio' => 2026, 'total_kg' => 900, 'total_usd' => 18000], $res->json('meta.por_anio.0'));
        $this->assertEquals(['anio' => 2027, 'total_kg' => 1200, 'total_usd' => 24000], $res->json('meta.por_anio.1'));

        // Un solo año con el parámetro antiguo
        $solo = $this->getJson('/api/project-potential?anio=2027&ejecutivo=' . urlencode('María Ortega'))->assertOk();
        $this->assertSame([2027], $solo->json('meta.anios'));
    }

    public function test_el_detalle_calcula_el_plan_para_el_anio_pedido(): void
    {
        $project = $this->proyecto();
        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref A'), [
            'kg_anio' => 600, 'frecuencia_compra' => 'semestral', 'fecha_primer_despacho' => '2026-09-01',
        ])])->assertOk();

        $res = $this->getJson("/api/project-potential/projects/{$project->id}?anios[]=2027")->assertOk();

        $this->assertSame([2027], $res->json('data.anios'));
        $plan = collect($res->json('data.referencias'))->firstWhere('referencia', 'Ref A')['planes'][0];
        $this->assertEquals([3 => 300, 9 => 300], collect($plan['meses'])->filter(fn ($m) => $m['kg'] > 0)->pluck('kg', 'mes')->all());
    }

    public function test_la_descarga_tiene_el_formato_del_excel_con_una_fila_por_referencia_seleccionada(): void
    {
        $categoria = DB::table('product_categories')->insertGetId(['name' => 'Home Care', 'slug' => 'home-care']);
        $project   = $this->proyecto('María Ortega', ['nombre' => 'Control olor', 'proactivo' => true, 'product_category_id' => $categoria]);
        $this->proyecto('Otra Ejecutiva', ['nombre' => 'Ajeno']);
        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref B'), [
            'kg_anio' => 1200, 'frecuencia_compra' => 'trimestral', 'fecha_primer_despacho' => '2026-06-01',
            'estado' => 'ganado', 'probabilidad' => 'alta', 'venta_anio_usd' => 700,
        ])])->assertOk();

        $sheet = app(\App\Services\ProjectPotentialExportService::class)
            ->build('María Ortega', null, [2026])
            ->getActiveSheet();
        $fila = fn (int $n) => $sheet->rangeToArray("A{$n}:AP{$n}", null, true, false)[0];

        $encabezado = $fila(1);
        $this->assertSame('EJECUTIVA', $encabezado[0]);
        $this->assertSame('REFERENCIA - CODIGO', $encabezado[4]);
        $this->assertSame('VENTA ' . now()->year, $encabezado[11]);
        $this->assertSame('ENERO KG 2026', $encabezado[15]);
        $this->assertSame('TOTAL VENTA ESTIMADA AÑO 2026 USD', $encabezado[39]);
        $this->assertSame('PROBABILIDAD', $encabezado[40]);
        // Solo el año elegido: nada después de PROBABILIDAD
        $this->assertNull($encabezado[41]);
        $this->assertSame('FF203764', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());

        $datos = $fila(2);
        $this->assertEquals(['MARÍA ORTEGA', $project->id, 'Prospecto P', 'Control olor', 'C-2 Ref B', 'HOME CARE', 'PROACTIVO'], array_slice($datos, 0, 7));
        $this->assertSame('n', $sheet->getCell('B2')->getDataType());
        $this->assertSame('n', $sheet->getCell('Z2')->getDataType());
        $this->assertEquals([1200, 20, 24000, 'JUNIO 2026', 700, 'TRIMESTRAL', 'Panel consumidor', 'GANADO'], array_slice($datos, 7, 8));
        $this->assertNull($datos[15]);          // enero 2026 sin despacho
        $this->assertEquals(300, $datos[25]);   // junio 2026 Kg
        $this->assertEquals(6000, $datos[26]);  // junio 2026 USD
        $this->assertEquals(18000, $datos[39]); // total 2026
        $this->assertSame('ALTA', $datos[40]);
        $this->assertNull($datos[41]);
        $this->assertNull($fila(3)[0]);         // una sola fila: Ref A no está seleccionada, el otro proyecto es ajeno
    }

    public function test_la_descarga_con_varios_anios_agrega_un_bloque_de_meses_por_anio(): void
    {
        $project = $this->proyecto();
        $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref B'), [
            'kg_anio' => 1200, 'frecuencia_compra' => 'trimestral', 'fecha_primer_despacho' => '2026-06-01', 'probabilidad' => 'media',
        ])])->assertOk();

        $sheet = app(\App\Services\ProjectPotentialExportService::class)
            ->build('María Ortega', null, [2026, 2027])
            ->getActiveSheet();
        [$encabezado, $datos] = $sheet->rangeToArray('A1:BP2', null, true, false);

        $this->assertSame('ENERO KG 2026', $encabezado[15]);
        $this->assertSame('ENERO KG 2027', $encabezado[41]);
        $this->assertSame('TOTAL VENTA ESTIMADA AÑO 2027 USD', $encabezado[65]);
        $this->assertSame('PROBABILIDAD', $encabezado[66]);
        $this->assertNull($encabezado[67]);
        $this->assertEquals(18000, $datos[39]); // total 2026
        $this->assertEquals(300, $datos[45]);   // marzo 2027 Kg
        $this->assertEquals(24000, $datos[65]); // total 2027
        $this->assertSame('MEDIA', $datos[66]);
    }

    public function test_el_endpoint_de_descarga_aplica_filtros_y_permisos(): void
    {
        $this->getJson('/api/project-potential/export?estado_externo=Otro')->assertStatus(422);

        $res = $this->get('/api/project-potential/export?anio=2027&ejecutivo=' . urlencode('María Ortega'))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('potencial_a_la_vista_maria_ortega_2027.xlsx', $res->headers->get('Content-Disposition'));

        $varios = $this->get('/api/project-potential/export?anios[]=2027&anios[]=2026')->assertOk();
        $this->assertStringContainsString('potencial_a_la_vista_2026-2027.xlsx', $varios->headers->get('Content-Disposition'));

        $this->givePermissions(['project list']);
        $this->get('/api/project-potential/export')->assertForbidden();
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

        // El potencial del proyecto no se toca; la suma se informa aparte
        $project->refresh();
        $this->assertNull($project->potencial_anual_kg);
        $this->assertNull($project->potencial_anual_usd);
        $this->assertEquals(['kg' => 1200, 'usd' => 16500], $res->json('data.suma_referencias'));
        $this->assertStringContainsString('2 referencia(s) seleccionada(s)', ProjectStatusHistory::first()->descripcion);
        // El estado de la referencia no toca el del proyecto
        $this->assertSame('Sin definir', $project->estado_externo);
    }

    public function test_deseleccionar_borra_los_datos_de_la_referencia(): void
    {
        $project = $this->proyecto();
        $a = $this->refId($project, 'Ref A');
        $b = $this->refId($project, 'Ref B');
        $this->guardar($project, [$this->seleccion($a), $this->seleccion($b)])->assertOk();

        $res = $this->guardar($project, [$this->seleccion($b, ['kg_anio' => 50])])->assertOk();
        $this->assertSame([$b], ProjectPotentialReference::pluck('reference_id')->all());
        $this->assertEquals(50, $res->json('data.suma_referencias.kg'));

        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_kg' => 999])->assertOk();

        $this->guardar($project, [])->assertOk();
        $this->assertSame(0, ProjectPotentialReference::count());
        $this->assertEquals(999, $project->fresh()->potencial_anual_kg);
        $this->assertNull($this->getJson("/api/project-potential/projects/{$project->id}")->json('data.suma_referencias'));
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

    public function test_el_precio_de_las_referencias_no_se_edita_desde_el_modulo(): void
    {
        $project = $this->proyecto();
        $ref     = $this->refId($project, 'Ref A');

        $this->patchJson("/api/project-potential/references/{$ref}", ['precio' => 30])->assertNotFound();
    }

    public function test_el_potencial_kg_manual_recalcula_el_usd_y_se_conserva_al_guardar_referencias(): void
    {
        $project = $this->proyecto(attrs: ['rango_max' => 15]);

        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_kg' => 800])
            ->assertOk()
            ->assertJsonPath('data.potencial_anual_kg', 800)
            ->assertJsonPath('data.potencial_anual_usd', 12000);
        $this->assertStringContainsString('Potencial anual (Kg) (manual)', ProjectStatusHistory::orderBy('id')->first()->descripcion);

        // Guardar referencias no pisa lo editado: la suma queda aparte
        $res = $this->guardar($project, [$this->seleccion($this->refId($project, 'Ref B'), ['kg_anio' => 10])])->assertOk();
        $this->assertEquals(800, $project->fresh()->potencial_anual_kg);
        $this->assertEquals(12000, $project->fresh()->potencial_anual_usd);
        $this->assertEquals(['kg' => 10, 'usd' => 200], $res->json('data.suma_referencias'));
    }

    public function test_el_potencial_usd_no_se_edita_a_mano(): void
    {
        $project = $this->proyecto();

        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_usd' => 12345.67])
            ->assertStatus(422)->assertJsonValidationErrors('potencial_anual_kg');
        $this->assertNull($project->fresh()->potencial_anual_usd);
    }

    public function test_el_ajuste_manual_valida_y_respeta_permisos(): void
    {
        $project = $this->proyecto();

        $this->patchJson("/api/project-potential/projects/{$project->id}", [])
            ->assertStatus(422)->assertJsonValidationErrors('potencial_anual_kg');
        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_kg' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('potencial_anual_kg');

        // Mismo valor: no ensucia el historial
        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_kg' => null])->assertOk();
        $this->assertSame(0, ProjectStatusHistory::count());

        $this->givePermissions(['project potential list']);
        $this->patchJson("/api/project-potential/projects/{$project->id}", ['potencial_anual_kg' => 5])->assertForbidden();
    }
}
