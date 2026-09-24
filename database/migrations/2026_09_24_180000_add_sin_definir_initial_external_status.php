<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El proyecto nace "Sin definir" hasta que se marque explícitamente Ganado,
 * Perdido o Cancelado. Todos los "Cancelado" existentes venían del renombre de
 * "En espera" (estado inicial), así que pasan a "Sin definir". Decidido por el
 * cliente el 2026-09-24.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY estado_externo ENUM('Sin definir','Cancelado','Ganado','Perdido') NOT NULL DEFAULT 'Sin definir'");
        DB::table('projects')->where('estado_externo', 'Cancelado')->update(['estado_externo' => 'Sin definir']);
    }

    public function down(): void
    {
        DB::table('projects')->where('estado_externo', 'Sin definir')->update(['estado_externo' => 'Cancelado']);
        DB::statement("ALTER TABLE projects MODIFY estado_externo ENUM('Cancelado','Ganado','Perdido') NOT NULL DEFAULT 'Cancelado'");
    }
};
