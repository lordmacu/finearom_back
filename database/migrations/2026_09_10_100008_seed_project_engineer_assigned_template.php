<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Template del correo que avisa la asignación del ingeniero de desarrollo
     * (va al ingeniero, CC a la ejecutiva). Solo se inserta si no existe:
     * después se administra desde /settings/email-templates.
     */
    public function up(): void
    {
        $exists = DB::table('email_templates')->where('key', 'project_engineer_assigned')->exists();
        if ($exists) return;

        DB::table('email_templates')->insert([
            'key'  => 'project_engineer_assigned',
            'name' => 'Proyecto: asignación de ingeniero de desarrollo',

            'subject' => 'Se asignó ingeniero de desarrollo — proyecto #|project_id| — |project_name|',

            'title' => 'Ingeniero asignado',

            'header_content' => '
<p>Hola equipo,</p>
<p><strong>|created_by|</strong> asignó a <strong>|engineer_name|</strong> como ingeniero de desarrollo
del proyecto <strong>|project_name|</strong> para <strong>|client_name|</strong>.</p>

<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>
',

            'footer_content' => '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom.
</p>',

            'signature'           => null,
            'available_variables' => json_encode([
                'project_id'    => 'ID del proyecto',
                'project_name'  => 'Nombre del proyecto',
                'client_name'   => 'Cliente, prospecto o nombre del prospecto',
                'engineer_name' => 'Ingeniero de desarrollo asignado',
                'created_by'    => 'Usuario que realizó la asignación',
                'project_url'   => 'Link al proyecto en la plataforma',
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
        DB::table('email_templates')->where('key', 'project_engineer_assigned')->delete();
    }
};
