<?php

namespace Tests\Feature\Projects;

use App\Models\Project;

class ProjectListDateFilterTest extends ProjectMailTestCase
{
    public function test_filtra_el_listado_por_rango_de_fecha_de_creacion(): void
    {
        $viejo  = $this->project(['nombre' => 'Viejo', 'fecha_creacion' => '2025-01-10']);
        $medio  = $this->project(['nombre' => 'Medio', 'fecha_creacion' => '2026-03-15']);
        $nuevo  = $this->project(['nombre' => 'Nuevo', 'fecha_creacion' => '2026-09-01']);

        $ids = fn ($query) => collect($this->getJson('/api/projects?' . http_build_query($query))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$medio->id, $nuevo->id], $ids(['desde' => '2026-01-01']));
        $this->assertSame([$viejo->id, $medio->id], $ids(['hasta' => '2026-03-15']));
        $this->assertSame([$medio->id], $ids(['desde' => '2026-03-15', 'hasta' => '2026-03-15']));
        // Fecha mal formada: se ignora
        $this->assertCount(3, $ids(['desde' => 'ayer']));
    }
}
