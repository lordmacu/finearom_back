<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->string('variante', 255)->nullable()->after('marca');
            $table->string('tipo_envase', 255)->nullable()->after('tipo_aplicacion');
            $table->text('benchmark_links')->nullable()->after('claims');
            $table->text('descripcion_detallada')->nullable()->after('benchmark_links');
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->dropColumn(['variante', 'tipo_envase', 'benchmark_links', 'descripcion_detallada']);
        });
    }
};
