<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Template del recordatorio diario "falta asignar ingeniero de desarrollo"
     * (cron projects:engineer-reminders, va a la ejecutiva del proyecto).
     * Solo se inserta si no existe: después se administra desde /settings/email-templates.
     */
    public function up(): void
    {
        $exists = DB::table('email_templates')->where('key', 'project_engineer_reminder')->exists();
        if ($exists) return;

        DB::table('email_templates')->insert([
            'key'  => 'project_engineer_reminder',
            'name' => 'Proyecto: recordatorio asignar ingeniero de desarrollo',

            'subject' => 'Falta asignar ingeniero de desarrollo — proyecto #|project_id| — |project_name|',

            'title' => 'Asignación pendiente',

            'header_content' => '
<p>Hola,</p>
<p>El proyecto <strong>|project_name|</strong> para <strong>|client_name|</strong> fue creado el
<strong>|created_date|</strong> y aún no tiene ingeniero de desarrollo asignado.
Por favor asígnalo desde el detalle del proyecto.</p>

<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>
',

            'footer_content' => '<p style="font-size:12px;color:#9ca3af;">
  Recordatorio automático diario de la plataforma Finearom (se repite hasta que se asigne el ingeniero).
</p>',

            'signature'           => null,
            'available_variables' => json_encode([
                'project_id'   => 'ID del proyecto',
                'project_name' => 'Nombre del proyecto',
                'client_name'  => 'Cliente, prospecto o nombre del prospecto',
                'created_date' => 'Fecha de creación del proyecto (dd/mm/aaaa)',
                'project_url'  => 'Link al proyecto en la plataforma',
            ]),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Borra el template aunque up() no lo haya insertado (fila preexistente): un
        // rollback manual pierde las ediciones hechas por UI. Aceptado: deploy.sh nunca hace rollback.
        DB::table('email_templates')->where('key', 'project_engineer_reminder')->delete();
    }
};
