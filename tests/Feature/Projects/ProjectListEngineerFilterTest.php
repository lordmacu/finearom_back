<?php

namespace Tests\Feature\Projects;

use App\Models\User;

class ProjectListEngineerFilterTest extends ProjectMailTestCase
{
    public function test_filtra_el_listado_por_ingeniero_asignado_o_sin_asignar(): void
    {
        $ing1 = User::create(['name' => 'Ing Uno', 'email' => 'ing1@finearom.co', 'password' => bcrypt('x')]);
        $ing2 = User::create(['name' => 'Ing Dos', 'email' => 'ing2@finearom.co', 'password' => bcrypt('x')]);
        $a = $this->project(['nombre' => 'A', 'desarrollador_id' => $ing1->id]);
        $b = $this->project(['nombre' => 'B', 'desarrollador_id' => $ing2->id]);
        $c = $this->project(['nombre' => 'C']);

        $ids = fn ($q) => collect($this->getJson('/api/projects?' . http_build_query($q))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$a->id], $ids(['desarrollador_id' => $ing1->id]));
        $this->assertSame([$c->id], $ids(['desarrollador_id' => 'sin_asignar']));
        $this->assertCount(3, $ids(['desarrollador_id' => 'cualquiera']));
    }
}
