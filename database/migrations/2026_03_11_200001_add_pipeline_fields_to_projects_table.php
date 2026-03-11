<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('fecha_cierre_estimada')->nullable()->after('fecha_entrega');
            $table->decimal('potencial_anual_usd', 12, 2)->nullable()->after('fecha_cierre_estimada');
            $table->decimal('potencial_anual_kg', 12, 2)->nullable()->after('potencial_anual_usd');
            $table->enum('probabilidad_cierre', ['alto', 'medio', 'bajo'])->nullable()->after('potencial_anual_kg');
            $table->integer('frecuencia_compra_estimada')->nullable()->after('probabilidad_cierre');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'frecuencia_compra_estimada',
                'probabilidad_cierre',
                'potencial_anual_kg',
                'potencial_anual_usd',
                'fecha_cierre_estimada',
            ]);
        });
    }
};
