<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Templates de entrega PARCIAL por área (misma lista de destinatarios que
     * la entrega de cada área). Solo se insertan si no existen: después se
     * administran desde /settings/email-templates.
     */
    private const AREAS = [
        // key del template => [nombre del área, proceso (lista de destinatarios)]
        'applications_partial' => ['Aplicaciones', 'Proyecto: aplicaciones listas'],
        'evaluation_partial'   => ['Evaluaciones', 'Proyecto: entrega evaluaciones'],
        'marketing_partial'    => ['Marketing', 'Proyecto: entrega marketing'],
        'regulatoria_partial'  => ['Regulatoria', 'Proyecto: entrega regulatoria'],
        'especiales_partial'   => ['P. Especiales', 'Proyecto: entrega especiales'],
    ];

    public function up(): void
    {
        $button = '<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>';

        $variables = json_encode([
            'project_id'     => 'ID del proyecto',
            'project_name'   => 'Nombre del proyecto',
            'client_name'    => 'Cliente, prospecto o nombre del prospecto',
            'delivered_by'   => 'Área que registró la entrega parcial',
            'notas_entrega'  => 'Notas de esta entrega parcial (HTML, opcional)',
            'project_url'    => 'Link al proyecto en la plataforma',
        ]);

        foreach (self::AREAS as $key => [$nombre, $proceso]) {
            if (DB::table('email_templates')->where('key', "project_{$key}")->exists()) {
                continue;
            }

            DB::table('email_templates')->insert([
                'key'     => "project_{$key}",
                'name'    => "Proyecto: entrega parcial de {$nombre}",
                'subject' => "{$nombre}: entrega parcial — proyecto #|project_id| — |project_name| · |client_name|",
                'title'   => "{$nombre}: entrega parcial",

                'header_content' => '
<p>Hola equipo,</p>
<p><strong>|delivered_by|</strong> registró una <strong>entrega parcial</strong> del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>. El área sigue en proceso.</p>

<p><strong>Notas de esta entrega:</strong></p>
<p>|notas_entrega|</p>

<p style="font-size:13px;color:#6b7280;">Los archivos de esta entrega (si los hay) van adjuntos a este correo; todas las entregas quedan en la bitácora del proyecto.</p>
' . $button,

                'footer_content' => '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("' . $proceso . '"; si está vacía, se usa la de "Proyecto: creación").
</p>',
                'signature'           => null,
                'available_variables' => $variables,
                'is_active'           => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('key', array_map(fn ($k) => "project_{$k}", array_keys(self::AREAS)))
            ->delete();
    }
};
