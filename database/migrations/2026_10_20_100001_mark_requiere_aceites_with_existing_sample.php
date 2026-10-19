<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tarjeta "Muestra aceite" pasa a mostrarse solo cuando el proyecto marca "¿Requiere aceites?".
 * Para que no se esconda lo ya cargado, se marca en "sí" a los proyectos que ya tienen datos de
 * muestra (cantidad, copias u observaciones). El resto queda en "no".
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('project_samples')
            ->where(function ($q) {
                $q->where('cantidad', '>', 0)
                  ->orWhere('cantidad_copias', '>', 0)
                  ->orWhere(fn ($o) => $o->whereNotNull('observaciones')->where('observaciones', '!=', ''));
            })
            ->pluck('project_id');

        foreach ($ids->chunk(500) as $lote) {
            DB::table('projects')->whereIn('id', $lote)->update(['requiere_aceites' => true]);
        }
    }

    public function down(): void
    {
        // No se revierte: no hay forma de distinguir lo marcado a mano de lo marcado por esta migración
    }
};
