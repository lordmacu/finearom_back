<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Templates de la entrega del área Marketing (modal notas + adjuntos).
     * La re-entrega sale como actualización con el antes/después de las notas.
     * Solo se insertan si no existen: después se administran desde
     * /settings/email-templates.
     */
    public function up(): void
    {
        $button = '<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>';

        $footer = '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("Proyecto: entrega marketing"; si está vacía, se usa la de "Proyecto: creación").
</p>';

        $variables = json_encode([
            'project_id'     => 'ID del proyecto',
            'project_name'   => 'Nombre del proyecto',
            'client_name'    => 'Cliente, prospecto o nombre del prospecto',
            'delivered_by'   => 'Usuario que entregó/actualizó marketing',
            'notas_entrega'  => 'Notas escritas en el modal de entrega (HTML)',
            'changes_table'  => 'Tabla HTML con el antes/después de las notas (solo actualización)',
            'project_url'    => 'Link al proyecto en la plataforma',
        ]);

        if (!DB::table('email_templates')->where('key', 'project_marketing_delivered')->exists()) {
            DB::table('email_templates')->insert([
                'key'  => 'project_marketing_delivered',
                'name' => 'Proyecto: entrega marketing',

                'subject' => 'Marketing entregado — proyecto #|project_id| — |project_name| · |client_name|',

                'title' => 'Marketing entregado',

                'header_content' => '
<p>Hola equipo,</p>
<p><strong>|delivered_by|</strong> entregó el área de marketing del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>.</p>

<p><strong>Notas de la entrega:</strong></p>
<p>|notas_entrega|</p>

<p style="font-size:13px;color:#6b7280;">Los archivos de la entrega van adjuntos a este correo y también están disponibles en la vista del proyecto.</p>
' . $button,

                'footer_content' => $footer,
                'signature'      => null,
                'available_variables' => $variables,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('email_templates')->where('key', 'project_marketing_updated')->exists()) {
            DB::table('email_templates')->insert([
                'key'  => 'project_marketing_updated',
                'name' => 'Proyecto: actualización de marketing',

                'subject' => 'Marketing actualizado — proyecto #|project_id| — |project_name| · |client_name|',

                'title' => 'Marketing actualizado',

                'header_content' => '
<p>Hola equipo,</p>
<p><strong>|delivered_by|</strong> actualizó el área de marketing del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>.</p>

|changes_table|

<p><strong>Notas actuales:</strong></p>
<p>|notas_entrega|</p>

<p style="font-size:13px;color:#6b7280;">Los archivos actuales de la entrega van adjuntos a este correo y también están disponibles en la vista del proyecto.</p>
' . $button,

                'footer_content' => $footer,
                'signature'      => null,
                'available_variables' => $variables,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Borra los templates aunque up() no los haya insertado (filas preexistentes):
        // un rollback manual pierde las ediciones hechas por UI. Aceptado: deploy.sh nunca hace rollback.
        DB::table('email_templates')->whereIn('key', [
            'project_marketing_delivered',
            'project_marketing_updated',
        ])->delete();
    }
};
