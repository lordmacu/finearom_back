<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resumen por áreas del proyecto tal como se comunicó en el último correo
     * del hilo. El correo de modificación (botón "Enviar modificación") se arma
     * comparando el estado actual contra este snapshot.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('email_snapshot')->nullable()->after('email_thread_subject');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('email_snapshot');
        });
    }
};
