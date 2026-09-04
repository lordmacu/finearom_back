<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('estado_evaluaciones')->default(false)->after('estado_especiales');
            $table->date('fecha_evaluaciones')->nullable()->after('estado_evaluaciones');
            $table->string('ejecutivo_evaluaciones')->nullable()->after('fecha_evaluaciones');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['estado_evaluaciones', 'fecha_evaluaciones', 'ejecutivo_evaluaciones']);
        });
    }
};
