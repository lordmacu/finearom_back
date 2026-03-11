<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            // Marketing enriquecido
            $table->string('marca')->nullable()->after('obs_marketing');
            $table->string('tipo_aplicacion')->nullable()->after('marca');
            $table->string('packaging')->nullable()->after('tipo_aplicacion');
            $table->text('claims')->nullable()->after('packaging');
            $table->date('fecha_entrega_marketing')->nullable()->after('claims');

            // Certificados de calidad
            $table->boolean('cert_alergenos')->default(false)->after('obs_calidad');
            $table->boolean('cert_biodegradabilidad')->default(false)->after('cert_alergenos');
            $table->boolean('cert_animal_testing')->default(false)->after('cert_biodegradabilidad');
            $table->boolean('cert_coa')->default(false)->after('cert_animal_testing');
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->dropColumn([
                'marca', 'tipo_aplicacion', 'packaging', 'claims', 'fecha_entrega_marketing',
                'cert_alergenos', 'cert_biodegradabilidad', 'cert_animal_testing', 'cert_coa',
            ]);
        });
    }
};
