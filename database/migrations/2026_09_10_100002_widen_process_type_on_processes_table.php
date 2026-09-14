<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `processes.process_type` nació como ENUM con los 3 tipos de correos de OC.
     * Los tipos `project_{accion}` (hoy `project_created`, ver Process::TYPES) no
     * caben en ese ENUM: MySQL los rechaza o los trunca. Se amplía a VARCHAR(100),
     * que es lo que DATABASE.md ya documentaba. Tabla pequeña (~50 filas), ALTER seguro.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE processes MODIFY process_type VARCHAR(100) NOT NULL");
    }

    public function down(): void
    {
        // Vuelve al ENUM original. Falla si ya existen filas con tipos nuevos
        // (p. ej. project_created): borrarlas antes de un rollback manual.
        DB::statement("ALTER TABLE processes MODIFY process_type ENUM('orden_de_compra','confirmacion_despacho','pedido') NOT NULL");
    }
};
