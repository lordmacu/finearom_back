<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Template del correo de ACTUALIZACIÓN del flujo de desarrollo: el
     * ingeniero vuelve a oprimir "Entregado" tras agregar/editar referencias.
     * Dice qué cambió (|changes_table|) y trae la tabla completa de variantes
     * y referencias (|variants_table|). Solo se inserta si no existe: después
     * se administra desde /settings/email-templates.
     */
    public function up(): void
    {
        $exists = DB::table('email_templates')->where('key', 'project_development_updated')->exists();
        if ($exists) return;

        DB::table('email_templates')->insert([
            'key'  => 'project_development_updated',
            'name' => 'Proyecto: actualización de desarrollo',

            'subject' => 'Desarrollo actualizado — proyecto #|project_id| — |project_name| · |client_name|',

            'title' => 'Desarrollo actualizado',

            'header_content' => '
<p>Hola equipo,</p>
<p><strong>|engineer_name|</strong> actualizó el flujo de desarrollo del proyecto
<strong>|project_name|</strong> para <strong>|client_name|</strong>.
Esto fue lo que cambió:</p>

|changes_table|

<p>Todas las variantes y referencias del proyecto:</p>

|variants_table|

<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>
',

            'footer_content' => '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("Proyecto: entrega desarrollo"; si está vacía, se usa la de "Proyecto: creación").
</p>',

            'signature'           => null,
            'available_variables' => json_encode([
                'project_id'     => 'ID del proyecto',
                'project_name'   => 'Nombre del proyecto',
                'client_name'    => 'Cliente, prospecto o nombre del prospecto',
                'engineer_name'  => 'Ingeniero de desarrollo que actualizó',
                'changes_table'  => 'Tabla HTML con lo que cambió en variantes/referencias',
                'variants_table' => 'Tabla HTML completa de variantes con sus referencias',
                'project_url'    => 'Link al proyecto en la plataforma',
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
        DB::table('email_templates')->where('key', 'project_development_updated')->delete();
    }
};
