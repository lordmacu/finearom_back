<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El título del correo de creación muestra "Nuevo proyecto - Cliente".
     * Solo se toca si aún tiene el valor original (no pisa ediciones hechas por UI).
     */
    public function up(): void
    {
        DB::table('email_templates')
            ->where('key', 'project_created')
            ->where('title', 'Nuevo proyecto')
            ->update(['title' => 'Nuevo proyecto - |client_name|']);
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('key', 'project_created')
            ->where('title', 'Nuevo proyecto - |client_name|')
            ->update(['title' => 'Nuevo proyecto']);
    }
};
