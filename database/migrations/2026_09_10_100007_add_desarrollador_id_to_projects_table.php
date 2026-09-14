<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ingeniero de desarrollo asignado al proyecto (lo elige la comercial en la
     * creación o editando el detalle; solo usuarios con rol Desarrollo).
     * Sin FK constraint, mismo patrón que `ejecutivo_id` de esta tabla.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('desarrollador_id')->nullable()->after('ejecutivo_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('desarrollador_id');
        });
    }
};
