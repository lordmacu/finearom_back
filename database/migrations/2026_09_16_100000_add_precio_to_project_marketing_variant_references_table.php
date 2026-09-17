<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precio que Desarrollo asigna a cada referencia de una variante.
     */
    public function up(): void
    {
        Schema::table('project_marketing_variant_references', function (Blueprint $table) {
            $table->decimal('precio', 12, 2)->nullable()->after('dosis');
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing_variant_references', function (Blueprint $table) {
            $table->dropColumn('precio');
        });
    }
};
