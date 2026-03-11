<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Factor predeterminado por cliente
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('default_factor', 10, 4)->nullable()->after('lead_time');
        });

        // Nuevos campos en proyectos
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('product_category_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_categories')
                ->nullOnDelete();

            $table->decimal('costo_perfumacion_especifico', 10, 2)
                ->nullable()
                ->after('factor');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['product_category_id']);
            $table->dropColumn(['product_category_id', 'costo_perfumacion_especifico']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('default_factor');
        });
    }
};
