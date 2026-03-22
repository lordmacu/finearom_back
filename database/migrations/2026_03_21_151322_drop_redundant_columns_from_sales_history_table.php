<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->dropColumn([
                'cliente',
                'ejecutivo',
                'cliente_tipo',
                'categoria',
                'referencia',
                'estado',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->string('cliente', 200)->after('nit');
            $table->string('ejecutivo', 200)->nullable()->after('cliente');
            $table->string('cliente_tipo', 20)->nullable()->after('ejecutivo');
            $table->string('categoria', 100)->nullable()->after('cliente_tipo');
            $table->string('referencia', 300)->after('codigo');
            $table->string('estado', 50)->nullable()->after('newwin');
        });
    }
};
