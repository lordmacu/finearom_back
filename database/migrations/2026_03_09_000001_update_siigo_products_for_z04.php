<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siigo_products', function (Blueprint $table) {
            $table->string('nombre_corto', 120)->nullable()->after('nombre');
            $table->string('referencia', 60)->nullable()->after('grupo');
            $table->string('empresa', 10)->nullable()->after('referencia');
        });
    }

    public function down(): void
    {
        Schema::table('siigo_products', function (Blueprint $table) {
            $table->dropColumn(['nombre_corto', 'referencia', 'empresa']);
        });
    }
};
