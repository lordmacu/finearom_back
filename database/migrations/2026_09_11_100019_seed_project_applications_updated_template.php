<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aplicaciones pasa al flujo de modal (notas + adjuntos opcionales):
     * el template project_applications_ready se ACTUALIZA para incluir las
     * notas y el aviso de adjuntos, y se crea project_applications_updated
     * para la re-entrega. Los templates se administran desde
     * /settings/email-templates.
     */
    public function up(): void
    {
        $button = '<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>';

        $footer = '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("Proyecto: aplicaciones listas"; si está vacía, se usa la de "Proyecto: creación").
</p>';

        $variables = json_encode([
            'project_id'     => 'ID del proyecto',
            'project_name'   => 'Nombre del proyecto',
            'client_name'    => 'Cliente, prospecto o nombre del prospecto',
            'delivered_by'   => 'Usuario que entregó/actualizó aplicaciones',
            'notas_entrega'  => 'Notas escritas en el modal de entrega (HTML, opcional)',
            'changes_table'  => 'Tabla HTML con el antes/después de las notas (solo actualización)',
            'project_url'    => 'Link al proyecto en la plataforma',
        ]);

        // UPDATE (no insert-if-missing): el template viejo decía solo "están
        // listas" y no tenía variables de notas/adjuntos
        DB::table('email_templates')->where('key', 'project_applications_ready')->update([
            'subject' => 'Aplicaciones listas — proyecto #|project_id| — |project_name| · |client_name|',
            'title'   => 'Aplicaciones listas',
            'header_content' => '
<p>Hola equipo,</p>
<p><strong>|delivered_by|</strong> entregó las aplicaciones del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>:
las aplicaciones solicitadas se encuentran listas.</p>

<p><strong>Notas de la entrega:</strong></p>
<p>|notas_entrega|</p>

<p style="font-size:13px;color:#6b7280;">Los archivos de la entrega (si los hay) van adjuntos a este correo y también están disponibles en la vista del proyecto.</p>
' . $button,
            'footer_content' => $footer,
            'available_variables' => $variables,
            'updated_at' => now(),
        ]);

        if (!DB::table('email_templates')->where('key', 'project_applications_updated')->exists()) {
            DB::table('email_templates')->insert([
                'key'  => 'project_applications_updated',
                'name' => 'Proyecto: actualización de aplicaciones',

                'subject' => 'Aplicaciones actualizadas — proyecto #|project_id| — |project_name| · |client_name|',

                'title' => 'Aplicaciones actualizadas',

                'header_content' => '
<p>Hola equipo,</p>
<p><strong>|delivered_by|</strong> actualizó las aplicaciones del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>.</p>

|changes_table|

<p><strong>Notas actuales:</strong></p>
<p>|notas_entrega|</p>

<p style="font-size:13px;color:#6b7280;">Los archivos actuales de la entrega (si los hay) van adjuntos a este correo y también están disponibles en la vista del proyecto.</p>
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
        // Borra el template nuevo aunque up() no lo haya insertado. El template
        // project_applications_ready queda actualizado (no se restaura el viejo:
        // deploy.sh nunca hace rollback).
        DB::table('email_templates')->where('key', 'project_applications_updated')->delete();
    }
};
