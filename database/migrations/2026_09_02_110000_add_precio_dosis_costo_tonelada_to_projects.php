<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('precio', 12, 2)->nullable()->after('volumen');
            $table->decimal('dosis', 8, 2)->nullable()->after('precio');
            $table->decimal('costo_perfumacion_tonelada', 12, 2)->nullable()->after('costo_perfumacion_especifico');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'costo_perfumacion_tonelada',
                'dosis',
                'precio',
            ]);
        });
    }
};