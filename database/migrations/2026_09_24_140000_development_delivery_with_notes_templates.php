<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desarrollo entrega con el mismo modal que las demás áreas (notas + adjuntos,
 * parcial y final). Se agrega la plantilla de entrega parcial y las notas a las
 * de entrega final y actualización. Solo toca lo que falte: las plantillas se
 * administran desde /settings/email-templates.
 */
return new class extends Migration
{
    private const NOTAS = '
<p><strong>Notas de la entrega:</strong></p>
<p>|notas_entrega|</p>
<p style="font-size:13px;color:#6b7280;">Los archivos de la entrega (si los hay) van adjuntos a este correo; todas las entregas quedan en la bitácora del proyecto.</p>
';

    public function up(): void
    {
        $button = '<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>';

        if (!DB::table('email_templates')->where('key', 'project_development_partial')->exists()) {
            DB::table('email_templates')->insert([
                'key'     => 'project_development_partial',
                'name'    => 'Proyecto: entrega parcial de Desarrollo',
                'subject' => 'Desarrollo: entrega parcial — proyecto #|project_id| — |project_name| · |client_name|',
                'title'   => 'Desarrollo: entrega parcial',
                'header_content' => '
<p>Hola equipo,</p>
<p><strong>|engineer_name|</strong> registró una <strong>entrega parcial</strong> de desarrollo del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>. El área sigue en proceso.</p>
' . self::NOTAS . '
<p><strong>Variantes y referencias a la fecha:</strong></p>
|variants_table|
' . $button,
                'footer_content' => '<p style="font-size:12px;color:#9ca3af;">Correo automático de la plataforma Finearom.</p>',
                'signature'           => null,
                'available_variables' => json_encode([
                    'project_id'     => 'ID del proyecto',
                    'project_name'   => 'Nombre del proyecto',
                    'client_name'    => 'Cliente, prospecto o nombre del prospecto',
                    'engineer_name'  => 'Quien registró la entrega',
                    'notas_entrega'  => 'Notas de esta entrega (HTML, opcional)',
                    'variants_table' => 'Tabla de variantes y referencias',
                    'project_url'    => 'Link al proyecto en la plataforma',
                ]),
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Final y actualización: agregar las notas antes de la tabla de variantes
        foreach (['project_development_delivered', 'project_development_updated'] as $key) {
            $template = DB::table('email_templates')->where('key', $key)->first();
            if (!$template || str_contains((string) $template->header_content, '|notas_entrega|')) {
                continue;
            }

            // Justo después de la tabla de variantes (o al final si no hay tabla)
            $html  = (string) $template->header_content;
            $tabla = strpos($html, '|variants_table|');
            $html  = $tabla === false
                ? $html . self::NOTAS
                : substr_replace($html, '|variants_table|' . self::NOTAS, $tabla, strlen('|variants_table|'));

            $variables = json_decode((string) $template->available_variables, true) ?: [];
            $variables['notas_entrega'] = 'Notas de la entrega (HTML, opcional)';

            DB::table('email_templates')->where('key', $key)->update([
                'header_content'      => $html,
                'available_variables' => json_encode($variables),
                'updated_at'          => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'project_development_partial')->delete();

        foreach (['project_development_delivered', 'project_development_updated'] as $key) {
            $template = DB::table('email_templates')->where('key', $key)->first();
            if ($template) {
                DB::table('email_templates')->where('key', $key)->update([
                    'header_content' => str_replace(self::NOTAS, '', (string) $template->header_content),
                ]);
            }
        }
    }
};
