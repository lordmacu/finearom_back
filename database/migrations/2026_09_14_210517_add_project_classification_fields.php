<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('tipo_homologacion', 20)->nullable()->after('homologacion');
            $table->string('tipo_desarrollo', 30)->nullable()->after('tipo_homologacion');
            $table->string('area_aplicacion', 30)->nullable()->after('tipo_desarrollo');
            $table->string('area_evaluaciones', 30)->nullable()->after('area_aplicacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['tipo_homologacion', 'tipo_desarrollo', 'area_aplicacion', 'area_evaluaciones']);
        });
    }
};
