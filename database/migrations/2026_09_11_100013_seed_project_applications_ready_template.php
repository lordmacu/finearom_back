<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Template del correo de "aplicaciones listas" (botón Entregar del área
     * Aplicaciones / dept laboratorio). Solo se inserta si no existe: después
     * se administra desde /settings/email-templates.
     */
    public function up(): void
    {
        $exists = DB::table('email_templates')->where('key', 'project_applications_ready')->exists();
        if ($exists) return;

        DB::table('email_templates')->insert([
            'key'  => 'project_applications_ready',
            'name' => 'Proyecto: aplicaciones listas',

            'subject' => 'Aplicaciones listas — proyecto #|project_id| — |project_name| · |client_name|',

            'title' => 'Aplicaciones listas',

            'header_content' => '
<p>Hola equipo,</p>
<p>Las aplicaciones solicitadas para el proyecto <strong>|project_name|</strong>
para <strong>|client_name|</strong> se encuentran listas.</p>
<p style="font-size:13px;color:#6b7280;">Marcado por |delivered_by|.</p>

<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>
',

            'footer_content' => '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("Proyecto: aplicaciones listas"; si está vacía, se usa la de "Proyecto: creación").
</p>',

            'signature'           => null,
            'available_variables' => json_encode([
                'project_id'   => 'ID del proyecto',
                'project_name' => 'Nombre del proyecto',
                'client_name'  => 'Cliente, prospecto o nombre del prospecto',
                'delivered_by' => 'Usuario que marcó las aplicaciones como listas',
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
        DB::table('email_templates')->where('key', 'project_applications_ready')->delete();
    }
};
