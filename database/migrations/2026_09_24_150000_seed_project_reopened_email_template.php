<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correo del hilo del proyecto cuando alguien lo reabre. Solo se inserta si no
 * existe: después se administra desde /settings/email-templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('email_templates')->where('key', 'project_reopened')->exists()) {
            return;
        }

        DB::table('email_templates')->insert([
            'key'     => 'project_reopened',
            'name'    => 'Proyecto: reabierto',
            'subject' => 'Proyecto reabierto — proyecto #|project_id| — |project_name| · |client_name|',
            'title'   => 'Proyecto reabierto',
            'header_content' => '
<p>Hola equipo,</p>
<p><strong>|reopened_by|</strong> reabrió el proyecto <strong>|project_name|</strong> para <strong>|client_name|</strong>.</p>
<p>Estado anterior: <strong>|estado_anterior|</strong>. El proyecto vuelve a quedar en proceso y las áreas pueden entregar de nuevo.</p>
<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>',
            'footer_content' => '<p style="font-size:12px;color:#9ca3af;">Correo automático de la plataforma Finearom.</p>',
            'signature'           => null,
            'available_variables' => json_encode([
                'project_id'      => 'ID del proyecto',
                'project_name'    => 'Nombre del proyecto',
                'client_name'     => 'Cliente, prospecto o nombre del prospecto',
                'reopened_by'     => 'Quien reabrió el proyecto',
                'estado_anterior' => 'Estado que tenía antes de reabrir (p. ej. Ganado · Entregado)',
                'project_url'     => 'Link al proyecto en la plataforma',
            ]),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'project_reopened')->delete();
    }
};
