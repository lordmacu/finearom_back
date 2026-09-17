<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Una sola lista de destinatarios para proyectos: todas las listas
     * `project_*` (creación, modificación, desarrollo y entregas por área)
     * pasan a `proyectos`, sin repetir correos. El pie de los templates de
     * proyecto deja de mencionar las listas viejas.
     */
    public function up(): void
    {
        $vistos = [];
        DB::table('processes')
            ->orderBy('id')
            ->get()
            ->filter(fn ($p) => str_starts_with((string) $p->process_type, 'project_'))
            ->each(function ($p) use (&$vistos) {
                $email = strtolower(trim((string) $p->email));
                if (isset($vistos[$email])) {
                    DB::table('processes')->where('id', $p->id)->delete();
                    return;
                }
                $vistos[$email] = true;
                DB::table('processes')->where('id', $p->id)->update(['process_type' => 'proyectos']);
            });

        $pie = '<p style="font-size:12px;color:#9ca3af;">
  Correo automático de la plataforma Finearom. La lista de destinatarios se administra en Configuración → Procesos ("Proyectos").
</p>';

        $ids = DB::table('email_templates')
            ->where('footer_content', 'like', '%Configuración → Procesos%')
            ->get(['id', 'key'])
            ->filter(fn ($t) => str_starts_with($t->key, 'project_'))
            ->pluck('id');

        DB::table('email_templates')->whereIn('id', $ids)->update(['footer_content' => $pie, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Sin vuelta atrás exacta: las listas por acción se fusionaron.
        DB::table('processes')->where('process_type', 'proyectos')->update(['process_type' => 'project_created']);
    }
};
