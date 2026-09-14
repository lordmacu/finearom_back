<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas de la entrega del área Marketing (modal de entrega con editor
     * enriquecido): viajan en el correo del hilo y quedan visibles en la
     * vista del proyecto. Los adjuntos van en project_files con categoria
     * 'marketing'.
     */
    public function up(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->text('notas_entrega')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->dropColumn('notas_entrega');
        });
    }
};
