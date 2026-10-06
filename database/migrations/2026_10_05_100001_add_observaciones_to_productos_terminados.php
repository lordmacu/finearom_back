<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notas del producto terminado (p. ej. ingredientes que no se encontraron al importarlo). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos_terminados', function (Blueprint $table) {
            $table->text('observaciones')->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('productos_terminados', function (Blueprint $table) {
            $table->dropColumn('observaciones');
        });
    }
};
