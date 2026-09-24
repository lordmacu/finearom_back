<?php

namespace Tests\Feature\Projects;

use App\Models\Process;

/**
 * El correo de creación solo lleva campos que existen en el formulario.
 */
class ProjectCreationMailFieldsTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_updated', 'Cambios #|project_id|', '|changes_table|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);
    }

    private function correoDeCreacion(array $attrs): string
    {
        $project = $this->project($attrs);
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        return $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
    }

    public function test_no_incluye_campos_que_ya_no_estan_en_el_formulario(): void
    {
        $html = $this->correoDeCreacion([
            'volumen' => 500, 'factor' => 1.8, 'internacional' => true, 'costo_perfumacion_especifico' => 12,
            'estado_externo' => 'Sin definir', 'potencial_anual_kg' => 100, 'precio' => 5, 'rango_min' => 3, 'rango_max' => 5,
        ]);

        foreach (['Volumen', 'Factor', 'Internacional', 'Costo perfumación específico', 'Estado externo', 'Cancelado', 'Precio'] as $retirado) {
            $this->assertStringNotContainsString($retirado, $html, "El correo aún muestra '{$retirado}'");
        }
        $this->assertStringContainsString('Potencial anual (Kg)', $html);
        $this->assertStringContainsString('Potencial anual (USD)', $html);
        $this->assertStringContainsString('Rango de precio', $html);
        $this->assertStringContainsString('500,00', $html); // 100 Kg × rango máximo 5
    }

    public function test_el_estado_comercial_no_va_en_el_correo(): void
    {
        $html = $this->correoDeCreacion(['estado_externo' => 'Ganado', 'estado_interno' => 'En proceso']);

        foreach (['Estado comercial', 'Estado externo', 'Estado interno', 'Ganado'] as $texto) {
            $this->assertStringNotContainsString($texto, $html);
        }
    }

    public function test_el_estado_comercial_de_snapshots_viejos_no_cuenta_como_cambio(): void
    {
        $project = $this->project();
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $snapshot = $project->fresh()->email_snapshot;
        $snapshot['Estado comercial'] = ['Estado externo' => 'En espera', 'Estado interno' => 'En proceso'];
        $project->forceFill(['email_snapshot' => $snapshot])->saveQuietly();

        $this->postJson("/api/projects/{$project->id}/send-update")->assertStatus(422);
    }

    public function test_el_rango_renombrado_no_cuenta_como_cambio_en_snapshots_viejos(): void
    {
        $project = $this->project(['rango_min' => 3, 'rango_max' => 5]);
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        // Snapshot de antes del cambio de nombre ("Rango")
        $snapshot = $project->fresh()->email_snapshot;
        $snapshot['Información general']['Rango'] = $snapshot['Información general']['Rango de precio'];
        unset($snapshot['Información general']['Rango de precio']);
        $project->forceFill(['email_snapshot' => $snapshot])->saveQuietly();

        $this->postJson("/api/projects/{$project->id}/send-update")->assertStatus(422);
    }

    public function test_muestra_y_aplicacion_sin_unidades_en_snapshots_viejos_no_cuentan_como_cambio(): void
    {
        $project = $this->project();
        \App\Models\ProjectSample::create(['project_id' => $project->id, 'cantidad' => 250, 'cantidad_copias' => 1]);
        \App\Models\ProjectApplication::create(['project_id' => $project->id, 'dosis' => 1.5, 'cantidad_aplicacion' => 3]);
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Cantidad: 250,00 g · Copias: 1 unidad', $html);
        $this->assertStringContainsString('Dosis: 1,50 % · Cantidad: 3 unidades', $html);

        // Snapshot de antes de las unidades
        $snapshot = $project->fresh()->email_snapshot;
        $snapshot['Desarrollo']['Muestra aceite'] = 'Cantidad: 250,00 · Copias: 1';
        $snapshot['Desarrollo']['Aplicación'] = 'Dosis: 1,50 · Cantidad: 3';
        $project->forceFill(['email_snapshot' => $snapshot])->saveQuietly();

        $this->postJson("/api/projects/{$project->id}/send-update")->assertStatus(422);
    }
}
