<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Template del correo que abre el hilo de un proyecto. Solo se inserta si no
     * existe: después se administra desde /settings/email-templates y un deploy
     * no debe pisar esas ediciones.
     */
    public function up(): void
    {
        $exists = DB::table('email_templates')->where('key', 'project_created')->exists();
        if ($exists) return;

        DB::table('email_templates')->insert([
            'key'  => 'project_created',
            'name' => 'Proyecto: creación (abre el hilo interno)',

            'subject' => 'Nuevo proyecto #|project_id| — |project_name| · |client_name|',

            'title' => 'Nuevo proyecto',

            'header_content' => '
<p>Hola equipo,</p>
<p><strong>|created_by|</strong> creó el proyecto <strong>|project_name|</strong> para <strong>|client_name|</strong>.
Este correo abre el hilo del proyecto: las próximas novedades llegarán como respuesta a este mensaje.</p>

|project_table|

<p style="margin:24px 0;">
  <a href="|project_url|" style="display:inline-block;padding:10px 20px;background-color:#1F2345;color:#ffffff !important;text-decoration:none;border-radius:6px;font-weight:bold;">Ver proyecto</a>
</p>
',

            'footer_content' => '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("Proyecto: creación").
</p>',

            'signature'           => null,
            'available_variables' => json_encode([
                'project_id'      => 'ID del proyecto',
                'project_name'    => 'Nombre del proyecto',
                'client_name'     => 'Cliente, prospecto o nombre del prospecto',
                'project_type'    => 'Colección / Desarrollo / Fine Fragances',
                'product_type'    => 'Tipo de producto',
                'executive'       => 'Ejecutivo del proyecto',
                'created_by'      => 'Usuario que realizó la acción',
                'required_date'   => 'Fecha requerida (dd/mm/aaaa)',
                'calculated_date' => 'Fecha calculada (dd/mm/aaaa)',
                'volume'          => 'Volumen',
                'range'           => 'Rango mínimo – máximo',
                'project_url'     => 'Link al proyecto en la plataforma',
                'project_table'   => 'Tabla HTML con la ficha resumen del proyecto',
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
        DB::table('email_templates')->where('key', 'project_created')->delete();
    }
};
