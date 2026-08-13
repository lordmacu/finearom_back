<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `partials` es una tabla base sin migración `create_*` en este proyecto
     * (importada del legacy, ver DATABASE.md); `tracking_number` nunca tuvo
     * índice propio. El webhook de Coordinadora (CoordinadoraInboundService)
     * valida cada guía entrante contra esta columna en un endpoint público,
     * sin autenticación propia del proveedor, con hasta 60 peticiones/min:
     * sin índice, cada push dispara un recorrido completo de la tabla.
     */
    public function up(): void
    {
        Schema::table('partials', function (Blueprint $table) {
            $table->index('tracking_number', 'idx_partials_tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('partials', function (Blueprint $table) {
            $table->dropIndex('idx_partials_tracking_number');
        });
    }
};
