<?php

namespace Tests\Feature\Projects;

/**
 * Los filtros de la lista de proyectos se ofrecen en cascada: cada uno muestra solo los valores
 * que existen entre los proyectos que cumplen los OTROS filtros.
 */
class ProjectFilterOptionsTest extends ProjectMailTestCase
{
    private function opciones(array $query = []): array
    {
        return $this->getJson('/api/projects/filtro-opciones?' . http_build_query($query))->assertOk()->json('data');
    }

    private function sembrar(): void
    {
        $this->project(['nombre' => 'A', 'ejecutivo' => 'Ana',  'fecha_creacion' => '2025-01-10', 'estado_externo' => 'Ganado',      'estado_interno' => 'Entregado']);
        $this->project(['nombre' => 'B', 'ejecutivo' => 'Beto', 'fecha_creacion' => '2026-03-15', 'estado_externo' => 'Sin definir', 'estado_interno' => 'En proceso']);
        $this->project(['nombre' => 'C', 'ejecutivo' => 'Cami', 'fecha_creacion' => '2026-09-01', 'estado_externo' => 'Perdido',     'estado_interno' => 'En proceso']);
    }

    public function test_sin_filtros_ofrece_todos_los_valores(): void
    {
        $this->sembrar();

        $o = $this->opciones();

        $this->assertSame(['Ana', 'Beto', 'Cami'], $o['ejecutivos']);
        $this->assertEqualsCanonicalizing(['Ganado', 'Sin definir', 'Perdido'], $o['estados_externos']);
    }

    public function test_el_rango_de_fechas_reduce_los_ejecutivos_y_los_estados(): void
    {
        $this->sembrar();

        $o = $this->opciones(['desde' => '2026-01-01']);

        $this->assertSame(['Beto', 'Cami'], $o['ejecutivos']);
        $this->assertEqualsCanonicalizing(['Sin definir', 'Perdido'], $o['estados_externos']);
        $this->assertSame(['En proceso'], $o['estados_internos']);
    }

    public function test_un_filtro_no_se_limita_a_si_mismo_para_poder_cambiar_de_opcion(): void
    {
        $this->sembrar();

        // con ejecutivo=Beto el listado muestra 1 proyecto, pero el filtro de ejecutivo sigue ofreciendo los demás
        $o = $this->opciones(['ejecutivo' => 'Beto']);
        $this->assertSame(['Ana', 'Beto', 'Cami'], $o['ejecutivos']);

        // y los otros filtros sí se reducen por el ejecutivo elegido
        $this->assertSame(['Sin definir'], $o['estados_externos']);
        $this->assertSame(['En proceso'], $o['estados_internos']);
    }

    public function test_los_filtros_se_combinan(): void
    {
        $this->sembrar();

        $o = $this->opciones(['desde' => '2026-01-01', 'estado_externo' => 'Perdido']);

        $this->assertSame(['Cami'], $o['ejecutivos']);
        // el estado externo no se limita a sí mismo: ofrece los de las fechas elegidas
        $this->assertEqualsCanonicalizing(['Sin definir', 'Perdido'], $o['estados_externos']);
    }

    public function test_sin_resultados_no_ofrece_opciones(): void
    {
        $this->sembrar();

        $o = $this->opciones(['desde' => '2030-01-01']);

        $this->assertSame([], $o['ejecutivos']);
        $this->assertSame([], $o['estados_externos']);
        $this->assertFalse($o['hay_sin_asignar']);
    }

    public function test_el_filtro_de_fechas_del_listado_sigue_igual(): void
    {
        $this->sembrar();

        $this->assertCount(2, $this->getJson('/api/projects?desde=2026-01-01')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/projects?ejecutivo=Beto')->assertOk()->json('data'));
    }
}
