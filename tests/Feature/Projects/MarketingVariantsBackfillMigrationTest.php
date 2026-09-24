<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectMarketingVariant;
use App\Models\ProjectVariant;

class MarketingVariantsBackfillMigrationTest extends ProjectMailTestCase
{
    private function correrMigracion(): void
    {
        (require database_path('migrations/2026_09_24_130000_backfill_marketing_variants_from_project_variants.php'))->up();
    }

    public function test_copia_a_marketing_las_variantes_viejas_de_desarrollo_sin_duplicar(): void
    {
        $project = $this->project();
        $a = ProjectVariant::create(['project_id' => $project->id, 'nombre' => 'Lavanda']);
        $b = ProjectVariant::create(['project_id' => $project->id, 'nombre' => 'Coco']);
        // Marketing ya tenía "coco" (mismo nombre, sin enlace) con su trabajo
        $existente = ProjectMarketingVariant::create(['project_id' => $project->id, 'nombre' => 'coco ', 'claims' => 'Suave', 'orden' => 3]);

        $this->correrMigracion();
        $this->correrMigracion(); // idempotente

        $mvs = ProjectMarketingVariant::where('project_id', $project->id)->get();
        $this->assertCount(2, $mvs);
        $this->assertSame($b->id, $existente->fresh()->project_variant_id);
        $this->assertSame('Suave', $existente->fresh()->claims);

        $nueva = $mvs->firstWhere('project_variant_id', $a->id);
        $this->assertSame('Lavanda', $nueva->nombre);
        $this->assertSame(4, $nueva->orden);
    }

    public function test_marketing_no_renombra_las_que_vienen_de_desarrollo(): void
    {
        $project = $this->project();
        $v = ProjectVariant::create(['project_id' => $project->id, 'nombre' => 'Lavanda']);
        $this->correrMigracion();
        $mv = ProjectMarketingVariant::where('project_variant_id', $v->id)->first();

        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$mv->id}", ['nombre' => 'Otro', 'claims' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');

        // Mismo nombre + otros campos: sí se guarda
        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$mv->id}", ['nombre' => 'Lavanda', 'claims' => 'Fresco'])->assertOk();
        $this->assertSame('Fresco', $mv->fresh()->claims);
    }
}
