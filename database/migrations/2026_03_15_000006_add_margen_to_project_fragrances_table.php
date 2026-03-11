<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_fragrances', function (Blueprint $table) {
            $table->decimal('margen', 10, 4)->nullable()->after('gramos');
            $table->decimal('precio_calculado', 10, 2)->nullable()->after('margen');
            $table->string('notas', 500)->nullable()->after('precio_calculado');
        });
    }

    public function down(): void
    {
        Schema::table('project_fragrances', function (Blueprint $table) {
            $table->dropColumn(['margen', 'precio_calculado', 'notas']);
        });
    }
};
