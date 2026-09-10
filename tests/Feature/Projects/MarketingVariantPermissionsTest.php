<?php

namespace Tests\Feature\Projects;

use App\Support\MarketingVariantPermissions;

class MarketingVariantPermissionsTest extends ProjectFieldsTestCase
{
    public function test_comercial_maneja_variantes_pero_no_referencias(): void
    {
        $this->givePermissions(['project list', MarketingVariantPermissions::COMMERCIAL]);

        $this->assertSame([
            'can_manage_variants'   => true,
            'can_manage_references' => false,
        ], MarketingVariantPermissions::metaFor($this->user->fresh()));
    }

    public function test_desarrollo_maneja_referencias_pero_no_variantes(): void
    {
        $this->givePermissions(['project list', MarketingVariantPermissions::TECHNICAL]);

        $this->assertSame([
            'can_manage_variants'   => false,
            'can_manage_references' => true,
        ], MarketingVariantPermissions::metaFor($this->user->fresh()));
    }

    public function test_marketing_no_maneja_nada(): void
    {
        $this->givePermissions(['project list']);

        $this->assertSame([
            'can_manage_variants'   => false,
            'can_manage_references' => false,
        ], MarketingVariantPermissions::metaFor($this->user->fresh()));
    }

    private function project(): \App\Models\Project
    {
        return \App\Models\Project::create(['nombre' => 'Proyecto Permisos', 'fecha_creacion' => today()]);
    }

    public function test_el_meta_del_index_refleja_el_rol(): void
    {
        $project = $this->project();

        $this->givePermissions(['project list', MarketingVariantPermissions::COMMERCIAL]);
        $meta = $this->getJson("/api/projects/{$project->id}/marketing-variants")->assertOk()->json('meta');
        $this->assertTrue($meta['can_manage_variants']);
        $this->assertFalse($meta['can_manage_references']);

        $this->givePermissions(['project list', MarketingVariantPermissions::TECHNICAL]);
        $meta = $this->getJson("/api/projects/{$project->id}/marketing-variants")->assertOk()->json('meta');
        $this->assertFalse($meta['can_manage_variants']);
        $this->assertTrue($meta['can_manage_references']);

        $this->givePermissions(['project list']);
        $meta = $this->getJson("/api/projects/{$project->id}/marketing-variants")->assertOk()->json('meta');
        $this->assertFalse($meta['can_manage_variants']);
        $this->assertFalse($meta['can_manage_references']);
    }

    public function test_desarrollo_no_puede_crear_ni_editar_ni_borrar_variantes(): void
    {
        $project = $this->project();

        $this->givePermissions(['project list', MarketingVariantPermissions::COMMERCIAL]);
        $id = $this->postJson("/api/projects/{$project->id}/marketing-variants", ['nombre' => 'V1'])->json('data.id');

        $this->givePermissions(['project list', MarketingVariantPermissions::TECHNICAL]);

        $this->postJson("/api/projects/{$project->id}/marketing-variants", ['nombre' => 'V2'])->assertStatus(403);
        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}", ['nombre' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$project->id}/marketing-variants/{$id}")->assertStatus(403);
    }

    public function test_marketing_solo_puede_listar(): void
    {
        $project = $this->project();

        $this->givePermissions(['project list', MarketingVariantPermissions::COMMERCIAL]);
        $id = $this->postJson("/api/projects/{$project->id}/marketing-variants", ['nombre' => 'V1'])->json('data.id');

        $this->givePermissions(['project list']);

        $this->getJson("/api/projects/{$project->id}/marketing-variants")->assertOk();
        $this->postJson("/api/projects/{$project->id}/marketing-variants", ['nombre' => 'V2'])->assertStatus(403);
        $this->putJson("/api/projects/{$project->id}/marketing-variants/{$id}", ['nombre' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$project->id}/marketing-variants/{$id}")->assertStatus(403);
    }
}
