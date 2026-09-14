<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas que escribe quien entrega el área de Evaluaciones (modal de
     * entrega): viajan en el correo del hilo y quedan visibles en la vista
     * del proyecto. Los adjuntos van en project_files con categoria
     * 'evaluaciones'.
     */
    public function up(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->text('notas_entrega')->nullable()->after('observacion');
        });
    }

    public function down(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->dropColumn('notas_entrega');
        });
    }
};
