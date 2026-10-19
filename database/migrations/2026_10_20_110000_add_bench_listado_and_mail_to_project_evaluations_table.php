<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluaciones:
 * - bench_listado: valor del desplegable "Listado de Bench" (se muestra cuando el bench está disponible).
 *   Las opciones del desplegable están por definir; la columna ya queda lista.
 * - bench_correo_enviado_at: marca de que ya se envió el correo de compra del bench, para no repetirlo
 *   cada vez que se guarda la evaluación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->string('bench_listado', 150)->nullable()->after('bench_disponible');
            $table->timestamp('bench_correo_enviado_at')->nullable()->after('bench_listado');
        });
    }

    public function down(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->dropColumn(['bench_listado', 'bench_correo_enviado_at']);
        });
    }
};
