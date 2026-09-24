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
            'estado_externo' => 'Cancelado', 'potencial_anual_kg' => 100, 'precio' => 5, 'rango_min' => 3, 'rango_max' => 5,
        ]);

        foreach (['Volumen', 'Factor', 'Internacional', 'Costo perfumación específico', 'Estado externo', 'Cancelado', 'Precio'] as $retirado) {
            $this->assertStringNotContainsString($retirado, $html, "El correo aún muestra '{$retirado}'");
        }
        $this->assertStringContainsString('Potencial anual (Kg)', $html);
        $this->assertStringContainsString('Potencial anual (USD)', $html);
        $this->assertStringContainsString('Rango', $html);
        $this->assertStringContainsString('500,00', $html); // 100 Kg × rango máximo 5
    }

    public function test_ganado_si_aparece_como_estado_externo(): void
    {
        $html = $this->correoDeCreacion(['estado_externo' => 'Ganado']);

        $this->assertStringContainsString('Estado externo', $html);
        $this->assertStringContainsString('Ganado', $html);
    }
}
