<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "¿Requiere aplicaciones?": la sección de Aplicaciones del proyecto solo se despliega si está marcada.
 * Los proyectos nuevos arrancan en "no"; los que ya existían se marcan en "sí" para que no se les
 * esconda información que ya tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('requiere_aplicaciones')->default(false)->after('requiere_piramides');
        });

        DB::table('projects')->update(['requiere_aplicaciones' => true]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('requiere_aplicaciones');
        });
    }
};
