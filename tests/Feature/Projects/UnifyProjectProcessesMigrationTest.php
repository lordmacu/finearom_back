<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;

class UnifyProjectProcessesMigrationTest extends ProjectMailTestCase
{
    public function test_fusiona_las_listas_de_proyecto_sin_repetir_correos_y_no_toca_las_demas(): void
    {
        $fila = fn ($tipo, $email) => ['name' => $email, 'email' => $email, 'process_type' => $tipo, 'created_at' => now(), 'updated_at' => now()];
        DB::table('processes')->insert([
            $fila('project_created', 'lab@finearom.co'),
            $fila('project_marketing_delivered', 'LAB@finearom.co'),
            $fila('project_marketing_delivered', 'mkt@finearom.co'),
            $fila('orden_de_compra', 'lab@finearom.co'),
        ]);
        DB::table('email_templates')->insert([
            'key' => 'project_marketing_partial', 'name' => 'x', 'subject' => 's', 'title' => 't', 'header_content' => 'h',
            'footer_content' => '<p>Configuración → Procesos ("Proyecto: entrega marketing"; si está vacía…)</p>',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_09_17_130000_unify_project_processes.php'))->up();

        $this->assertSame(['lab@finearom.co', 'mkt@finearom.co'], DB::table('processes')->where('process_type', 'proyectos')->orderBy('id')->pluck('email')->all());
        $this->assertSame(['orden_de_compra', 'proyectos'], DB::table('processes')->distinct()->orderBy('process_type')->pluck('process_type')->all());
        $this->assertSame(1, DB::table('processes')->where('process_type', 'orden_de_compra')->count());
        $this->assertStringContainsString('("Proyectos")', DB::table('email_templates')->value('footer_content'));
    }
}
