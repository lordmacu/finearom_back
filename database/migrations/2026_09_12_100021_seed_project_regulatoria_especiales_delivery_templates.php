<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Templates de entrega de Regulatoria y P. Especiales (modal notas +
     * adjuntos opcionales, mismo flujo que aplicaciones/evaluaciones/marketing).
     * Solo se insertan si no existen: después se administran desde
     * /settings/email-templates.
     */
    public function up(): void
    {
        $areas = [
            'regulatoria' => [
                'nombre'   => 'Regulatoria',
                'entrega'  => 'Regulatoria entregada',
                'accion'   => 'entregó el área de regulatoria',
                'accion_u' => 'actualizó el área de regulatoria',
                'proceso'  => 'Proyecto: entrega regulatoria',
            ],
            'especiales' => [
                'nombre'   => 'P. Especiales',
                'entrega'  => 'P. Especiales entregado',
                'accion'   => 'entregó el área de proyectos especiales',
                'accion_u' => 'actualizó el área de proyectos especiales',
                'proceso'  => 'Proyecto: entrega especiales',
            ],
        ];

        $button = '<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>';

        $variables = json_encode([
            'project_id'     => 'ID del proyecto',
            'project_name'   => 'Nombre del proyecto',
            'client_name'    => 'Cliente, prospecto o nombre del prospecto',
            'delivered_by'   => 'Usuario que entregó/actualizó el área',
            'notas_entrega'  => 'Notas escritas en el modal de entrega (HTML, opcional)',
            'changes_table'  => 'Tabla HTML con el antes/después de las notas (solo actualización)',
            'project_url'    => 'Link al proyecto en la plataforma',
        ]);

        foreach ($areas as $area => $t) {
            $footer = '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("' . $t['proceso'] . '"; si está vacía, se usa la de "Proyecto: creación").
</p>';

            if (!DB::table('email_templates')->where('key', "project_{$area}_delivered")->exists()) {
                DB::table('email_templates')->insert([
                    'key'  => "project_{$area}_delivered",
                    'name' => $t['proceso'],

                    'subject' => $t['entrega'] . ' — proyecto #|project_id| — |project_name| · |client_name|',

                    'title' => $t['entrega'],

                    'header_content' => '
<p>Hola equipo,</p>
<p><strong>|delivered_by|</strong> ' . $t['accion'] . ' del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>.</p>

<p><strong>Notas de la entrega:</strong></p>
<p>|notas_entrega|</p>

<p style="font-size:13px;color:#6b7280;">Los archivos de la entrega (si los hay) van adjuntos a este correo y también están disponibles en la vista del proyecto.</p>
' . $button,

                    'footer_content' => $footer,
                    'signature'      => null,
                    'available_variables' => $variables,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (!DB::table('email_templates')->where('key', "project_{$area}_updated")->exists()) {
                DB::table('email_templates')->insert([
                    'key'  => "project_{$area}_updated",
                    'name' => 'Proyecto: actualización de ' . $area,

                    'subject' => $t['nombre'] . ' actualizada — proyecto #|project_id| — |project_name| · |client_name|',

                    'title' => $t['nombre'] . ' actualizada',

                    'header_content' => '
<p>Hola equipo,</p>
<p><strong>|delivered_by|</strong> ' . $t['accion_u'] . ' del proyecto
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
    }

    public function down(): void
    {
        // Borra los templates aunque up() no los haya insertado (filas preexistentes):
        // un rollback manual pierde las ediciones hechas por UI. Aceptado: deploy.sh nunca hace rollback.
        DB::table('email_templates')->whereIn('key', [
            'project_regulatoria_delivered',
            'project_regulatoria_updated',
            'project_especiales_delivered',
            'project_especiales_updated',
        ])->delete();
    }
};
