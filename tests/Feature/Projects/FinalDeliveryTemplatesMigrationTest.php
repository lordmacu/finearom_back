<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;

class FinalDeliveryTemplatesMigrationTest extends ProjectMailTestCase
{
    private function insertar(string $key, string $title, string $body): void
    {
        DB::table('email_templates')->insert([
            'key' => $key, 'name' => $key, 'subject' => 's', 'title' => $title,
            'header_content' => $body, 'available_variables' => json_encode(['project_id' => 'ID']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_marca_final_y_actualizacion_solo_en_templates_sin_editar(): void
    {
        $this->insertar('project_regulatoria_delivered', 'Regulatoria entregada', '<p>Entrega del área de |delivered_by| en…</p>');
        $this->insertar('project_regulatoria_updated', 'Regulatoria actualizada', '<p>Actualización del área de |delivered_by| en…</p>');
        // Editado a mano desde la UI: se respeta
        $this->insertar('project_marketing_delivered', 'Mi título propio', '<p>Entrega del área de |delivered_by|</p>');

        $migracion = require database_path('migrations/2026_09_17_120000_mark_final_delivery_in_area_templates.php');
        $migracion->up();

        $t = fn ($key) => DB::table('email_templates')->where('key', $key)->first();

        $this->assertSame('Regulatoria: entrega final', $t('project_regulatoria_delivered')->title);
        $this->assertStringContainsString('<strong>Entrega final</strong> del área de', $t('project_regulatoria_delivered')->header_content);
        $this->assertSame('Regulatoria: actualización después de la entrega final', $t('project_regulatoria_updated')->title);
        $this->assertStringContainsString('Actualización (posterior a la entrega final) del área de', $t('project_regulatoria_updated')->header_content);

        $this->assertSame('Mi título propio', $t('project_marketing_delivered')->title);
        $this->assertStringNotContainsString('Entrega final', $t('project_marketing_delivered')->header_content);

        // La variable queda documentada en todos los de entrega
        $this->assertArrayHasKey('tipo_entrega', json_decode($t('project_marketing_delivered')->available_variables, true));

        $migracion->down();
        $this->assertSame('Regulatoria entregada', $t('project_regulatoria_delivered')->title);
        $this->assertStringNotContainsString('Entrega final', $t('project_regulatoria_delivered')->header_content);
    }
}
