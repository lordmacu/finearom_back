<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectMarketingVariant;
use App\Models\ProjectMarketingVariantReference;

/**
 * Las variantes que crea Desarrollo se crean solas en Marketing (solo el nombre).
 */
class ProjectVariantMarketingSyncTest extends ProjectMailTestCase
{
    private function crearVariante($project, string $nombre = 'Variante A'): int
    {
        return $this->postJson("/api/projects/{$project->id}/variants", ['nombre' => $nombre])
            ->assertCreated()->json('data.id');
    }

    public function test_crear_una_variante_en_desarrollo_la_crea_en_marketing(): void
    {
        $project = $this->project();
        ProjectMarketingVariant::create(['project_id' => $project->id, 'nombre' => 'Propia de marketing', 'orden' => 1]);

        $id = $this->crearVariante($project, 'Lavanda');

        $mv = ProjectMarketingVariant::where('project_variant_id', $id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('Lavanda', $mv->nombre);
        $this->assertSame($project->id, $mv->project_id);
        $this->assertSame(2, $mv->orden);
    }

    public function test_renombrar_en_desarrollo_renombra_en_marketing_sin_tocar_lo_demas(): void
    {
        $project = $this->project();
        $id = $this->crearVariante($project, 'Lavanda');
        ProjectMarketingVariant::where('project_variant_id', $id)->update(['claims' => 'Suave', 'color_etiqueta' => '#ff0000']);

        $this->putJson("/api/projects/{$project->id}/variants/{$id}", ['nombre' => 'Lavanda Fresh'])->assertOk();

        $mv = ProjectMarketingVariant::where('project_variant_id', $id)->first();
        $this->assertSame('Lavanda Fresh', $mv->nombre);
        $this->assertSame('Suave', $mv->claims);
        $this->assertSame('#ff0000', $mv->color_etiqueta);
    }

    public function test_borrar_en_desarrollo_borra_en_marketing_si_marketing_no_le_agrego_nada(): void
    {
        $project = $this->project();
        $id = $this->crearVariante($project);

        $this->deleteJson("/api/projects/{$project->id}/variants/{$id}")->assertOk();

        $this->assertSame(0, ProjectMarketingVariant::where('project_id', $project->id)->count());
    }

    public function test_borrar_en_desarrollo_conserva_la_de_marketing_si_ya_tiene_trabajo(): void
    {
        $project = $this->project();
        $conClaims = $this->crearVariante($project, 'Con claims');
        $conRefs   = $this->crearVariante($project, 'Con referencias');
        ProjectMarketingVariant::where('project_variant_id', $conClaims)->update(['claims' => 'Hipoalergénico']);
        $mvRefs = ProjectMarketingVariant::where('project_variant_id', $conRefs)->first();
        ProjectMarketingVariantReference::create(['variant_id' => $mvRefs->id, 'referencia' => 'REF-1', 'orden' => 1]);

        $this->deleteJson("/api/projects/{$project->id}/variants/{$conClaims}")->assertOk();
        $this->deleteJson("/api/projects/{$project->id}/variants/{$conRefs}")->assertOk();

        $restantes = ProjectMarketingVariant::where('project_id', $project->id)->orderBy('id')->get();
        $this->assertSame(['Con claims', 'Con referencias'], $restantes->pluck('nombre')->all());
        $this->assertTrue($restantes->every(fn ($mv) => $mv->project_variant_id === null));
    }
}
