<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El estado externo solo puede ser Cancelado, Ganado o Perdido: "En espera"
 * pasa a llamarse "Cancelado" (también en los proyectos existentes y como
 * estado inicial). Decidido por el cliente el 2026-09-24.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY estado_externo ENUM('En espera','Cancelado','Ganado','Perdido') NOT NULL DEFAULT 'En espera'");
        DB::table('projects')->where('estado_externo', 'En espera')->update(['estado_externo' => 'Cancelado']);
        DB::statement("ALTER TABLE projects MODIFY estado_externo ENUM('Cancelado','Ganado','Perdido') NOT NULL DEFAULT 'Cancelado'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE projects MODIFY estado_externo ENUM('En espera','Cancelado','Ganado','Perdido') NOT NULL DEFAULT 'Cancelado'");
        DB::table('projects')->where('estado_externo', 'Cancelado')->update(['estado_externo' => 'En espera']);
        DB::statement("ALTER TABLE projects MODIFY estado_externo ENUM('En espera','Ganado','Perdido') NOT NULL DEFAULT 'En espera'");
    }
};
