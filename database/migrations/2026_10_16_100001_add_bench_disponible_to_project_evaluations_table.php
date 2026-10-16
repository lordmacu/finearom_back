<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "¿El bench se encuentra disponible?" en la sección de Evaluaciones. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->boolean('bench_disponible')->default(false)->after('bench_text');
        });
    }

    public function down(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->dropColumn('bench_disponible');
        });
    }
};
